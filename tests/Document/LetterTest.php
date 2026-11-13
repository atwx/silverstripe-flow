<?php

namespace Atwx\Flow\Tests\Document;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\CaseDocumentSubController;
use Atwx\Flow\Document\PdfRenderer;
use Atwx\Flow\Document\Placeholders;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\TextBlock;
use Atwx\Flow\Service\AssignmentService;
use Atwx\Flow\Service\DocumentService;
use Atwx\Flow\Tests\Stubs\FakePdfRenderer;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use Atwx\Flow\Tests\Stubs\TestDocumentData;
use Atwx\Flow\Tests\Stubs\TestParticipant;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class LetterTest extends SapphireTest
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
     * @var array<string, array<int, class-string>>
     */
    protected static $required_extensions = [
        DocumentService::class => [TestDocumentData::class],
    ];

    private FakePdfRenderer $renderer;

    private Member $olga;

    private Member $lena;

    private TestCourse $course;

    protected function setUp(): void
    {
        parent::setUp();
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        TestAssetStore::activate('flow-documents');
        $this->renderer = new FakePdfRenderer();
        Injector::inst()->registerService($this->renderer, PdfRenderer::class);

        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        foreach (['olga' => 'Olga', 'lena' => 'Lena'] as $key => $name) {
            $member = Member::create(['Email' => "{$key}@example.com", 'FirstName' => $name, 'Surname' => 'Org']);
            $member->write();
            $member->Groups()->add($group);
            $this->$key = $member;
        }
        $this->logInAs($this->olga);

        TextBlock::create([
            'Code' => 'Intro',
            'Title' => 'Einleitung',
            'Body' => '<p><strong>Kurs</strong> {{Title}}</p>',
        ])->write();

        $this->course = TestCourse::create(['Title' => 'Paläographie <I>', 'Venue' => 'Marburg']);
        $statusID = Status::get()->filter(['Code' => 'draft', 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($this->course, (int) $statusID);
        foreach ([['Anna', 'anna@example.org'], ['Ben', ''], ['Cem', 'cem@example.org']] as [$name, $email]) {
            TestParticipant::create(['Name' => $name, 'Email' => $email, 'CourseID' => $this->course->ID])->write();
        }
        $lead = Responsibility::get()->filter(['Code' => 'lead', 'Workflow.Code' => 'course'])->first();
        $this->assertNotNull($lead);
        AssignmentService::singleton()->assign($this->course, $lead, $this->lena, $this->olga);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function letter(): DocumentTemplate
    {
        $template = DocumentTemplate::get()->filter(['Code' => 'letter', 'Workflow.Code' => 'course'])->first();
        $this->assertNotNull($template);
        return $template;
    }

    private function participantID(string $name): int
    {
        return (int) TestParticipant::get()->find('Name', $name)?->ID;
    }

    public function testBlocksLoopsFreeTextSelectedAndSigner(): void
    {
        $html = DocumentService::singleton()->html($this->course, $this->letter(), $this->olga, [
            'FreeText' => "Zeile 1\n<b>Zeile 2</b>",
            'SelectedIDs' => [$this->participantID('Cem'), $this->participantID('Ben')],
        ]);
        $block = '<p><strong>Kurs</strong> Paläographie &lt;I&gt;</p>';
        $this->assertStringContainsString($block, $html, 'block as HTML');
        $this->assertStringContainsString('<ul><li>Ben</li><li>Cem</li></ul>', $html, 'chosen entries');
        $this->assertStringContainsString('<ol><li>Cem</li><li>Anna</li></ol>', $html, 'sorted descending, filtered');
        $this->assertStringContainsString('<p>Zeile 1<br>' . "\n" . '&lt;b&gt;Zeile 2&lt;/b&gt;</p>', $html);
        $this->assertStringContainsString('<p>Lena Org</p>', $html, 'signer from the responsibility');
        $this->assertStringContainsString('<title>Hook: Brief</title>', $html, 'updateFlowDocumentData');

        $plain = Placeholders::singleton()->replace('{{Block.Intro}} / {{Block.Missing}}', $this->course, null, false);
        $this->assertSame('Kurs Paläographie <I> / ', $plain, 'plain text: block without tags');
        $filtered = Placeholders::singleton()->replace(
            '{{#Participants filter=Name:Ben}}{{Name}}{{/Participants}}',
            $this->course,
            null
        );
        $this->assertSame('Ben', $filtered);
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $post
     */
    private function sub(string $method = 'GET', array $get = [], array $post = []): CaseDocumentSubController
    {
        $parent = TestCourseController::create();
        $request = new HTTPRequest($method, 'test-courses/documents/' . $this->course->ID . '/compose', $get, $post);
        $request->setRouteParams(['ParentID' => $this->course->ID, 'Action' => 'compose']);
        $request->setSession(new Session([]));
        $parent->setRequest($request);
        $sub = CaseDocumentSubController::create()->setParentContext($parent, (int) $this->course->ID);
        $sub->setRequest($request);
        return $sub;
    }

    public function testDialogCreatesEditsAndDeletesDocuments(): void
    {
        $template = $this->letter();
        $form = $this->sub('GET', ['template' => $template->ID])->documentForm();
        $fields = $form->Fields();
        $this->assertEqualsCanonicalizing(
            [$this->participantID('Anna'), $this->participantID('Ben'), $this->participantID('Cem')],
            array_map('intval', (array) $fields->dataFieldByName('Entries')?->getValue()),
            'all entries chosen'
        );
        $this->assertSame((int) $this->lena->ID, (int) $fields->dataFieldByName('SignerID')?->getValue());
        $this->assertNotNull($fields->dataFieldByName('FreeText'));

        $data = [
            'TemplateID' => $template->ID,
            'Entries' => [$this->participantID('Anna')],
            'FreeText' => 'Bitte bis Freitag antworten.',
            'SignerID' => $this->olga->ID,
        ];
        $sub = $this->sub('POST', [], $data);
        $preview = $sub->doPreview($data, $sub->documentForm());
        $this->assertStringContainsString('Bitte bis Freitag antworten.', (string) $preview->getBody());
        $this->assertSame("script-src 'none'", $preview->getHeader('Content-Security-Policy'));
        $this->assertSame(0, CaseDocument::get()->count(), 'preview creates nothing');

        $sub->doCreate($data, $sub->documentForm());
        $document = CaseDocument::get()->first();
        $this->assertNotNull($document);
        $this->assertSame([$this->participantID('Anna')], $document->selectedIDs());
        $this->assertSame('Bitte bis Freitag antworten.', $document->FreeText);
        $this->assertSame((int) $this->olga->ID, (int) $document->SignerID);
        $this->assertStringContainsString('<p>Olga Org</p>', $this->renderer->lastHtml);

        // Edit: the stored inputs are loaded; saving creates the PDF again from the current data.
        $form = $this->sub('GET', ['document' => $document->ID])->documentForm();
        $this->assertSame('Bitte bis Freitag antworten.', $form->Fields()->dataFieldByName('FreeText')?->getValue());
        $this->course->Title = 'Paläographie II';
        $this->course->write();
        $edit = ['DocumentID' => $document->ID] + $data;
        $edit['Entries'] = [$this->participantID('Cem')];
        $sub = $this->sub('POST', [], $edit);
        $sub->doCreate($edit, $sub->documentForm());
        $this->assertSame(1, CaseDocument::get()->count(), 'same document');
        $document = CaseDocument::get()->byID($document->ID);
        $this->assertNotNull($document);
        $this->assertSame([$this->participantID('Cem')], $document->selectedIDs());
        $this->assertStringContainsString('<ul><li>Cem</li></ul>', $this->renderer->lastHtml);
        $this->assertStringContainsString('Paläographie II', $this->renderer->lastHtml, 'current data');

        $this->assertTrue($document->canDelete());
        $this->assertTrue($this->sub()->canWriteDocuments());
        $this->assertTrue($this->sub()->checkAccessAction('delete'), 'delete with canWriteDocuments');
        $this->logInAs(Member::create(['Email' => 'nobody@example.com']));
        $this->assertFalse($this->sub()->canWriteDocuments(), 'no role in the workflow');
        $this->assertFalse($document->canDelete());
    }

    public function testDocumentWithoutDialogInputsUsesAllEntriesAndTheDefaultSigner(): void
    {
        $document = DocumentService::singleton()->create($this->course, $this->letter(), $this->olga);
        $all = '<ul><li>Anna</li><li>Ben</li><li>Cem</li></ul>';
        $this->assertStringContainsString($all, $this->renderer->lastHtml);
        $this->assertSame((int) $this->lena->ID, (int) $document->SignerID);
        $this->assertNull($document->SelectedIDs);

        DocumentService::singleton()->regenerate($document, $this->olga);
        $this->assertStringContainsString($all, $this->renderer->lastHtml);
    }
}
