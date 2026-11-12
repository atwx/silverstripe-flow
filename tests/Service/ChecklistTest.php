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
use Atwx\SilverstripeFrontdeskKit\Forms\FileUploadField;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Validation\ValidationException;
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
        TestAssetStore::activate('ChecklistTest');
        Upload_Validator::config()->set('use_is_uploaded_file', false);
    }

    protected function tearDown(): void
    {
        $_FILES = [];
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function entry(TestCourse $course, string $code): ChecklistEntry
    {
        $this->sub($course)->Items();
        $entry = ChecklistEntry::get()->filter(['CaseID' => $course->ID, 'Item.Code' => $code])->first();
        $this->assertNotNull($entry);
        return $entry;
    }

    /**
     * Sub-controller handling a POST of the edit form for an entry.
     *
     * @param array<string, mixed> $post
     */
    private function postSub(TestCourse $course, array $post): ChecklistSubController
    {
        $sub = $this->sub($course);
        $request = new HTTPRequest('POST', 'test-courses/checklist/' . $course->ID . '/EditForm', [], $post);
        $request->setSession(new Session([]));
        $sub->setRequest($request);
        (new \ReflectionProperty($sub, 'action'))->setValue($sub, 'EditForm');
        return $sub;
    }

    private function upload(string $field, string $name): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'flow');
        file_put_contents($path, "%PDF-1.4\n%test\n");
        $_FILES[$field] = [
            'name' => $name,
            'type' => 'application/pdf',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => 16,
        ];
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

    public function testRequiredFileIsNeededToMarkTheEntryDone(): void
    {
        $entry = $this->entry($this->courseIn('draft'), 'certificate');
        $entry->State = 'done';
        try {
            $entry->write();
            $this->fail('expected a validation error');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Teilnahmebescheinigungen', $e->getMessage());
        }
        $entry->State = 'not_needed';
        $entry->write();
        $this->assertSame('not_needed', ChecklistEntry::get()->byID($entry->ID)?->State);
    }

    public function testFileFieldOnlyForItemsWithAFile(): void
    {
        $course = $this->courseIn('draft');
        $room = $this->entry($course, 'room');
        $certificate = $this->entry($course, 'certificate');

        $fields = $this->postSub($course, ['ID' => $room->ID])->EditForm()->Fields();
        $this->assertNull($fields->dataFieldByName('FileID'));
        $this->assertNotNull($fields->dataFieldByName('ItemTitle'));

        $fields = $this->postSub($course, ['ID' => $certificate->ID])->EditForm()->Fields();
        $this->assertInstanceOf(FileUploadField::class, $fields->dataFieldByName('FileID'));
    }

    public function testUploadedFileIsProtectedAndOnlyDownloadedThroughItsCaseFile(): void
    {
        $course = $this->courseIn('draft');
        $entry = $this->entry($course, 'certificate');
        $other = $this->courseIn('draft');
        $post = ['ID' => $entry->ID, 'State' => 'done', 'Comment' => 'da', 'CaseID' => $other->ID];
        $this->upload('FileID', 'bescheinigung.pdf');

        $sub = $this->postSub($course, $post);
        $form = $sub->EditForm();
        $form->loadDataFrom($post);
        $sub->save($post, $form);

        $entry = ChecklistEntry::get()->byID($entry->ID);
        $this->assertNotNull($entry);
        $this->assertSame('done', $entry->State);
        $this->assertSame((int) $course->ID, (int) $entry->CaseID, 'a posted CaseID is ignored');
        $file = $entry->File();
        $this->assertTrue($file->exists());
        $this->assertSame(AssetStore::VISIBILITY_PROTECTED, $file->getVisibility());

        $download = function (TestCourse $parent) use ($entry) {
            $sub = $this->sub($parent);
            $request = new HTTPRequest('GET', 'x');
            $request->setRouteParams(['ID' => $entry->ID, 'OtherID' => 'FileID']);
            return $sub->file($request);
        };
        $this->assertStringStartsWith('%PDF', $download($course)->getBody());
        try {
            $download($other);
            $this->fail('expected 404 through another case file');
        } catch (HTTPResponse_Exception $e) {
            $this->assertSame(404, $e->getResponse()->getStatusCode());
        }

        $course->delete();
        $this->assertNull(File::get()->byID($file->ID), 'the file goes with the case file');
    }
}
