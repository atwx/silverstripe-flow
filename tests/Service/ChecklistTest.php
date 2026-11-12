<?php

namespace Atwx\Flow\Tests\Service;

use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\ChecklistSubController;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\ChecklistEntry;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Service\TransitionException;
use Atwx\Flow\Service\TransitionService;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Control\Session;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class ChecklistTest extends SapphireTest
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
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        $member = Member::create(['Email' => 'olga@example.com', 'FirstName' => 'Olga']);
        $member->write();
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        $member->Groups()->add($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        $this->logInAs($member);
    }

    private function courseIn(string $status): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        $statusID = Status::get()->filter(['Code' => $status, 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    private function sub(TestCourse $course): ChecklistSubController
    {
        $parent = TestCourseController::create();
        $request = new HTTPRequest('GET', 'test-courses/checklist/' . $course->ID);
        $request->setSession(new Session([]));
        $parent->setRequest($request);
        $sub = ChecklistSubController::create()->setParentContext($parent, (int) $course->ID);
        $sub->setRequest($request);
        return $sub;
    }

    public function testListCreatesOneEntryPerItemInOrder(): void
    {
        $course = $this->courseIn('draft');
        $items = $this->sub($course)->Items();
        $this->assertSame(['Raum gebucht', 'Teilnahmebescheinigungen'], $items->map('ID', 'ItemTitle')->values());
        $this->sub($course)->Items();
        $this->assertSame(2, ChecklistEntry::get()->count(), 'entries are created once');
        $this->assertFalse($this->sub($course)->canAdd());
    }

    public function testRequiredItemBlocksTheStatusUntilDone(): void
    {
        $course = $this->courseIn('review');
        $actor = MemberActor::current();
        $this->assertNotNull($actor);
        try {
            TransitionService::singleton()->transition($course, 'done', $actor);
            $this->fail('expected the checklist to block');
        } catch (TransitionException $e) {
            $this->assertSame('Vor „Abgeschlossen“ fehlt noch in der Checkliste: Raum gebucht.', $e->getMessage());
        }

        $this->sub($course)->Items();
        $entry = ChecklistEntry::get()->filter('Item.Code', 'room')->first();
        $this->assertNotNull($entry);
        $entry->State = 'not_needed';
        $entry->write();
        $this->assertSame(date('Y-m-d'), $entry->Date, 'date set when completed');

        $change = TransitionService::singleton()->transition($course, 'done', $actor);
        $this->assertSame(
            (int) Status::get()->filter(['Code' => 'done', 'Workflow.Code' => 'course'])->first()?->ID,
            (int) $change->ToID
        );
    }

    public function testEntriesCannotBeDeletedAndGoWithTheCaseFile(): void
    {
        $course = $this->courseIn('draft');
        $sub = $this->sub($course);
        $entry = $sub->Items()->first();
        $this->assertNotNull($entry);
        try {
            $sub->delete(new HTTPRequest('DELETE', 'x'));
            $this->fail('expected 403');
        } catch (HTTPResponse_Exception $e) {
            $this->assertSame(403, $e->getResponse()->getStatusCode());
        }
        $course->delete();
        $this->assertSame(0, ChecklistEntry::get()->count());
    }
}
