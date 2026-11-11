<?php

namespace Atwx\Flow\Tests\Controller;

use Atwx\Flow\Actor\AccessLinkActor;
use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\AccessLink;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\LinkAccess;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Edit links: model rules and the public page /flow/link/<token>.
 */
class AccessLinkTest extends FunctionalTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
    }

    private function courseIn(string $status): TestCourse
    {
        $course = TestCourse::create([
            'Title' => 'Kurs',
            'Venue' => 'Marburg',
            'Description' => 'Alt',
            'InternalNote' => 'geheim',
        ]);
        $statusID = Status::get()->filter(['Code' => $status, 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    private function role(string $code): FlowRole
    {
        $role = FlowRole::get()->filter(['Code' => $code, 'Workflow.Code' => 'course'])->first();
        $this->assertNotNull($role);
        return $role;
    }

    /**
     * @return array{AccessLink, string}
     */
    private function linkFor(TestCourse $course, string $role = 'speaker'): array
    {
        $link = AccessLink::create(['Name' => 'Ruth', 'RoleID' => $this->role($role)->ID]);
        $link->setField('CaseClass', TestCourse::class);
        $link->setField('CaseID', $course->ID);
        $link->write();
        $token = $link->getPlainToken();
        $this->assertNotNull($token);
        return [$link, $token];
    }

    private function url(string $token, string $action = ''): string
    {
        return AccessLink::URL_SEGMENT . '/' . $token . ($action ? '/' . $action : '');
    }

    public function testTokenIsStoredAsHashOnly(): void
    {
        [$link, $token] = $this->linkFor($this->courseIn('draft'));

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token);
        $this->assertSame(hash('sha256', $token), $link->TokenHash);
        $this->assertNotSame($token, $link->TokenHash);
        $this->assertSame($link->ID, AccessLink::findByToken($token)?->ID);
        $this->assertNull(AccessLink::findByToken($link->TokenHash), 'the hash is no token');
        $this->assertNull(AccessLink::findByToken('../../etc'));
        $this->assertNull(AccessLink::get()->byID($link->ID)?->getPlainToken(), 'not kept after loading');
    }

    public function testRoleMustBeExternalOfSameWorkflow(): void
    {
        $course = $this->courseIn('draft');
        $link = AccessLink::create(['Name' => 'X', 'RoleID' => $this->role('organisers')->ID]);
        $link->setField('CaseClass', TestCourse::class);
        $link->setField('CaseID', $course->ID);
        $this->assertFalse($link->validate()->isValid(), 'internal role');

        $link->Name = '';
        $link->RoleID = $this->role('speaker')->ID;
        $this->assertFalse($link->validate()->isValid(), 'name required');

        $link->Name = 'X';
        $link->Email = 'kein-mail';
        $this->assertFalse($link->validate()->isValid(), 'invalid email');
    }

    public function testUsableUntilRevokedExpiredOrFinal(): void
    {
        $course = $this->courseIn('with_speaker');
        [$link] = $this->linkFor($course);
        $actor = new AccessLinkActor($link);
        $workflow = Workflow::get()->find('Code', 'course');
        $this->assertNotNull($workflow);

        $this->assertTrue($link->isUsable());
        $this->assertSame(['speaker'], $actor->flowRoleCodes($workflow, $course));
        $this->assertSame([], $actor->flowRoleCodes($workflow, $this->courseIn('with_speaker')), 'other case');

        DBDatetime::set_mock_now('2026-06-10 12:00:00');
        $link->ExpiresAt = '2026-06-10';
        $this->assertTrue($link->isUsable(), 'valid on the expiry day');
        $link->ExpiresAt = '2026-06-09';
        $this->assertFalse($link->isUsable());
        $this->assertSame([], $actor->flowRoleCodes($workflow, $course));
        DBDatetime::clear_mock_now();

        $link->ExpiresAt = '';
        $link->revoke();
        $this->assertFalse(AccessLink::get()->byID($link->ID)?->isUsable());

        [$lapsed] = $this->linkFor($this->courseIn('done'));
        $this->assertFalse($lapsed->isUsable(), 'final status');
    }

    public function testPageShowsFieldsOfTheRole(): void
    {
        [, $token] = $this->linkFor($this->courseIn('with_speaker'));
        $response = $this->get($this->url($token));

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Ruth', $body);
        $this->assertStringContainsString('name="Description"', $body);
        $this->assertStringNotContainsString('geheim', $body, 'organisation group is hidden');
        $this->assertStringContainsString('noindex', (string) $response->getHeader('X-Robots-Tag'));
        $this->assertSame(1, LinkAccess::get()->filter('Kind', LinkAccess::VIEW)->count());

        $this->get($this->url($token));
        $this->assertSame(1, LinkAccess::get()->filter('Kind', LinkAccess::VIEW)->count(), 'not logged twice');
    }

    public function testInvalidLinksAre404(): void
    {
        $this->assertSame(404, $this->get($this->url(str_repeat('a', 64)))->getStatusCode());

        [$revoked, $token] = $this->linkFor($this->courseIn('with_speaker'));
        $revoked->revoke();
        $this->assertSame(404, $this->get($this->url($token))->getStatusCode());

        [, $token] = $this->linkFor($this->courseIn('done'));
        $this->assertSame(404, $this->get($this->url($token))->getStatusCode());
    }

    public function testSaveOnlyWritesFieldsOfTheRole(): void
    {
        $course = $this->courseIn('with_speaker');
        [, $token] = $this->linkFor($course);
        $this->get($this->url($token));

        $this->post($this->url($token, 'RecordForm'), [
            'Description' => 'Neu',
            'Title' => 'Manipuliert',
            'InternalNote' => 'Manipuliert',
            'FlowStatusID' => 1,
            'action_save' => 'Speichern',
        ]);

        $stored = TestCourse::get()->byID($course->ID);
        $this->assertNotNull($stored);
        $this->assertSame('Neu', $stored->Description);
        $this->assertSame('Kurs', $stored->Title);
        $this->assertSame('geheim', $stored->InternalNote);
        $this->assertSame('with_speaker', Status::get()->byID((int) $stored->FlowStatusID)?->Code);
        $saved = LinkAccess::get()->filter('Kind', LinkAccess::SAVE)->first();
        $this->assertNotNull($saved);
        $this->assertStringContainsString('Description', (string) $saved->Fields);
    }

    public function testReadOnlyStatusHasNoForm(): void
    {
        [, $token] = $this->linkFor($this->courseIn('draft'));
        $body = (string) $this->get($this->url($token))->getBody();
        $this->assertStringNotContainsString('name="Description"', $body);

        $this->post($this->url($token, 'RecordForm'), ['Description' => 'Neu', 'action_save' => 'Speichern']);
        $this->assertSame('Alt', TestCourse::get()->first()?->Description);
    }

    public function testTransitionViaLinkIsRecordedWithTheLink(): void
    {
        $course = $this->courseIn('with_speaker');
        [$link, $token] = $this->linkFor($course);
        $this->get($this->url($token));

        $this->post($this->url($token, 'recordaction'), [
            'RecordAction' => 'flowstatus',
            'Option' => 'review',
            'Comment' => 'Fertig',
        ]);

        $change = StatusChange::get()->filter(['CaseID' => $course->ID, 'Comment' => 'Fertig'])->first();
        $this->assertNotNull($change);
        $this->assertSame($link->ID, (int) $change->AccessLinkID);
        $this->assertSame(0, (int) $change->MemberID);
        $this->assertSame('Ruth (Referent:in)', $change->getActorName());

        // Only the role's transitions: "done" is not reachable for the speaker.
        $this->post($this->url($token, 'recordaction'), ['RecordAction' => 'flowstatus', 'Option' => 'done']);
        $stored = TestCourse::get()->byID($course->ID);
        $this->assertSame('review', Status::get()->byID((int) $stored?->FlowStatusID)?->Code);
    }

    public function testInternalRevokeActionAndTimeline(): void
    {
        $this->logInWithPermission('ADMIN');
        $course = $this->courseIn('with_speaker');
        [$link] = $this->linkFor($course);
        $controller = TestCourseController::create();

        $revoke = null;
        foreach ($controller->recordActions($course) as $action) {
            if ($action->getName() === 'flowrevoke') {
                $revoke = $action;
            }
        }
        $this->assertNotNull($revoke);
        $this->assertSame([(string) $link->ID => 'Ruth (Referent:in)'], $revoke->getOptions());
        $revoke->run($course, (string) $link->ID, '');
        $this->assertTrue((bool) AccessLink::get()->byID($link->ID)?->isRevoked());

        $timeline = $controller->timeline($course);
        $this->assertNotNull($timeline);
        $kinds = array_count_values($timeline->column('Kind'));
        $this->assertSame(['status' => 1, 'link' => 2], $kinds, 'created; link created and revoked');
    }

    public function testDeletingCaseRemovesLinks(): void
    {
        $course = $this->courseIn('with_speaker');
        [$link] = $this->linkFor($course);
        $course->delete();
        $this->assertNull(AccessLink::get()->byID($link->ID));
    }
}
