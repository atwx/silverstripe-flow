<?php

namespace Atwx\Flow\Tests\Document;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\CaseDocumentSubController;
use Atwx\Flow\Document\PdfRenderer;
use Atwx\Flow\Document\Placeholders;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\Note;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Service\DocumentService;
use Atwx\Flow\Tests\Stubs\FakePdfRenderer;
use Atwx\Flow\Tests\Stubs\OpenRecord;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class DocumentTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
        OpenRecord::class,
    ];

    private FakePdfRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        TestAssetStore::activate('flow-documents');
        $this->renderer = new FakePdfRenderer();
        Injector::inst()->registerService($this->renderer, PdfRenderer::class);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    public function testDocumentOfAnOpenRecordStaysProtected(): void
    {
        $this->logOut();
        $record = OpenRecord::create(['Title' => 'offen']);
        $record->write();
        $document = CaseDocument::create(['CaseClass' => $record->ClassName, 'CaseID' => $record->ID]);
        $document->File->setFromString('%PDF', 'flow-documents/offen.pdf', null, null, ['visibility' => 'protected']);
        $document->write();

        $this->assertFalse($document->canView(null), 'never without login');
        $this->assertSame('protected', $document->File->getVisibility());
    }

    private function course(string $title = 'Paläographie <I>'): TestCourse
    {
        $course = TestCourse::create(['Title' => $title, 'Venue' => 'Marburg']);
        $statusID = Status::get()->filter(['Code' => 'draft', 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    private function template(): DocumentTemplate
    {
        $template = DocumentTemplate::get()->filter(['Code' => 'confirmation', 'Workflow.Code' => 'course'])->first();
        $this->assertNotNull($template);
        return $template;
    }

    private function logInAsOrganiser(): Member
    {
        $member = Member::create(['Email' => 'olga@example.com', 'FirstName' => 'Olga', 'Surname' => 'Org']);
        $member->write();
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        $member->Groups()->add($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        $this->logInAs($member);
        return $member;
    }

    public function testPlaceholdersResolveFieldsStatusMeAndEscape(): void
    {
        $member = Member::create(['FirstName' => 'Olga', 'Surname' => 'Org']);
        $course = $this->course();
        $text = Placeholders::singleton()->replace(
            '{{Title}} | {{Venue}} | {{Status}} | {{Me.Name}} | {{Unknown}} | {{Flow.audience}} | {{ClassName}}',
            $course,
            $member
        );
        $this->assertSame(
            'Paläographie &lt;I&gt; | Marburg | Entwurf | Olga Org |  |  | ' . htmlspecialchars(TestCourse::class),
            $text
        );
        $this->assertSame('Paläographie <I>', Placeholders::singleton()->replace('{{Title}}', $course, null, false));
    }

    public function testPlaceholderDefaultsLinkAndDateTime(): void
    {
        $course = $this->course();
        $course->Venue = '';
        $placeholders = Placeholders::singleton();
        $this->assertSame(
            '- | Paläographie <I>',
            $placeholders->replace('{{Venue|-}} | {{Title|-}}', $course, null, false)
        );
        $this->assertSame('unbekannt', $placeholders->replace('{{ Unknown | unbekannt }}', $course, null, false));
        $this->assertStringEndsWith(
            'test-courses/view/' . $course->ID,
            $placeholders->replace('{{Link}}', $course, null, false)
        );
        $this->assertMatchesRegularExpression(
            '/^\d{2}\.\d{2}\.\d{4} \d{2}:\d{2}$/',
            $placeholders->replace('{{Created}}', $course, null, false)
        );
    }

    public function testPlaceholdersDoNotCallMethods(): void
    {
        $course = $this->course();
        $this->assertSame('', Placeholders::singleton()->replace('{{delete}}{{write}}', $course, null));
        $this->assertNotNull(TestCourse::get()->byID($course->ID), 'record still exists');
    }

    public function testRepeatBlocksLoopOverToManyRelations(): void
    {
        $course = $this->course('Kurs R');
        foreach (['eins', 'zwei'] as $text) {
            Note::create(['CaseClass' => $course->ClassName, 'CaseID' => $course->ID, 'Text' => $text])->write();
        }
        $text = Placeholders::singleton()->replace(
            '<ul>{{#FlowNotes}}<li>{{Text}} ({{Case.Title}})</li>{{/FlowNotes}}</ul>{{#Nope}}x{{/Nope}}',
            $course,
            null
        );
        $this->assertStringContainsString('<li>eins (Kurs R)</li>', $text);
        $this->assertStringContainsString('<li>zwei (Kurs R)</li>', $text);
        $this->assertStringEndsWith('</ul>', $text, 'unknown relation: block left out');
    }

    public function testCustomFieldShowsOptionLabel(): void
    {
        $course = $this->course();
        $course->setFlowValue('audience', array_key_first(
            CustomField::get()->find('Code', 'audience')?->optionMap() ?? []
        ));
        $course->write();
        $label = array_values(CustomField::get()->find('Code', 'audience')?->optionMap() ?? [])[0];
        $this->assertSame($label, Placeholders::singleton()->replace('{{Flow.audience}}', $course, null, false));
    }

    public function testCreateStoresAProtectedPdfAndTitle(): void
    {
        $course = $this->course('Kurs A');
        $document = DocumentService::singleton()->create($course, $this->template(), null);

        $this->assertSame('Bestätigung Kurs A', $document->Title);
        $this->assertStringContainsString(
            '<p>Der Kurs Kurs A in Marburg hat den Status Entwurf.</p>',
            $this->renderer->lastHtml
        );
        $this->assertSame('%PDF-fake Bestätigung Kurs A', $document->File->getString());
        $this->assertSame('protected', $document->File->getVisibility());
        $this->assertSame(1, $course->FlowDocuments()->count());

        $course->delete();
        $this->assertSame(0, CaseDocument::get()->count(), 'documents go with the case file');
    }

    public function testRecordActionCreatesDocumentAndTimelineEntry(): void
    {
        $this->logInAsOrganiser();
        $course = $this->course('Kurs B');
        $controller = TestCourseController::create();
        $action = null;
        foreach ($controller->recordActions($course) as $candidate) {
            if ($candidate->getName() === 'flowdocument') {
                $action = $candidate;
            }
        }
        $this->assertNotNull($action);
        $this->assertSame([(string) $this->template()->ID => 'Bestätigung'], $action->getOptions());
        $message = $action->run($course, (string) $this->template()->ID, '');
        $this->assertSame('Das Schreiben „Bestätigung Kurs B“ wurde erstellt.', $message);
        $titles = $controller->timeline($course)?->column('Title') ?? [];
        $this->assertContains('Schreiben erstellt: Bestätigung Kurs B', $titles);
    }

    public function testDownloadOnlyForDocumentsOfTheCaseFile(): void
    {
        $this->logInAsOrganiser();
        $course = $this->course('Kurs C');
        $other = $this->course('Kurs D');
        $document = DocumentService::singleton()->create($course, $this->template(), null);

        $response = $this->sub($course->ID)->download($this->request($course->ID, $document->ID));
        $this->assertSame('application/pdf', $response->getHeader('Content-Type'));
        $this->assertStringStartsWith('%PDF-fake', (string) $response->getBody());

        $this->expectException(HTTPResponse_Exception::class);
        $this->sub($other->ID)->download($this->request($other->ID, $document->ID));
    }

    private function request(int $caseID, int $documentID): HTTPRequest
    {
        $request = new HTTPRequest('GET', "test-courses/documents/{$caseID}/download/{$documentID}");
        $request->setRouteParams(['ParentID' => $caseID, 'Action' => 'download', 'ID' => $documentID]);
        $request->setSession(new Session([]));
        return $request;
    }

    private function sub(int $caseID): CaseDocumentSubController
    {
        $parent = TestCourseController::create();
        $request = $this->request($caseID, 0);
        $parent->setRequest($request);
        $sub = CaseDocumentSubController::create()->setParentContext($parent, $caseID);
        $sub->setRequest($request);
        return $sub;
    }
}
