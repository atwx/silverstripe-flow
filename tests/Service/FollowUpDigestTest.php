<?php

namespace Atwx\Flow\Tests\Service;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\FollowUpController;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\FollowUp;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Service\FollowUpDigest;
use Atwx\Flow\Service\MailService;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class FollowUpDigestTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
    ];

    private Member $olga;

    protected function setUp(): void
    {
        parent::setUp();
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        MailService::config()->set('from', 'flow@example.org');
        Config::modify()->merge(Director::class, 'rules', ['followups//$Action' => FollowUpController::class]);

        $this->olga = $this->member('olga@example.com', 'Olga', 'organisers');
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
    }

    private function member(string $email, string $name, ?string $group = null): Member
    {
        $member = Member::create(['Email' => $email, 'FirstName' => $name]);
        $member->write();
        if ($group) {
            $member->Groups()->add(Group::get()->find('Code', $group));
        }
        return $member;
    }

    private function followUp(string $title, string $due, Member $member, bool $done = false): FollowUp
    {
        $course = TestCourse::create(['Title' => $title]);
        $statusID = Status::get()->filter(['Code' => 'draft', 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        $followUp = FollowUp::create([
            'CaseClass' => TestCourse::class,
            'CaseID' => $course->ID,
            'Text' => 'Nachfassen',
            'DueDate' => $due,
            'MemberID' => $member->ID,
            'IsDone' => $done,
        ]);
        $followUp->write();
        return $followUp;
    }

    public function testOneMailPerMemberWithTheDueFollowUpsAndLinks(): void
    {
        $this->followUp('Paläographie', '2026-11-10', $this->olga);
        $this->followUp('Aktenkunde', '2026-11-12', $this->olga);
        $this->followUp('Später', '2026-11-13', $this->olga);
        $this->followUp('Erledigt', '2026-11-01', $this->olga, true);
        $outsider = $this->member('lena@example.com', 'Lena');
        $this->followUp('Nicht sichtbar', '2026-11-01', $outsider);

        $report = FollowUpDigest::singleton()->send('2026-11-12');

        $this->assertCount(1, $report, 'only members who may view the case file');
        $email = $this->findEmail('olga@example.com', 'flow@example.org', 'Fällige Wiedervorlagen (2)');
        $this->assertNotNull($email);
        $body = (string) $email['Content'];
        $this->assertStringContainsString('Guten Tag Olga', $body);
        $this->assertStringContainsString('10.11.2026 (überfällig): Paläographie – Nachfassen', $body);
        $this->assertStringContainsString('12.11.2026: Aktenkunde', $body);
        $this->assertStringNotContainsString('Später', $body);
        $this->assertStringNotContainsString('Erledigt', $body);
        $this->assertStringContainsString('test-courses/view/', $body);
        $this->assertStringContainsString('followups?view=mine-due', $body);
        $this->assertNull($this->findEmail('lena@example.com'));
    }

    public function testOnlyOncePerDayAndNotWhenTurnedOff(): void
    {
        $this->followUp('Paläographie', '2026-11-10', $this->olga);
        $digest = FollowUpDigest::singleton();

        $this->assertStringEndsWith('sent', $digest->send('2026-11-12')[0]);
        $this->assertStringEndsWith('(already sent today)', $digest->send('2026-11-12')[0]);
        $this->assertStringEndsWith('sent', $digest->send('2026-11-12', false, true)[0], 'forced');
        $this->assertStringEndsWith('sent', $digest->send('2026-11-13')[0], 'next day');
        $this->assertStringEndsWith('(dry run)', $digest->send('2026-11-14', true)[0]);

        $olga = Member::get()->byID($this->olga->ID);
        $this->assertNotNull($olga);
        $olga->FlowDigestOff = true;
        $olga->write();
        $this->assertStringEndsWith('(turned off)', $digest->send('2026-11-14')[0]);
    }

    public function testMembersWithoutAValidAddressAreReported(): void
    {
        $admin = $this->member('admin', 'Admin', 'organisers');
        $this->followUp('Paläographie', '2026-11-10', $admin);

        $this->assertStringEndsWith('(no valid address)', FollowUpDigest::singleton()->send('2026-11-12')[0]);
    }
}
