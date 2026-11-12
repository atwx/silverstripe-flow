<?php

namespace Atwx\Flow\Tests\Model;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\CommentSubController;
use Atwx\Flow\Controller\FollowUpController;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\Comment;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use Atwx\Flow\Tests\Stubs\TestParticipant;
use Atwx\Flow\Tests\Stubs\TestParticipantController;
use Atwx\SilverstripeFrontdeskKit\Catalog\Catalogs;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class CommentTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestParticipant::class,
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
        Permission::grant($group->ID, TestParticipantController::singleton()->getPermissionCode('EDIT'));
        Permission::grant($group->ID, FollowUpController::singleton()->getPermissionCode('EDIT'));
        $this->logInAs($this->olga);
    }

    private function course(): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        $statusID = Status::get()->filter(['Code' => 'draft', 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function comment(DataObject $record, string $text, array $fields = []): Comment
    {
        $comment = Comment::create(array_merge([
            'RecordClass' => $record->ClassName,
            'RecordID' => $record->ID,
            'Text' => $text,
        ], $fields));
        $comment->write();
        return $comment;
    }

    public function testListOrderUpcomingThenImportantThenLatest(): void
    {
        $course = $this->course();
        $old = $this->comment($course, 'alt');
        $this->comment($course, 'wichtig', ['Important' => true]);
        $this->comment($course, 'übermorgen', ['DueDate' => date('Y-m-d', strtotime('+2 days'))]);
        $this->comment($course, 'morgen', ['DueDate' => date('Y-m-d', strtotime('+1 day')), 'DueTime' => '09:30']);
        $this->comment($course, 'vorbei', ['DueDate' => '2020-01-01']);
        $this->comment($course, 'neu');
        DB::query('UPDATE "Flow_Comment" SET "LastEdited" = \'2020-01-01 00:00:00\' WHERE "ID" = ' . $old->ID);

        $texts = Comment::inListOrder(Comment::forRecord($course))->column('Text');
        $this->assertSame(['morgen', 'übermorgen', 'wichtig', 'neu', 'vorbei', 'alt'], $texts);
    }

    public function testAuthorStaysTypeDefaultsAndSummary(): void
    {
        Comment::singleton()->requireDefaultRecords();
        $course = $this->course();
        $comment = $this->comment($course, 'Schule anrufen', [
            'Kind' => Comment::FOLLOW_UP,
            'MemberID' => $this->olga->ID,
            'DueDate' => '2030-05-01',
            'DueTime' => '14:00:00',
        ]);
        $this->assertSame((int) $this->olga->ID, (int) $comment->CreatedByID);
        $type = $comment->Type();
        $this->assertTrue($type->exists(), 'default type of the catalog');
        $this->assertSame('Memo', $type->Title);
        $this->assertSame('Wiedervorlage (Olga): Schule anrufen', $comment->getSummary());
        $this->assertSame('01.05.2030 14:00', $comment->getDueLabel());

        $berta = Member::create(['Email' => 'berta@example.com', 'FirstName' => 'Berta']);
        $berta->write();
        $this->logInAs($berta);
        $comment->Text = 'Schule zurückrufen';
        $comment->write();
        $this->assertSame((int) $this->olga->ID, (int) $comment->CreatedByID);
        $this->assertSame((int) $berta->ID, (int) $comment->LastEditedByID);

        $comment->Text = str_repeat('x', 2001);
        $this->assertFalse($comment->validate()->isValid());
        $this->assertTrue(Catalogs::singleton()->exists(Comment::TYPE_CATALOG));
    }

    public function testCommentsOnARecordThatIsNoCaseFile(): void
    {
        $course = $this->course();
        $ruth = TestParticipant::create(['Name' => 'Ruth', 'Email' => 'ruth@example.org', 'CourseID' => $course->ID]);
        $ruth->write();
        $redirect = TestParticipant::create(['Name' => 'Kurs', 'CourseID' => $course->ID]);
        $redirect->write();

        $this->assertSame($ruth->ID, $this->sub($ruth)->commentRecord()?->ID);
        $this->assertInstanceOf(TestCourse::class, $this->sub($redirect)->commentRecord(), 'commentRecordFor');

        $sub = $this->sub($ruth);
        $form = $sub->EditForm();
        $data = ['Kind' => Comment::TODO, 'Text' => 'Bescheinigung schicken', 'Important' => 1];
        $form->loadDataFrom($data);
        $sub->save($data, $form);
        $todo = Comment::get()->find('Text', 'Bescheinigung schicken');
        $this->assertNotNull($todo);
        $this->assertSame(TestParticipant::class, $todo->RecordClass);
        $this->assertSame((int) $ruth->ID, (int) $todo->RecordID);
        $this->assertTrue($todo->canView());
        $this->assertSame(1, $ruth->FlowComments()->count());

        // The to-do basket: to-dos without anyone responsible; take over.
        $overview = $this->overview(['view' => 'basket']);
        $this->assertSame(['Bescheinigung schicken'], $overview->Items()->column('Text'));
        $actions = (new \ReflectionMethod($overview, 'defineBulkActions'))->invoke($overview);
        $take = array_values(array_filter($actions, fn ($action) => $action->getName() === 'take'))[0];
        $take->handle(Comment::get()->filter('ID', $todo->ID), null);
        $this->assertSame((int) $this->olga->ID, (int) Comment::get()->byID($todo->ID)?->MemberID, 'taken over');
        $this->assertSame([], $this->overview(['view' => 'basket'])->Items()->column('Text'));
        $this->assertSame(['Bescheinigung schicken'], $this->overview(['view' => 'todos'])->Items()->column('Text'));
        $this->assertSame([], $this->overview(['view' => 'mine'])->Items()->column('Text'), 'no follow-up');
        $this->assertStringEndsWith(
            'test-participants/view/' . $ruth->ID,
            (string) FollowUpController::recordLink($ruth)
        );

        $ruth->delete();
        $this->assertSame(0, Comment::get()->filter('RecordClass', TestParticipant::class)->count());
    }

    public function testFollowUpsOfEarlierVersionsAreMoved(): void
    {
        $course = $this->course();
        DB::query('CREATE TABLE "Flow_FollowUp" ("ID" INT AUTO_INCREMENT PRIMARY KEY, "Created" DATETIME,'
            . ' "LastEdited" DATETIME, "DueDate" DATE, "Text" VARCHAR(255), "IsDone" TINYINT, "DoneAt" DATETIME,'
            . ' "CaseClass" VARCHAR(255), "CaseID" INT, "MemberID" INT, "RuleID" INT, "CreatedByID" INT,'
            . ' "DoneByID" INT)');
        DB::prepared_query(
            'INSERT INTO "Flow_FollowUp" ("Created", "LastEdited", "DueDate", "Text", "IsDone", "CaseClass",'
            . ' "CaseID", "MemberID", "RuleID", "CreatedByID", "DoneByID") VALUES (?, ?, ?, ?, 0, ?, ?, ?, 0, ?, 0)',
            ['2026-01-01 10:00:00', '2026-01-01 10:00:00', '2026-02-01', 'Alt', TestCourse::class, $course->ID,
                $this->olga->ID, $this->olga->ID]
        );

        Comment::singleton()->requireDefaultRecords();

        $moved = Comment::get()->find('Text', 'Alt');
        $this->assertNotNull($moved);
        $this->assertSame(Comment::FOLLOW_UP, $moved->Kind);
        $this->assertSame((int) $course->ID, (int) $moved->RecordID);
        $this->assertSame('2026-02-01', $moved->DueDate);
        $this->assertSame((int) $this->olga->ID, (int) $moved->MemberID);
        $this->assertSame([], DB::query('SHOW TABLES LIKE \'Flow_FollowUp\'')->column(), 'renamed');
        DB::query('DROP TABLE IF EXISTS "_obsolete_Flow_FollowUp"');
    }

    private function sub(DataObject $record): CommentSubController
    {
        $parent = TestParticipantController::create();
        $request = new HTTPRequest('POST', 'test-participants/comments/' . $record->ID . '/save');
        $request->setSession(new Session([]));
        $parent->setRequest($request);
        $sub = CommentSubController::create()->setParentContext($parent, (int) $record->ID);
        $sub->setRequest($request);
        return $sub;
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
