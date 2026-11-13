<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Document\PdfRenderer;
use Atwx\Flow\Document\Placeholders;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\MailTemplate;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Member;
use SilverStripe\View\SSViewer;

/**
 * Creates documents (PDF) of a case file from its workflow's templates.
 *
 * The body is rendered into the template "Atwx\Flow\Document\FlowDocument"
 * (projects override it for their letterhead), turned into a PDF by the
 * PdfRenderer and stored protected with the case file.
 */
class DocumentService
{
    use Configurable;
    use Extensible;
    use Injectable;

    /**
     * Templates of the case file's workflow.
     *
     * @return array<int, DocumentTemplate>
     */
    public function templatesFor(DataObject $case): array
    {
        $workflow = Workflow::forClass($case::class);
        return $workflow ? DocumentTemplate::get()->filter('WorkflowID', $workflow->ID)->toArray() : [];
    }

    /**
     * The full HTML of a document (letterhead template with the filled body).
     *
     * The template gets Title, Body, Case, Template, Date, Signer, FreeText and
     * Selected, plus the case file's flowDocumentData($template, $signer) if it
     * has that method and what DocumentService extensions add in
     * updateFlowDocumentData(array &$data, DataObject $case, DocumentTemplate $template).
     *
     * @param array{FreeText?: string, SelectedIDs?: array<int, int>|null, SignerID?: int} $input
     */
    public function html(DataObject $case, DocumentTemplate $template, ?Member $by, array $input = []): string
    {
        $context = $this->context($case, $template, $by, $input);
        $body = Placeholders::singleton()->replace((string) $template->Body, $case, $by, true, null, $context);
        $data = [
            'Title' => $this->title($case, $template, $by, $context),
            'Body' => DBHTMLText::create()->setValue($body),
            'Case' => $case,
            'Template' => $template,
            'Date' => date('d.m.Y'),
            'Signer' => $context['Signer'],
            'FreeText' => $context['FreeText'],
            'Selected' => $context['Selected'],
        ];
        if ($case->hasMethod('flowDocumentData')) {
            $data = array_merge($data, (array) $case->flowDocumentData($template, $context['Signer']));
        }
        $this->extend('updateFlowDocumentData', $data, $case, $template);
        return (string) SSViewer::create('Atwx\\Flow\\Document\\FlowDocument')->process(ArrayData::create($data));
    }

    /**
     * Creates the document. $input holds the dialog's inputs: FreeText,
     * SelectedIDs (null = all entries of the template's Select relation) and
     * SignerID (0 = default signer).
     *
     * @param array{FreeText?: string, SelectedIDs?: array<int, int>|null, SignerID?: int} $input
     */
    public function create(DataObject $case, DocumentTemplate $template, ?Member $by, array $input = []): CaseDocument
    {
        $this->assertTemplateOf($case, $template);
        $document = CaseDocument::create([
            'CaseClass' => $case->ClassName,
            'CaseID' => $case->ID,
            'TemplateID' => $template->ID,
            'MemberID' => (int) $by?->ID,
        ]);
        return $this->render($document, $case, $template, $by, $input);
    }

    /**
     * Creates the PDF of an existing document again from the current data,
     * with new inputs or (null) the ones it was created with.
     *
     * @param array{FreeText?: string, SelectedIDs?: array<int, int>|null, SignerID?: int}|null $input
     */
    public function regenerate(CaseDocument $document, ?Member $by, ?array $input = null): CaseDocument
    {
        $case = $document->Case();
        $template = $document->Template();
        if (!$case->exists() || !$template->exists()) {
            throw new TransitionException('The document has no case file or template any more.');
        }
        $this->assertTemplateOf($case, $template);
        $input ??= $this->inputOf($document);
        if ($document->File->exists()) {
            $document->File->deleteFile();
        }
        return $this->render($document, $case, $template, $by, $input);
    }

    /**
     * The inputs a document was created with.
     *
     * @return array{FreeText: string, SelectedIDs: array<int, int>|null, SignerID: int}
     */
    public function inputOf(CaseDocument $document): array
    {
        return [
            'FreeText' => (string) $document->FreeText,
            'SelectedIDs' => $document->SelectedIDs === null ? null : $document->selectedIDs(),
            'SignerID' => (int) $document->SignerID,
        ];
    }

    /**
     * Entries of the template's Select relation that can be chosen.
     *
     * @return iterable<DataObject>
     */
    public function selectable(DataObject $case, DocumentTemplate $template): iterable
    {
        $relation = $template->selectRelation();
        if ($relation === null || !MailTemplate::isListRelation($case::class, $relation)) {
            return [];
        }
        return $case->$relation();
    }

    /**
     * The member signing by default: the one responsible in the template's
     * SignerResponsibility, else the member creating the document.
     */
    public function defaultSigner(DataObject $case, DocumentTemplate $template, ?Member $by): ?Member
    {
        $responsibility = $template->signerResponsibility();
        $assignee = $responsibility ? AssignmentService::singleton()->assignee($case, $responsibility) : null;
        return $assignee ?? $by;
    }

    /**
     * Members who can be chosen as signer: candidates of the workflow's
     * responsibilities, the default signer and the current member.
     *
     * @return array<int, string> ID => name
     */
    public function signerOptions(DataObject $case, DocumentTemplate $template, ?Member $by): array
    {
        $options = [];
        foreach (Responsibility::get()->filter('WorkflowID', (int) $template->WorkflowID) as $responsibility) {
            $options += $responsibility->candidateOptions();
        }
        foreach ([$this->defaultSigner($case, $template, $by), $by] as $member) {
            if ($member) {
                $options[(int) $member->ID] ??= (string) $member->getName();
            }
        }
        asort($options);
        return $options;
    }

    /**
     * @param array{FreeText?: string, SelectedIDs?: array<int, int>|null, SignerID?: int} $input
     */
    private function render(
        CaseDocument $document,
        DataObject $case,
        DocumentTemplate $template,
        ?Member $by,
        array $input
    ): CaseDocument {
        $context = $this->context($case, $template, $by, $input);
        $title = $this->title($case, $template, $by, $context);
        $pdf = Injector::inst()->get(PdfRenderer::class)->render($this->html($case, $template, $by, $input), $title);
        $document->Title = $title;
        $document->FreeText = $context['FreeText'];
        $document->SignerID = (int) $context['Signer']?->ID;
        if (array_key_exists('SelectedIDs', $input) && $input['SelectedIDs'] !== null) {
            $document->setSelectedIDList($input['SelectedIDs']);
        } else {
            $document->SelectedIDs = null;
        }
        $fileName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $title) ?: 'document';
        $document->File->setFromString(
            $pdf,
            'flow-documents/' . $fileName . '.pdf',
            null,
            null,
            ['visibility' => AssetStore::VISIBILITY_PROTECTED]
        );
        $document->write();
        return $document;
    }

    /**
     * @param array{FreeText?: string, SelectedIDs?: array<int, int>|null, SignerID?: int} $input
     * @return array{FreeText: string, Selected: iterable<DataObject>, Signer: ?Member}
     */
    private function context(DataObject $case, DocumentTemplate $template, ?Member $by, array $input): array
    {
        $selected = $this->selectable($case, $template);
        $ids = $input['SelectedIDs'] ?? null;
        if ($ids !== null && $selected instanceof DataList) {
            $selected = $selected->filter('ID', $ids ?: [0]);
        }
        $signerID = (int) ($input['SignerID'] ?? 0);
        $signer = $signerID && isset($this->signerOptions($case, $template, $by)[$signerID])
            ? Member::get()->byID($signerID)
            : $this->defaultSigner($case, $template, $by);
        return [
            'FreeText' => $template->FreeText ? trim((string) ($input['FreeText'] ?? '')) : '',
            'Selected' => $selected,
            'Signer' => $signer,
        ];
    }

    private function assertTemplateOf(DataObject $case, DocumentTemplate $template): void
    {
        $workflow = Workflow::forClass($case::class);
        if (!$case->isInDB() || !$workflow || (int) $template->WorkflowID !== (int) $workflow->ID) {
            throw new TransitionException('The template does not belong to this case file.');
        }
    }

    /**
     * @param array{FreeText: string, Selected: iterable<DataObject>, Signer: ?Member} $context
     */
    private function title(DataObject $case, DocumentTemplate $template, ?Member $by, array $context): string
    {
        $pattern = trim((string) $template->DocumentTitle) ?: (string) $template->Title;
        return trim(Placeholders::singleton()->replace($pattern, $case, $by, false, null, $context))
            ?: (string) $template->Title;
    }
}
