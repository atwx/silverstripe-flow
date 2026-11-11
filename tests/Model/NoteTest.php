<?php

namespace Atwx\Flow\Tests\Model;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\Note;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Service\TransitionException;
use Atwx\Flow\Tests\Stubs\TestActor;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;

class NoteTest extends SapphireTest
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

    private function member(string $groupCode): Member
    {
        $member = Member::create(['Email' => $groupCode . '@example.com', 'FirstName' => ucfirst($groupCode)]);
        $member->write();
        $group = Group::get()->find('Code', $groupCode);
        $this->assertNotNull($group);
        $member->Groups()->add($group);
        return $member;
    }

    private function courseIn(string $status): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        $statusID = Status::get()->filter(['Code' => $status, 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    public function testMemberWithWritableFieldsAddsNote(): void
    {
        $organiser = $this->member('organisers');
        $this->logInAs($organiser);
        $course = $this->courseIn('draft');

        $this->assertTrue($course->canAddFlowNote());
        $note = $course->addFlowNote('  Raum ist gebucht. ');

        $this->assertSame('Raum ist gebucht.', $note->Text);
        $this->assertSame($organiser->ID, (int) $note->MemberID);
        $this->assertSame(1, $course->FlowNotes()->count());
    }

    public function testReadOnlyRoleMayNotAddNotes(): void
    {
        $this->logInAs($this->member('readers'));
        $course = $this->courseIn('draft');

        $this->assertFalse($course->canAddFlowNote());
        $this->expectException(TransitionException::class);
        $course->addFlowNote('Darf ich nicht');
    }

    public function testRightToAddNotesFollowsStatus(): void
    {
        $speaker = new TestActor(['speaker']);
        $this->assertFalse($this->courseIn('draft')->canAddFlowNote($speaker), 'only reads in draft');
        $this->assertTrue($this->courseIn('with_speaker')->canAddFlowNote($speaker), 'writes in with_speaker');
        $this->assertSame(0, (int) $this->courseIn('with_speaker')->addFlowNote('Von extern', $speaker)->MemberID);
    }

    public function testEmptyNoteIsRefused(): void
    {
        $this->logInAs($this->member('organisers'));
        $this->expectException(TransitionException::class);
        $this->courseIn('draft')->addFlowNote('   ');
    }

    public function testWithoutLoginNoNotes(): void
    {
        $course = $this->courseIn('draft');
        $this->assertFalse($course->canAddFlowNote());
        $this->assertFalse(TestCourse::create()->canAddFlowNote(new TestActor(['organisers'])), 'unsaved');
    }

    public function testNotesAreImmutable(): void
    {
        $this->logInAs($this->member('organisers'));
        $note = $this->courseIn('draft')->addFlowNote('Text');
        $this->assertFalse($note->canEdit());
        $this->assertFalse($note->canDelete());
        $this->assertFalse(Note::singleton()->canCreate());
    }

    public function testControllerOffersNoteActionAndTimeline(): void
    {
        $this->logInAs($this->member('organisers'));
        $course = $this->courseIn('draft');
        $controller = TestCourseController::create();

        $actions = $controller->recordActions($course);
        $this->assertSame(['flowstatus', 'flownote'], array_map(fn ($action) => $action->getName(), $actions));
        $this->assertTrue($actions[1]->isCommentRequired());

        $actions[1]->run($course, null, 'Bitte Raum klären');
        $timeline = $controller->timeline($course);
        $this->assertNotNull($timeline);
        $this->assertContains('Bitte Raum klären', $timeline->column('Text'));
        $this->assertContains('note', $timeline->column('Kind'));

        $this->logInAs($this->member('readers'));
        $this->assertSame([], TestCourseController::create()->recordActions($course));
    }

    public function testDeletingCaseRemovesHistoryAndNotes(): void
    {
        $this->logInAs($this->member('organisers'));
        $course = TestCourse::create(['Title' => 'Weg']);
        $course->write();
        $course->addFlowNote('Notiz');
        $id = $course->ID;

        $course->delete();

        $filter = ['CaseClass' => TestCourse::class, 'CaseID' => $id];
        $this->assertSame(0, Note::get()->filter($filter)->count());
        $this->assertSame(0, StatusChange::get()->filter($filter)->count());
    }
}
