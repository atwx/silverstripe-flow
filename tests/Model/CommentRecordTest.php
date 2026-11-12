<?php

namespace Atwx\Flow\Tests\Model;

use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\CommentSubController;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\Comment;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Service\AssignmentService;
use Atwx\Flow\Service\FollowUpService;
use Atwx\Flow\Service\TransitionService;
use Atwx\Flow\Tests\Stubs\TestCommentRedirect;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use Atwx\Flow\Tests\Stubs\TestParticipant;
use Atwx\SilverstripeFrontdeskKit\Catalog\CatalogEntry;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * A case file that keeps its comments on another record (flowCommentRecord()):
 * sub-list, follow-up rules and timeline use that record.
 */
class CommentRecordTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestParticipant::class,
    ];

    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected static $required_extensions = [
        TestCourse::class => [TestCommentRedirect::class],
    ];

    public function testFollowUpsSubListAndTimelineUseTheCommentRecord(): void
    {
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        $olga = Member::create(['Email' => 'olga@example.com', 'FirstName' => 'Olga']);
        $olga->write();
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        $olga->Groups()->add($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        $this->logInAs($olga);

        $course = TestCourse::create(['Title' => 'Kurs']);
        $draft = Status::get()->filter(['Code' => 'draft', 'Workflow.Code' => 'course'])->first();
        CaseFileExtension::writeStatus($course, (int) $draft?->ID);
        $ruth = TestParticipant::create(['Name' => 'Ruth', 'CourseID' => $course->ID]);
        $ruth->write();
        $lead = Responsibility::get()->filter(['Code' => 'lead', 'Workflow.Code' => 'course'])->first();
        $this->assertNotNull($lead);
        AssignmentService::singleton()->assign($course, $lead, $olga, $olga);
        $this->assertSame($ruth->ID, Comment::recordFor($course)->ID);

        $actor = MemberActor::current();
        $this->assertNotNull($actor);
        TransitionService::singleton()->transition($course, 'with_speaker', $actor);
        $followUp = Comment::forRecord($ruth)->first();
        $this->assertNotNull($followUp, 'the rule puts the follow-up on the comment record');
        $this->assertSame(Comment::FOLLOW_UP, $followUp->Kind);
        $this->assertSame(0, Comment::forRecord($course)->count());

        $parent = TestCourseController::create();
        $request = new HTTPRequest('GET', 'test-courses/comments/' . $course->ID);
        $request->setSession(new Session([]));
        $parent->setRequest($request);
        $sub = CommentSubController::create()->setParentContext($parent, (int) $course->ID);
        $this->assertSame($ruth->ID, $sub->commentRecord()?->ID);
        $titles = $parent->timeline($course)?->column('Title') ?? [];
        $this->assertContains('Wiedervorlage zum ' . $followUp->getDueLabel() . ': Referent:in nachfassen', $titles);

        $withSpeaker = Status::get()->filter(['Code' => 'with_speaker', 'Workflow.Code' => 'course'])->first();
        $review = Status::get()->filter(['Code' => 'review', 'Workflow.Code' => 'course'])->first();
        FollowUpService::singleton()->onStatusChange($course, (int) $withSpeaker?->ID, (int) $review?->ID, null);
        $this->assertTrue((bool) Comment::get()->byID($followUp->ID)?->IsDone, 'closed on leave');
    }

    public function testEnsureDefaultType(): void
    {
        CatalogEntry::get()->filter('Catalog', Comment::TYPE_CATALOG)->removeAll();
        $type = Comment::ensureDefaultType();
        $this->assertNotNull($type);
        $this->assertSame('Memo', $type->Title);
        $this->assertSame($type->ID, Comment::ensureDefaultType()?->ID, 'only once');
    }
}
