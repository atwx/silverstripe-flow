<?php

namespace Atwx\Flow\Tests\Model;

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
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\SecurityToken;

class CommentStreamTest extends SapphireTest
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
        $this->olga = Member::create(['Email' => 'olga@example.com', 'FirstName' => 'Olga', 'Surname' => 'O']);
        $this->olga->write();
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        $this->olga->Groups()->add($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        Permission::grant($group->ID, TestParticipantController::singleton()->getPermissionCode('EDIT'));
        Permission::grant($group->ID, FollowUpController::singleton()->getPermissionCode('EDIT'));
        $this->logInAs($this->olga);
        Config::modify()->set(CommentSubController::class, 'display', 'stream');
    }

    private function participant(): TestParticipant
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        $course->write();
        $ruth = TestParticipant::create(['Name' => 'Ruth', 'CourseID' => $course->ID]);
        $ruth->write();
        return $ruth;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function comment(TestParticipant $record, array $data): Comment
    {
        $comment = Comment::create(array_merge([
            'RecordClass' => TestParticipant::class,
            'RecordID' => $record->ID,
        ], $data));
        $comment->write();
        return $comment;
    }

    /**
     * @param array<string, mixed> $post
     */
    private function sub(DataObject $record, string $method = 'GET', array $post = []): CommentSubController
    {
        $parent = TestParticipantController::create();
        $request = new HTTPRequest($method, 'test-participants/comments/' . $record->ID, [], $post);
        $request->addHeader('HX-Request', 'true');
        $request->setSession(new Session([]));
        $parent->setRequest($request);
        $sub = CommentSubController::create()->setParentContext($parent, (int) $record->ID);
        $sub->setRequest($request);
        return $sub;
    }

    public function testStreamOrderHighlightAndEntryText(): void
    {
        $ruth = $this->participant();
        $old = $this->comment($ruth, ['Text' => "Erste Zeile\nzweite", 'Created' => '2026-01-01 10:00:00']);
        $new = $this->comment($ruth, ['Text' => 'Neu', 'Created' => '2026-02-01 10:00:00']);
        $important = $this->comment($ruth, ['Text' => 'Wichtig', 'Important' => true, 'Created' => '2025-01-01']);
        $appointment = $this->comment($ruth, [
            'Kind' => Comment::APPOINTMENT, 'Text' => 'Gespräch', 'DueDate' => '2026-12-01', 'DueTime' => '10:00',
            'MemberID' => $this->olga->ID,
        ]);

        $order = Comment::streamOrder(Comment::forRecord($ruth))->column('ID');
        $this->assertSame([$appointment->ID, $important->ID, $new->ID, $old->ID], $order);
        $this->assertTrue($appointment->getStreamHighlight());
        $this->assertTrue($important->getStreamHighlight());
        $this->assertFalse($new->getStreamHighlight());
        $this->assertSame('Termin (Olga O):', $appointment->getStreamLead());
        $this->assertSame('Termin: 01.12.2026 10:00', $appointment->getStreamDue());
        $this->assertSame('', $old->getStreamLead(), 'memo without lead');
        $this->assertSame("Erste Zeile<br>\nzweite", (string) $old->getTextHtml());
        $this->assertStringStartsWith('01.01.2026', $old->getStreamMeta());

        $response = $this->sub($ruth)->index($this->sub($ruth)->getRequest());
        $this->assertInstanceOf(HTTPResponse::class, $response);
        $html = (string) $response->getBody();
        $this->assertStringContainsString('fdk-comment-stream', $html);
        $this->assertStringContainsString('fdk-comment-highlight', $html);
        $this->assertLessThan(strpos($html, 'Neu<'), strpos($html, 'Gespräch'), 'appointment first');
        $this->assertStringContainsString('name="ids[]"', $html, 'checkboxes for deleting several');
    }

    public function testDeleteSelectedOnlyOwnRecordsWithToken(): void
    {
        $ruth = $this->participant();
        $other = $this->participant();
        $a = $this->comment($ruth, ['Text' => 'a']);
        $b = $this->comment($ruth, ['Text' => 'b']);
        $keep = $this->comment($ruth, ['Text' => 'bleibt']);
        $foreign = $this->comment($other, ['Text' => 'fremd']);

        SecurityToken::enable();
        $sub = $this->sub($ruth, 'POST', ['ids' => [$a->ID, $b->ID, $foreign->ID]]);
        $this->assertSame(400, $this->catchStatus(fn () => $sub->deleteselected($sub->getRequest())), 'no token');
        $token = SecurityToken::inst()->getValue();
        $sub = $this->sub($ruth, 'POST', ['ids' => [$a->ID, $b->ID, $foreign->ID], 'SecurityID' => $token]);
        $html = (string) $sub->deleteselected($sub->getRequest())->getBody();
        SecurityToken::disable();

        $this->assertStringContainsString('2 Kommentare gelöscht.', $html);
        $this->assertNull(Comment::get()->byID($a->ID));
        $this->assertNull(Comment::get()->byID($b->ID));
        $this->assertNotNull(Comment::get()->byID($keep->ID));
        $this->assertNotNull(Comment::get()->byID($foreign->ID), 'other record: not deleted');
    }

    public function testFollowUpListAsStreamWithRecordLinksAndBulkDelete(): void
    {
        $ruth = $this->participant();
        $this->comment($ruth, [
            'Kind' => Comment::FOLLOW_UP,
            'Text' => 'Anrufen',
            'DueDate' => date('Y-m-d'),
            'MemberID' => $this->olga->ID,
        ]);
        $this->comment($ruth, [
            'Kind' => Comment::APPOINTMENT,
            'Text' => 'Treffen',
            'DueDate' => '2099-01-01',
            'MemberID' => $this->olga->ID,
        ]);
        Config::modify()->set(FollowUpController::class, 'display', 'stream');
        $controller = FollowUpController::create();
        $request = new HTTPRequest('GET', 'followups');
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        $html = (string) $controller->index($request);
        $this->assertStringContainsString('fdk-comment-stream', $html);
        $this->assertStringContainsString('Anrufen', $html);
        $this->assertStringNotContainsString('Treffen', $html, 'appointments only in their view');
        $this->assertStringContainsString('test-participants/view/' . $ruth->ID, $html, 'link to the record');
        $this->assertStringContainsString('lg:w-1/2', $html);
        $this->assertStringContainsString('data-confirm=', $html, 'bulk delete asks');

        $controller = FollowUpController::create();
        $request = new HTTPRequest('GET', 'followups', ['view' => 'appointments']);
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        $this->assertSame(['Treffen'], $controller->Items()->column('Text'));
    }

    private function catchStatus(callable $call): int
    {
        try {
            $call();
        } catch (\SilverStripe\Control\HTTPResponse_Exception $e) {
            return $e->getResponse()->getStatusCode();
        }
        return 200;
    }
}
