<?php

namespace Atwx\Flow\Tests\Controller;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\CommentSubController;
use Atwx\Flow\Controller\FollowUpController;
use Atwx\Flow\Model\Comment;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use Atwx\Flow\Tests\Stubs\TestParticipant;
use Atwx\Flow\Tests\Stubs\TestParticipantController;
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

class FollowUpPagesTest extends SapphireTest
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

    private Member $kai;

    private TestParticipant $ruth;

    private ?Session $session = null;

    protected function setUp(): void
    {
        parent::setUp();
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        Permission::grant($group->ID, TestParticipantController::singleton()->getPermissionCode('EDIT'));
        Permission::grant($group->ID, FollowUpController::singleton()->getPermissionCode('EDIT'));
        foreach (['olga' => ['Olga', 'Ost'], 'kai' => ['Kai', 'Klein']] as $key => [$first, $last]) {
            $member = Member::create(['Email' => $key . '@example.com', 'FirstName' => $first, 'Surname' => $last]);
            $member->write();
            $member->Groups()->add($group);
            $this->$key = $member;
        }
        $this->logInAs($this->olga);
        $course = TestCourse::create(['Title' => 'Kurs']);
        $course->write();
        $this->ruth = TestParticipant::create(['Name' => 'Ruth', 'CourseID' => $course->ID]);
        $this->ruth->write();
        Config::modify()->set(FollowUpController::class, 'pages', [
            'followups' => [
                'title' => 'Wiedervorlagen',
                'columns' => [[
                    'title' => 'Meine Wiedervorlagen',
                    'kind' => 'followup',
                    'title_other' => 'Wiedervorlagen von {name}',
                ]],
            ],
            'todos' => [
                'title' => 'To-Do',
                'columns' => [
                    ['title' => 'Meine To-Do-Liste', 'kind' => 'todo'],
                    ['title' => 'Todo-Korb', 'kind' => 'todo', 'member' => 'none', 'actions' => ['take']],
                ],
            ],
        ]);
        Config::modify()->set(FollowUpController::class, 'view_pages', ['basket' => 'todos']);
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
     * @param array<string, string> $post
     */
    private function request(string $url, array $get = [], string $method = 'GET', array $post = []): HTTPResponse
    {
        $controller = FollowUpController::create();
        $request = new HTTPRequest($method, $url, $get, $post);
        $request->setSession($this->session ?? new Session([]));
        try {
            $response = $controller->handleRequest($request);
        } catch (HTTPResponse_Exception $e) {
            return $e->getResponse();
        }
        return $response;
    }

    public function testToDoPageHasMyListAndTheBasket(): void
    {
        $this->comment([
            'Kind' => Comment::TODO, 'Text' => 'Meins', 'MemberID' => $this->olga->ID, 'DueDate' => '2026-05-01',
        ]);
        $this->comment(['Kind' => Comment::TODO, 'Text' => 'Im Korb']);
        $this->comment(['Kind' => Comment::TODO, 'Text' => 'Von Kai', 'MemberID' => $this->kai->ID]);
        $this->comment(['Kind' => Comment::FOLLOW_UP, 'Text' => 'Keine To-Do', 'MemberID' => $this->olga->ID]);
        $this->comment([
            'Kind' => Comment::TODO, 'Text' => 'Erledigt', 'MemberID' => $this->olga->ID, 'IsDone' => true,
        ]);

        $html = (string) $this->request('todos')->getBody();
        $count = '<span class="text-base-content/50 font-normal">(1)';
        $this->assertStringContainsString('Meine To-Do-Liste ' . $count, $html);
        $this->assertStringContainsString('Todo-Korb ' . $count, $html);
        $this->assertStringContainsString('Meins', $html);
        $this->assertStringContainsString('Im Korb', $html);
        $this->assertStringNotContainsString('Von Kai', $html);
        $this->assertStringNotContainsString('Keine To-Do', $html);
        $this->assertStringNotContainsString('Erledigt', $html);
        $this->assertSame(1, substr_count($html, '>Übernehmen</button>'), 'take over only in the basket');
        $this->assertStringContainsString('Nach Nutzer', $html);
        $this->assertStringNotContainsString('fdk-list-views', $html, 'no view chips');
        $this->assertStringNotContainsString('fdk-filter-bar', $html, 'no filter bar');
        $this->assertStringContainsString(
            'test-participants/comments/' . $this->ruth->ID . '/edit/',
            $html,
            'edit in the comments of the record'
        );

        $html = (string) $this->request('todos', ['member' => 'all'])->getBody();
        $this->assertStringContainsString('Meins', $html, 'all members');
        $this->assertStringContainsString('Von Kai', $html);
        $this->assertStringContainsString('Meine To-Do-Liste – Alle', $html);
        $this->assertStringContainsString('<option value="all" selected>Alle</option>', $html);

        $html = (string) $this->request('todos', ['member' => (string) $this->kai->ID])->getBody();
        $this->assertStringContainsString('Von Kai', $html, 'another member');
        $this->assertStringNotContainsString('Meins', $html);
        $this->assertStringContainsString('Im Korb', $html, 'the basket stays');
    }

    public function testOtherMemberTitleAndRedirects(): void
    {
        $this->comment(['Kind' => Comment::FOLLOW_UP, 'Text' => 'Nachfassen', 'MemberID' => $this->kai->ID]);
        $html = (string) $this->request('followups', ['member' => (string) $this->kai->ID])->getBody();
        $this->assertStringContainsString('Wiedervorlagen von Kai Klein', $html);

        $first = (string) $this->request('')->getHeader('Location');
        $this->assertStringEndsWith('followups/followups', $first, 'first page');
        $basket = (string) $this->request('', ['view' => 'basket'])->getHeader('Location');
        $this->assertStringEndsWith('followups/todos', $basket);
        $response = $this->request('', ['view' => 'overdue']);
        $this->assertSame(200, $response->getStatusCode(), 'other views keep the list');

        Config::modify()->set(FollowUpController::class, 'pages', []);
        $this->assertSame(404, $this->request('todos')->getStatusCode(), 'without pages no page');
        $this->assertSame(200, $this->request('')->getStatusCode(), 'and the list as before');
    }

    public function testTickTakeAndRemoveWithToken(): void
    {
        $mine = $this->comment(['Kind' => Comment::TODO, 'Text' => 'Meins', 'MemberID' => $this->olga->ID]);
        $basket = $this->comment(['Kind' => Comment::TODO, 'Text' => 'Im Korb']);
        $gone = $this->comment(['Kind' => Comment::TODO, 'Text' => 'Weg']);

        SecurityToken::enable();
        $this->session = new Session([SecurityToken::inst()->getName() => 'test-token']);
        try {
            $this->assertSame(400, $this->request('done/' . $mine->ID, [], 'POST')->getStatusCode(), 'no token');
            $token = ['SecurityID' => 'test-token'];
            $this->assertSame(302, $this->request('done/' . $mine->ID, [], 'POST', $token)->getStatusCode());
            $take = $this->request('take/' . $basket->ID, [], 'POST', $token);
            $this->assertSame(302, $take->getStatusCode());
            $this->assertSame(302, $this->request('remove/' . $gone->ID, [], 'POST', $token)->getStatusCode());
        } finally {
            SecurityToken::disable();
        }
        $this->assertTrue((bool) Comment::get()->byID($mine->ID)->IsDone);
        $this->assertSame($this->olga->ID, (int) Comment::get()->byID($basket->ID)->MemberID);
        $this->assertNull(Comment::get()->byID($gone->ID));
    }

    public function testCommentFormReturnsToThePage(): void
    {
        $comment = $this->comment(['Kind' => Comment::TODO, 'Text' => 'Meins', 'MemberID' => $this->olga->ID]);
        $parent = TestParticipantController::create();
        $request = new HTTPRequest('POST', 'test-participants/comments/' . $this->ruth->ID, [], [
            'ID' => $comment->ID, 'BackURL' => 'followups/todos?member=1',
        ]);
        $request->setSession(new Session([]));
        $parent->setRequest($request);
        $sub = CommentSubController::create()->setParentContext($parent, (int) $this->ruth->ID);
        $sub->setRequest($request);
        $link = new \ReflectionMethod(CommentSubController::class, 'afterSaveLink');
        $this->assertSame('followups/todos?member=1', $link->invoke($sub));

        $request = new HTTPRequest('POST', 'x', [], ['BackURL' => 'https://evil.example/']);
        $request->setSession(new Session([]));
        $sub->setRequest($request);
        $fallback = (string) $link->invoke($sub);
        $this->assertStringContainsString('test-participants/view/' . $this->ruth->ID, $fallback, 'no foreign site');
    }
}
