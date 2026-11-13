<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Service\DocumentService;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\RowAction;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Documents of a case file: list, download (PDF), the dialog to create a
 * document (compose/<case ID>?template=<ID>: free text, chosen entries,
 * signer, HTML preview) and to edit one (compose/<case ID>?document=<ID>:
 * same inputs, the PDF is created again from the current data). Documents can
 * be deleted by the same members.
 */
class CaseDocumentSubController extends FrontdeskSubController
{
    private static string $managed_model = CaseDocument::class;

    private static string $url_segment = 'documents';

    private static string $title = 'Schreiben';

    private static bool $allow_add = false;

    private static array $allowed_actions = [
        'download',
        'compose' => '->canWriteDocuments',
        'DocumentForm' => '->canWriteDocuments',
        'delete' => '->canWriteDocuments',
    ];

    /**
     * @return DataList<CaseDocument>
     */
    protected function getBaseQuery(): DataList
    {
        // PHPStan reports static<T> from DataList::filter() as incompatible with DataList<T>.
        // @phpstan-ignore return.type
        return CaseDocument::get()->filter([
            'CaseClass' => $this->parentController?->getManagedModel(),
            'CaseID' => $this->getParentID(),
        ]);
    }

    /**
     * Documents are not edited through the generic form, only through the dialog.
     *
     * @param Member|null $member
     */
    public function canEdit($member = null): bool
    {
        return false;
    }

    /**
     * Creating, editing and deleting documents: see FlowCaseController::mayWriteDocuments().
     */
    public function canWriteDocuments(): bool
    {
        $parent = $this->parentController;
        $case = $this->caseFile();
        return $parent instanceof FlowCaseController && $case !== null && $parent->mayWriteDocuments($case);
    }

    protected function defineColumns(): ColumnCollection
    {
        return ColumnCollection::create()
            ->make('Title', _t(CaseDocument::class . '.db_Title', 'Title'))->link('download/{ID}')->end()
            ->make('Created.Nice', _t(CaseDocument::class . '.CREATED', 'Created'))->end()
            ->make('Member.Name', _t(CaseDocument::class . '.has_one_Member', 'By'))->end();
    }

    protected function defineRowActions(DataObject $record): array
    {
        if (!$record instanceof CaseDocument || !$record->Template()->exists() || !$this->canWriteDocuments()) {
            return [];
        }
        return [
            RowAction::link(
                _t(self::class . '.EDIT', 'Edit'),
                $this->Link('compose/' . $this->getParentID()) . '?document=' . $record->ID
            ),
            RowAction::delete($this->Link('delete/' . $record->ID)),
        ];
    }

    /**
     * Streams the PDF of a document of this case file.
     */
    public function download(HTTPRequest $request): HTTPResponse
    {
        $parent = $this->parentController;
        if (!$parent || !$parent->canView() || !$this->caseFile()) {
            return $this->httpError(404);
        }
        $document = $this->getBaseQuery()->byID((int) $request->param('ID'));
        if (!$document || !$document->File->exists()) {
            return $this->httpError(404);
        }
        $name = (preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $document->Title) ?: 'document') . '.pdf';
        $response = HTTPResponse::create((string) $document->File->getString(), 200);
        $response->addHeader('Content-Type', 'application/pdf');
        $response->addHeader('Content-Disposition', 'inline; filename="' . $name . '"');
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    public function compose(HTTPRequest $request): DBHTMLText|HTTPResponse
    {
        $template = $this->requestedTemplate();
        if (!$template) {
            return $this->httpError(404);
        }
        $document = $this->requestedDocument();
        $title = $document
            ? _t(self::class . '.EDIT_TITLE', 'Edit document: {title}', ['title' => $document->Title])
            : _t(self::class . '.COMPOSE_TITLE', 'Document: {template}', ['template' => $template->Title]);
        return $this->getViewer('edit')->process($this->customise([
            'Title' => $title,
            'Form' => $this->documentForm(),
            'Actions' => false,
        ]));
    }

    public function documentForm(): Form
    {
        $case = $this->caseFile();
        $template = $this->requestedTemplate();
        $document = $this->requestedDocument();
        $service = DocumentService::singleton();
        $me = Security::getCurrentUser();

        $fields = FieldList::create(
            HiddenField::create('TemplateID', 'TemplateID', (int) $template?->ID),
            HiddenField::create('DocumentID', 'DocumentID', (int) $document?->ID)
        );
        $entries = [];
        if ($case && $template && $template->selectRelation() !== null) {
            foreach ($service->selectable($case, $template) as $entry) {
                $entries[(int) $entry->ID] = (string) $entry->getTitle();
            }
            $field = CheckboxSetField::create('Entries', _t(self::class . '.ENTRIES', 'Entries'), $entries);
            if (!$entries) {
                $field->setDescription(_t(self::class . '.NO_ENTRIES', 'The list is empty.'));
            }
            $fields->push($field);
        }
        if ($template?->FreeText) {
            $fields->push(TextareaField::create('FreeText', _t(self::class . '.FREE_TEXT', 'Free text'))->setRows(8));
        }
        if ($case && $template) {
            $fields->push(DropdownField::create(
                'SignerID',
                _t(self::class . '.SIGNER', 'Signed by'),
                $service->signerOptions($case, $template, $me)
            ));
        }

        $actions = FieldList::create(
            FormAction::create('doCreate', $document
                ? _t(self::class . '.SAVE', 'Save and create again')
                : _t(self::class . '.CREATE', 'Create')),
            FormAction::create('doPreview', _t(self::class . '.PREVIEW', 'Preview'))
                ->addExtraClass('btn-ghost')
                ->setAttribute('formtarget', '_blank'),
            LiteralField::create(
                'Cancel',
                '<a href="' . htmlspecialchars($this->caseLink(), ENT_QUOTES) . '" class="btn">'
                . _t(self::class . '.CANCEL', 'Cancel') . '</a>'
            )
        );
        $form = Form::create($this, 'DocumentForm', $fields, $actions);
        $form->setValidationResponseCallback(fn () => $this->redirectBack());

        if ($case && $template && !$this->getRequest()->isPOST()) {
            $input = $document
                ? $service->inputOf($document)
                : ['FreeText' => '', 'SelectedIDs' => null, 'SignerID' => 0];
            $form->loadDataFrom([
                'Entries' => $input['SelectedIDs'] ?? array_keys($entries),
                'FreeText' => $input['FreeText'],
                'SignerID' => $input['SignerID'] ?: (int) $service->defaultSigner($case, $template, $me)?->ID,
            ]);
        }
        return $form;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doCreate(array $data, Form $form): HTTPResponse
    {
        $case = $this->caseFile();
        $template = $this->requestedTemplate();
        if (!$case || !$template) {
            return $this->httpError(404);
        }
        $service = DocumentService::singleton();
        $me = Security::getCurrentUser();
        $document = $this->requestedDocument();
        $input = $this->inputFrom($data, $template);
        if ($document) {
            $service->regenerate($document, $me, $input);
            $this->flash(_t(
                self::class . '.SAVED',
                'Document "{title}" created again.',
                ['title' => $document->Title]
            ));
        } else {
            $document = $service->create($case, $template, $me, $input);
            $this->flash(_t(
                FlowCaseController::class . '.DOCUMENT_CREATED',
                'Document "{title}" created.',
                ['title' => $document->Title]
            ));
        }
        return $this->redirect($this->caseLink());
    }

    /**
     * The document as HTML with the current inputs (opened in a new tab).
     *
     * @param array<string, mixed> $data
     */
    public function doPreview(array $data, Form $form): HTTPResponse
    {
        $case = $this->caseFile();
        $template = $this->requestedTemplate();
        if (!$case || !$template) {
            return $this->httpError(404);
        }
        $input = $this->inputFrom($data, $template);
        $html = DocumentService::singleton()->html($case, $template, Security::getCurrentUser(), $input);
        $response = HTTPResponse::create($html, 200);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');
        $response->addHeader('Cache-Control', 'private, no-store');
        $response->addHeader('X-Robots-Tag', 'noindex');
        // The preview runs no scripts.
        $response->addHeader('Content-Security-Policy', "script-src 'none'");
        return $response;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{FreeText: string, SelectedIDs: array<int, int>|null, SignerID: int}
     */
    private function inputFrom(array $data, DocumentTemplate $template): array
    {
        $entries = $data['Entries'] ?? [];
        return [
            'FreeText' => (string) ($data['FreeText'] ?? ''),
            'SelectedIDs' => $template->selectRelation() !== null
                ? array_values(array_map('intval', is_array($entries) ? $entries : []))
                : null,
            'SignerID' => (int) ($data['SignerID'] ?? 0),
        ];
    }

    private function requestedTemplate(): ?DocumentTemplate
    {
        $document = $this->requestedDocument();
        if ($document) {
            return $document->Template()->exists() ? $document->Template() : null;
        }
        $request = $this->getRequest();
        $id = (int) ($request->postVar('TemplateID') ?: $request->getVar('template'));
        $case = $this->caseFile();
        if (!$id || !$case) {
            return null;
        }
        foreach (DocumentService::singleton()->templatesFor($case) as $template) {
            if ((int) $template->ID === $id) {
                return $template;
            }
        }
        return null;
    }

    private function requestedDocument(): ?CaseDocument
    {
        $request = $this->getRequest();
        $id = (int) ($request->postVar('DocumentID') ?: $request->getVar('document'));
        return $id && $this->caseFile() ? $this->getBaseQuery()->byID($id) : null;
    }

    private function caseFile(): ?DataObject
    {
        $parent = $this->parentController;
        return $parent instanceof FlowCaseController ? $parent->findCaseFile($this->getParentID()) : null;
    }

    private function caseLink(): string
    {
        return (string) $this->parentController?->Link('view/' . $this->getParentID());
    }
}
