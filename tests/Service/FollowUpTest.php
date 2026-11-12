<?php

namespace Atwx\Flow\Tests\Service;

use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\FollowUpController;
use Atwx\Flow\Controller\CommentSubController;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\Comment;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Service\AssignmentService;
use Atwx\Flow\Service\FollowUpService;
use Atwx\Flow\Service\TransitionService;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class FollowUpTest extends SapphireTest
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
        $this->olga = Member::create(['Email' => 'olga@example.com', 'FirstName' => 'Olga']);
        $this->olga->write();
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        $this->olga->Groups()->add($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        Permission::grant($group->ID, FollowUpController::singleton()->getPermissionCode('EDIT'));
        $this->logInAs($this->olga);
    }

    private function course(): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        $statusID = Status::get()->filter(['Code' => 'draft', 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        $lead = Responsibility::get()->filter(['Code' => 'lead', 'Workflow.Code' => 'course'])->first();
        $this->assertNotNull($lead);
        AssignmentService::singleton()->assign($course, $lead, $this->olga, $this->olga);
        return $course;
    }

    private function statusID(string $code): int
    {
        return (int) Status::get()->filter(['Code' => $code, 'Workflow.Code' => 'course'])->first()?->ID;
    }

    public function testEnteringAStatusCreatesTheFollowUpForTheResponsibleMember(): void
    {
        $course = $this->course();
        $actor = MemberActor::current();
        $this->assertNotNull($actor);
        TransitionService::singleton()->transition($course, 'with_speaker', $actor);

        $followUp = Comment::get()->filter(['RecordClass' => TestCourse::class, 'RecordID' => $course->ID])->first();
        $this->assertNotNull($followUp);
        $this->assertSame('Referent:in nachfassen', $followUp->Text);
        $this->assertSame(date('Y-m-d', (int) strtotime('+7 days')), $followUp->DueDate);
        $this->assertSame((int) $this->olga->ID, (int) $followUp->MemberID);

        FollowUpService::singleton()->onStatusChange(
            $course,
            $this->statusID('with_speaker'),
            $this->statusID('review'),
            null
        );
        $closed = Comment::get()->byID($followUp->ID);
        $this->assertNotNull($closed);
        $this->assertTrue((bool) $closed->IsDone, 'closed when the status is left');
        $this->assertNotNull($closed->DoneAt);

        $titles = TestCourseController::create()->timeline($course)?->column('Title') ?? [];
        $this->assertContains('Erledigt: Referent:in nachfassen', $titles);
    }

    public function testManualFollowUpGetsTheCaseClass(): void
    {
        $course = $this->course();
        $parent = TestCourseController::create();
        $request = new HTTPRequest('POST', 'test-courses/comments/' . $course->ID . '/save');
        $request->setSession(new Session([]));
        $parent->setRequest($request);
        $sub = CommentSubController::create()->setParentContext($parent, (int) $course->ID);
        $sub->setRequest($request);

        $form = $sub->EditForm();
        $data = ['Kind' => 'followup', 'DueDate' => '2030-01-15', 'Text' => 'Anrufen', 'MemberID' => $this->olga->ID];
        $form->loadDataFrom($data);
        $sub->save($data, $form);

        $followUp = Comment::get()->find('Text', 'Anrufen');
        $this->assertNotNull($followUp);
        $this->assertSame(TestCourse::class, $followUp->RecordClass);
        $this->assertSame((int) $course->ID, (int) $followUp->RecordID);
        $this->assertSame((int) $this->olga->ID, (int) $followUp->CreatedByID);
    }

    public function testOverviewListsOpenFollowUpsWithLinkToTheCaseFile(): void
    {
        $course = $this->course();
        $due = Comment::create([
            'Kind' => Comment::FOLLOW_UP, 'RecordClass' => TestCourse::class, 'RecordID' => $course->ID,
            'Text' => 'fällig', 'DueDate' => date('Y-m-d'), 'MemberID' => $this->olga->ID,
        ]);
        $due->write();
        Comment::create([
            'Kind' => Comment::FOLLOW_UP, 'RecordClass' => TestCourse::class, 'RecordID' => $course->ID,
            'Text' => 'später', 'DueDate' => '2099-01-01', 'MemberID' => $this->olga->ID,
        ])->write();
        Comment::create([
            'Kind' => Comment::FOLLOW_UP, 'RecordClass' => TestCourse::class, 'RecordID' => $course->ID,
            'Text' => 'erledigt', 'DueDate' => '2020-01-01', 'IsDone' => true,
        ])->write();

        $this->assertSame(['fällig', 'später'], $this->overview([])->Items()->column('Text'));
        $this->assertSame(['fällig'], $this->overview(['view' => 'mine-due'])->Items()->column('Text'));

        $controller = $this->overview([]);
        $html = '';
        foreach ($controller->Columns() as $column) {
            if ($column->getName() === 'RecordTitle') {
                $html = (string) $column->renderValue($due);
            }
        }
        $this->assertStringContainsString('test-courses/view/' . $course->ID, $html);
    }

    public function testFollowUpsGoWithTheCaseFile(): void
    {
        $course = $this->course();
        Comment::create(['RecordClass' => TestCourse::class, 'RecordID' => $course->ID, 'Text' => 'x'])->write();
        $course->delete();
        $this->assertSame(0, Comment::get()->count());
    }

    /**
     * @param array<string, string> $vars
     */
    private function overview(array $vars): FollowUpController
    {
        $controller = FollowUpController::create();
        $request = new HTTPRequest('GET', 'followups', $vars);
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        return $controller;
    }
}
