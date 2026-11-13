<?php

namespace Atwx\Flow\Tests\Controller;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\AccessLinkSubController;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Service\AssignmentService;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Service\TransitionException;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use Atwx\SilverstripeFrontdeskKit\Forms\FieldLayout;
use Atwx\SilverstripeFrontdeskKit\Table\ActionException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;

class FlowCaseControllerTest extends SapphireTest
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
        // UI texts are checked in German (lang/de.yml).
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
    }

    private function logInTo(string $groupCode): void
    {
        $member = Member::create(['Email' => $groupCode . '@example.com', 'FirstName' => ucfirst($groupCode)]);
        $member->write();
        $group = Group::get()->find('Code', $groupCode);
        $this->assertNotNull($group);
        $member->Groups()->add($group);
        $this->logInAs($member);
    }

    private function courseIn(string $status): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Kurs', 'Venue' => 'Marburg', 'InternalNote' => 'geheim']);
        $statusID = Status::get()->filter(['Code' => $status, 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    public function testLayoutUsesGroupsAndLevels(): void
    {
        $this->logInTo('readers');
        $layout = TestCourseController::create()->layout($this->courseIn('draft'));

        $this->assertInstanceOf(FieldLayout::class, $layout);
        $this->assertSame(['Eckdaten', 'Ausschreibung', 'Organisation'], array_column($layout->getGroups(), 'title'));
        $this->assertSame('read', $layout->levelFor('Title'));
        $this->assertSame('read', $layout->levelFor('InternalNote'));
        $this->assertSame('hidden', $layout->levelFor('NotInAnyGroup'), 'default is hidden');
    }

    public function testLayoutGroupsAreKeyedByCode(): void
    {
        $this->logInTo('readers');
        $layout = TestCourseController::create()->layout($this->courseIn('draft'));
        $this->assertNotNull($layout);
        $this->assertSame(['basics', 'announcement', 'organisation'], array_column($layout->getGroups(), 'key'));
    }

    public function testHeaderShowsStatusAndPhase(): void
    {
        $this->logInTo('readers');
        $fields = TestCourseController::create()->headerFields($this->courseIn('draft'));
        $this->assertNotNull($fields);
        $this->assertSame(['Status', 'Phase'], $fields->column('Label'));
        $this->assertSame('Entwurf', $fields->first()->Value);
    }

    public function testEditLinksAreOnlyListedForEditors(): void
    {
        $course = $this->courseIn('draft');
        $this->logInTo('readers');
        $this->assertFalse(TestCourseController::create()->showsSubController(AccessLinkSubController::class, $course));
        $organisers = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($organisers);
        Permission::grant($organisers->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        $this->logInTo('organisers');
        $this->assertTrue(TestCourseController::create()->showsSubController(AccessLinkSubController::class, $course));
    }

    public function testLayoutForNewRecordUsesInitialStatus(): void
    {
        $workflow = Workflow::get()->find('Code', 'course');
        $this->assertNotNull($workflow);
        $workflow->InitialStatusID = (int) Status::get()
            ->filter(['Code' => 'done', 'WorkflowID' => $workflow->ID])
            ->first()?->ID;
        $workflow->write();
        $this->logInTo('organisers');

        $layout = TestCourseController::create()->layout(null);
        $this->assertNotNull($layout);
        $this->assertSame('read', $layout->levelFor('Description'), 'announcement is read-only in "done"');
        $this->assertSame('write', $layout->levelFor('Title'));
    }

    public function testWithoutLoginEverythingIsHidden(): void
    {
        $layout = TestCourseController::create()->layout($this->courseIn('draft'));
        $this->assertNotNull($layout);
        $this->assertSame('hidden', $layout->levelFor('Title'));
        $this->assertSame([], TestCourseController::create()->recordActions($this->courseIn('draft')));
    }

    public function testStatusActionOffersAllowedTransitions(): void
    {
        $this->logInTo('organisers');
        $course = $this->courseIn('draft');
        $actions = TestCourseController::create()->recordActions($course);

        $this->assertCount(2, $actions, 'status change and note');
        $this->assertSame('flowstatus', $actions[0]->getName());
        $this->assertSame(['with_speaker' => 'An Referent:in senden'], $actions[0]->getOptions());

        $message = $actions[0]->run($course, 'with_speaker', 'los');
        $this->assertIsString($message);
        $this->assertStringContainsString('Bei Referent:in', $message);
        $stored = TestCourse::get()->byID($course->ID);
        $this->assertSame('with_speaker', Status::get()->byID((int) $stored?->FlowStatusID)?->Code);
    }

    public function testNoStatusActionWithoutTransitions(): void
    {
        $this->logInTo('readers');
        $this->assertSame([], TestCourseController::create()->recordActions($this->courseIn('draft')));
    }

    public function testRefusedTransitionBecomesActionError(): void
    {
        $this->assertInstanceOf(ActionException::class, new TransitionException('x'));

        $this->logInTo('organisers');
        $course = $this->courseIn('draft');
        $action = TestCourseController::create()->recordActions($course)[0];

        // Someone else changes the status in the meantime.
        $copy = TestCourse::get()->byID($course->ID);
        $this->assertNotNull($copy);
        $copy->transitionTo('with_speaker');

        $this->expectException(ActionException::class);
        $action->run($course, 'with_speaker', '');
    }

    public function testTimelineShowsStatusHistory(): void
    {
        $this->logInTo('organisers');
        $course = TestCourse::create(['Title' => 'Neu']);
        $course->write();
        $course->transitionTo('with_speaker', null, 'Bitte ausfüllen');

        $timeline = TestCourseController::create()->timeline($course);
        $this->assertNotNull($timeline);
        $titles = $timeline->column('Title');
        $this->assertContains('Angelegt, Status: Entwurf', $titles);
        $this->assertContains('Status: Entwurf → Bei Referent:in', $titles);
        $this->assertContains('Bitte ausfüllen', $timeline->column('Text'));
        $this->assertContains('Organisers', $timeline->column('Author'));
    }

    public function testColumnsHideFieldsTheMemberMayNotRead(): void
    {
        $this->logInTo('readers');
        $columns = TestCourseController::create()->columns();
        $course = $this->courseIn('draft');

        $values = [];
        foreach ($columns as $column) {
            $values[$column->getName()] = (string) $column->renderValue($course);
        }
        $this->assertSame('Kurs', $values['Title']);
        $this->assertSame('geheim', $values['InternalNote'], 'readers may read organisation');
        $this->assertSame('Entwurf', $values['FlowStatus.Title']);
        $this->assertSame('Planung', $values['FlowStatus.Phase.Title']);

        // An actor without the organisation group sees no internal note.
        $this->logOut();
        $member = Member::create(['Email' => 'nobody@example.com']);
        $member->write();
        $this->logInAs($member);
        $columns = TestCourseController::create()->columns();
        foreach ($columns as $column) {
            if ($column->getName() === 'InternalNote') {
                $this->assertSame('', (string) $column->renderValue($course));
            }
        }
    }

    public function testResponsibilityActionColumnFilterHeaderAndTimeline(): void
    {
        $organisers = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($organisers);
        Permission::grant($organisers->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        $this->logInTo('organisers');
        $me = Security::getCurrentUser();
        $this->assertNotNull($me);
        $course = $this->courseIn('draft');
        $other = $this->courseIn('draft');
        $controller = TestCourseController::create();

        $action = null;
        foreach ($controller->recordActions($course) as $candidate) {
            if ($candidate->getName() === 'flowassign') {
                $action = $candidate;
            }
        }
        $this->assertNotNull($action);
        $this->assertSame(['lead'], array_keys($action->getSelects()));
        $message = $action->run($course, null, '', ['lead' => (string) $me->ID]);
        $this->assertSame('Zuständigkeit geändert.', $message);

        $column = null;
        foreach ($controller->columns() as $candidate) {
            if ($candidate->getName() === 'Assignee_lead') {
                $column = $candidate;
            }
        }
        $this->assertNotNull($column);
        $this->assertSame((string) $me->getName(), (string) $column->renderValue($course));
        $this->assertSame('', (string) $column->renderValue($other));

        $this->assertContains('Leitung', $controller->headerFields($course)?->column('Label') ?? []);
        $titles = $controller->timeline($course)?->column('Title') ?? [];
        $this->assertContains('Leitung: niemand → ' . $me->getName(), $titles);

        $request = new HTTPRequest('GET', 'test-courses', ['view' => 'mine']);
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        $this->assertSame([(int) $course->ID], $controller->filteredIDs());

        $request = new HTTPRequest('GET', 'test-courses', ['Assignee_lead' => (string) $me->ID]);
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        $filtered = $controller->filteredIDs();
        $this->assertCount(1, $filtered, 'filter by assignee');
        $this->assertSame((int) $course->ID, $filtered[0]);

        // Several members: any of them.
        $colleague = Member::create(['Email' => 'colleague@example.com', 'FirstName' => 'Kai']);
        $colleague->write();
        $colleague->Groups()->add(Group::get()->find('Code', 'organisers'));
        $lead = Responsibility::get()->filter(['Code' => 'lead', 'Workflow.Code' => 'course'])->first();
        $this->assertNotNull($lead);
        AssignmentService::singleton()->assign($other, $lead, $colleague, $me);
        $filtered = $this->filtered($controller, ['Assignee_lead' => json_encode([$me->ID, $colleague->ID])]);
        $this->assertEqualsCanonicalizing([(int) $course->ID, (int) $other->ID], $filtered, 'any of the members');
    }

    public function testStatusFilterWithSeveralStatuses(): void
    {
        $this->logInTo('organisers');
        $draft = $this->courseIn('draft');
        $review = $this->courseIn('review');
        $this->courseIn('released');
        $controller = TestCourseController::create();
        $ids = fn (string ...$codes) => array_map(
            fn (string $code) => (int) Status::get()
                ->filter(['Code' => $code, 'Workflow.Code' => 'course'])
                ->first()?->ID,
            $codes
        );
        $this->assertEqualsCanonicalizing(
            [(int) $draft->ID, (int) $review->ID],
            $this->filtered($controller, ['FlowStatus' => json_encode($ids('draft', 'review'))])
        );
        $this->assertSame(
            [(int) $review->ID],
            $this->filtered($controller, ['FlowStatus' => (string) $ids('review')[0]]),
            'single value (older links and saved views)'
        );
    }

    /**
     * @param array<string, string> $vars
     * @return array<int, int>
     */
    private function filtered(TestCourseController $controller, array $vars): array
    {
        $request = new HTTPRequest('GET', 'test-courses', $vars);
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        return array_map('intval', $controller->filteredIDs());
    }

    public function testReadersGetNoResponsibilityAction(): void
    {
        $this->logInTo('readers');
        $names = array_map(
            fn ($action) => $action->getName(),
            TestCourseController::create()->recordActions($this->courseIn('draft'))
        );
        $this->assertNotContains('flowassign', $names);
    }
}
