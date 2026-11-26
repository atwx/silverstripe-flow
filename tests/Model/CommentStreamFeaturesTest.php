<?php

namespace Atwx\Flow\Tests\Model;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\CommentSubController;
use Atwx\Flow\Controller\FollowUpController;
use Atwx\Flow\Model\Comment;
use Atwx\Flow\Model\CommentReference;
use Atwx\Flow\Model\CommentType;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use Atwx\Flow\Tests\Stubs\TestParticipant;
use Atwx\Flow\Tests\Stubs\TestParticipantController;
use Atwx\SilverstripeFrontdeskKit\Catalog\Catalogs;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\SecurityToken;

class CommentStreamFeaturesTest extends SapphireTest
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

    private TestParticipant $ruth;

    protected function setUp(): void
    {
        parent::setUp();
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        $this->olga = Member::create(['Email' => 'olga@example.com', 'FirstName' => 'Olga', 'Surname' => 'Ost']);
        $this->olga->write();
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        $this->olga->Groups()->add($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        Permission::grant($group->ID, TestParticipantController::singleton()->getPermissionCode('EDIT'));
        Permission::grant($group->ID, FollowUpController::singleton()->getPermissionCode('EDIT'));
        $this->logInAs($this->olga);
        Config::modify()->set(CommentSubController::class, 'display', 'stream');
        Catalogs::singleton()->sync(Comment::TYPE_CATALOG, [
            'memo' => ['Title' => 'Memo', 'IsDefault' => true],
            'phone' => ['Title' => 'Telefonat', 'Icon' => 'phone'],
        ]);
        $course = TestCourse::create(['Title' => 'Kurs']);
        $course->write();
        $this->ruth = TestParticipant::create(['Name' => 'Ruth', 'CourseID' => $course->ID]);
        $this->ruth->write();
        TestParticipant::$referenceTargets = [];
    }

    protected function tearDown(): void
    {
        TestParticipant::$referenceTargets = [];
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function comment(array $data): Comment
    {
        $comment = Comment::create(array_merge([
            'RecordClass' => TestParticipant::class,
            'RecordID' => $this->ruth->ID,
        ], $data));
        $comment->write();
        return $comment;
    }

    /**
     * @param array<string, string> $get
     * @param array<string, mixed> $post
     * @param array<string, string> $params
     */
    private function sub(
        array $get = [],
        string $method = 'GET',
        array $post = [],
        array $params = []
    ): CommentSubController {
        $parent = TestParticipantController::create();
        $request = new HTTPRequest($method, 'test-participants/comments/' . $this->ruth->ID, $get, $post);
        $request->addHeader('HX-Request', 'true');
        $request->setSession(new Session([]));
        $request->setRouteParams($params);
        $parent->setRequest($request);
        $sub = CommentSubController::create()->setParentContext($parent, (int) $this->ruth->ID);
        $sub->setRequest($request);
        return $sub;
    }

    /**
     * @param array<string, string> $get
     */
    private function html(array $get = []): string
    {
        $sub = $this->sub($get);
        $response = $sub->index($sub->getRequest());
        $this->assertInstanceOf(HTTPResponse::class, $response);
        return (string) $response->getBody();
    }

    private function phoneType(): CommentType
    {
        $type = Catalogs::singleton()->byCode(Comment::TYPE_CATALOG, 'phone');
        $this->assertInstanceOf(CommentType::class, $type);
        return $type;
    }

    public function testOpenAreaAndDoneEntries(): void
    {
        $todo = $this->comment(['Kind' => Comment::TODO, 'Text' => 'Zeugnis anfordern', 'DueDate' => '2026-11-20',
            'MemberID' => $this->olga->ID]);
        $appointment = $this->comment(['Kind' => Comment::APPOINTMENT, 'Text' => 'Interview', 'DueDate' => '2026-11-28',
            'DueTime' => '10:00']);
        $memo = $this->comment(['Text' => 'Mutter angerufen']);
        $done = $this->comment(['Kind' => Comment::TODO, 'Text' => 'Bogen senden', 'IsDone' => true]);

        $sub = $this->sub();
        $this->assertSame([$todo->ID, $appointment->ID], $sub->OpenItems()->column('ID'));
        $this->assertSame('To-Do · Zeugnis anfordern · fällig 20.11. · OO', $todo->getOpenLine());
        $this->assertSame('Termin · Interview · 28.11. 10:00', $appointment->getOpenLine());
        $this->assertSame([$memo->ID], $sub->Items()->column('ID'), 'open ones above, done ones hidden');
        $this->assertEqualsCanonicalizing([$memo->ID, $done->ID], $this->sub(['done' => '1'])->Items()->column('ID'));

        $html = $this->html();
        $this->assertStringContainsString('Offen (2)', $html);
        $this->assertStringContainsString('erledigte einblenden', $html);
        $this->assertStringContainsString('comments/' . $this->ruth->ID . '/toggledone/' . $todo->ID, $html);
    }

    public function testToggleDoneNeedsTokenAndReturnsTheStream(): void
    {
        $todo = $this->comment(['Kind' => Comment::TODO, 'Text' => 'Anrufen']);
        SecurityToken::enable();
        try {
            $sub = $this->sub([], 'POST', [], ['ID' => (string) $todo->ID]);
            $this->assertSame(400, $this->statusOf(fn () => $sub->toggledone($sub->getRequest())));
            $post = ['SecurityID' => SecurityToken::inst()->getValue()];
            $sub = $this->sub([], 'POST', $post, ['ID' => (string) $todo->ID]);
            $html = (string) $sub->toggledone($sub->getRequest())->getBody();
        } finally {
            SecurityToken::disable();
        }
        $todo = Comment::get()->byID($todo->ID);
        $this->assertTrue((bool) $todo->IsDone);
        $this->assertSame($this->olga->ID, (int) $todo->DoneByID);
        $this->assertStringNotContainsString('Offen (', $html, 'nothing open any more');
    }

    public function testTypesIconsAndFilterChips(): void
    {
        $phone = $this->comment(['Text' => 'Rückruf', 'TypeID' => $this->phoneType()->ID]);
        $memo = $this->comment(['Text' => 'Notiz']);
        $this->assertSame('phone', $phone->getTypeIcon());
        $this->assertSame('Telefonat', $phone->getTypeLabel());
        $this->assertSame('Memo', $memo->getTypeLabel(), 'default type');

        $html = $this->html();
        $this->assertStringContainsString('<i data-lucide="phone"', $html);
        $this->assertStringContainsString('?type=phone', $html);
        $this->assertSame([$phone->ID], $this->sub(['type' => 'phone'])->Items()->column('ID'));

        Config::modify()->set(CommentSubController::class, 'stream_types', false);
        $html = $this->html(['type' => 'phone']);
        $this->assertStringNotContainsString('?type=phone', $html, 'no chips');
        $this->assertCount(2, $this->sub(['type' => 'phone'])->Items(), 'type filter off');
        $this->assertNull($this->sub()->EditForm()->Fields()->dataFieldByName('TypeID'), 'no type in the form');
    }

    public function testReferencesInTheFormAndAsFilter(): void
    {
        $a = TestCourse::create(['Title' => 'Ashcombe']);
        $a->write();
        $b = TestCourse::create(['Title' => 'Bramley']);
        $b->write();
        $foreign = TestCourse::create(['Title' => 'Fremd']);
        $foreign->write();
        TestParticipant::$referenceTargets = [$a, $b];

        $field = $this->sub()->EditForm()->Fields()->dataFieldByName('FlowReferences');
        $this->assertInstanceOf(\SilverStripe\Forms\CheckboxSetField::class, $field, 'multiple choice in the form');
        $this->assertSame('Kurse', $field->Title());
        $this->assertSame(['Ashcombe', 'Bramley'], array_values($field->getSource()));

        $comment = $this->comment(['Text' => 'Test bestätigt']);
        $this->comment(['Text' => 'Ohne Bezug']);
        $afterWrite = new \ReflectionMethod(CommentSubController::class, 'afterWrite');
        $afterWrite->invoke($this->sub(), $comment, [
            'FlowReferencesShown' => '1',
            'FlowReferences' => [CommentReference::keyFor($a), CommentReference::keyFor($foreign)],
        ]);
        $this->assertSame([CommentReference::keyFor($a)], $comment->referenceKeys(), 'only offered records');
        $this->assertSame(['Ashcombe'], $comment->getReferenceTitles()->column('Title'));

        $this->assertSame([$comment->ID], $this->sub(['ref' => CommentReference::keyFor($a)])->Items()->column('ID'));
        $this->assertCount(2, $this->sub()->Items());
        $html = $this->html();
        $this->assertStringContainsString('Kurse: alle', $html);
        $this->assertStringContainsString('?ref=' . rawurlencode(CommentReference::keyFor($b)), $html);
        $this->assertStringContainsString('badge-outline">Ashcombe</span>', $html);

        $afterWrite->invoke($this->sub(), $comment, ['FlowReferencesShown' => '1']);
        $this->assertSame([], $comment->referenceKeys(), 'all unticked');
        $comment->setReferences([$b]);
        $comment->delete();
        $this->assertSame(0, CommentReference::get()->count(), 'deleted with the comment');
    }

    public function testFollowUpActionTurnsAnEntryIntoAFollowUp(): void
    {
        $memo = $this->comment(['Text' => 'Rückmeldung Bramley']);
        $sub = $this->sub();
        $labels = $sub->RowActionsFor($memo)->DirectActions->column('Label');
        $this->assertContains('Wiedervorlage', $labels);

        $data = ['ID' => $memo->ID, 'DueDate' => '2026-11-25', 'MemberID' => $this->olga->ID];
        $sub->doFollowUp($data, $sub->FollowUpForm());
        $memo = Comment::get()->byID($memo->ID);
        $this->assertSame(Comment::FOLLOW_UP, $memo->Kind);
        $this->assertSame('2026-11-25', $memo->DueDate);
        $this->assertSame($this->olga->ID, (int) $memo->MemberID);
        $this->assertFalse((bool) $memo->IsDone);
        $labels = $this->sub()->RowActionsFor($memo)->DirectActions->column('Label');
        $this->assertNotContains('Wiedervorlage', $labels, 'already an open follow-up');
    }

    public function testSimpleStreamWithoutFeatures(): void
    {
        $flags = ['stream_open', 'stream_types', 'stream_references', 'stream_done_toggle', 'stream_followup_action'];
        foreach ($flags as $flag) {
            Config::modify()->set(CommentSubController::class, $flag, false);
        }
        TestParticipant::$referenceTargets = [$this->ruth->Course()];
        $todo = $this->comment(['Kind' => Comment::TODO, 'Text' => 'Offen']);
        $done = $this->comment(['Kind' => Comment::TODO, 'Text' => 'Erledigt', 'IsDone' => true]);
        $sub = $this->sub();
        $this->assertEqualsCanonicalizing([$todo->ID, $done->ID], $sub->Items()->column('ID'), 'all in the stream');
        $html = $this->html();
        $this->assertStringNotContainsString('Offen (', $html);
        $this->assertStringNotContainsString('erledigte einblenden', $html);
        $this->assertStringNotContainsString('fdk-comment-filters', $html);
        $this->assertNull($sub->EditForm()->Fields()->dataFieldByName('FlowReferences'));
        $labels = $sub->RowActionsFor($todo)->DirectActions->column('Label');
        $this->assertNotContains('Wiedervorlage', $labels);
    }

    public function testCountTodoBasket(): void
    {
        $this->comment(['Kind' => Comment::TODO, 'Text' => 'Korb']);
        $this->comment(['Kind' => Comment::TODO, 'Text' => 'Meins', 'MemberID' => $this->olga->ID]);
        $this->comment(['Kind' => Comment::TODO, 'Text' => 'Korb erledigt', 'IsDone' => true]);
        $this->comment(['Kind' => Comment::FOLLOW_UP, 'Text' => 'Wiedervorlage ohne Person']);
        $this->assertSame(1, FollowUpController::countTodoBasket());
        $this->assertSame(1, FollowUpController::countTodos());
        $this->logOut();
        $this->assertSame(0, FollowUpController::countTodoBasket());
    }

    private function statusOf(callable $call): int
    {
        try {
            $call();
        } catch (HTTPResponse_Exception $e) {
            return $e->getResponse()->getStatusCode();
        }
        return 200;
    }
}
