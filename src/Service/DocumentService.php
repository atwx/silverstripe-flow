<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Document\PdfRenderer;
use Atwx\Flow\Document\Placeholders;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\ArrayData;
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
     */
    public function html(DataObject $case, DocumentTemplate $template, ?Member $by): string
    {
        $placeholders = Placeholders::singleton();
        $body = $placeholders->replace((string) $template->Body, $case, $by);
        return (string) SSViewer::create('Atwx\\Flow\\Document\\FlowDocument')->process(ArrayData::create([
            'Title' => $this->title($case, $template, $by),
            'Body' => DBHTMLText::create()->setValue($body),
            'Case' => $case,
            'Template' => $template,
            'Date' => date('d.m.Y'),
        ]));
    }

    public function create(DataObject $case, DocumentTemplate $template, ?Member $by): CaseDocument
    {
        $workflow = Workflow::forClass($case::class);
        if (!$case->isInDB() || !$workflow || (int) $template->WorkflowID !== (int) $workflow->ID) {
            throw new TransitionException('The template does not belong to this case file.');
        }
        $title = $this->title($case, $template, $by);
        $pdf = Injector::inst()->get(PdfRenderer::class)->render($this->html($case, $template, $by), $title);

        $document = CaseDocument::create([
            'Title' => $title,
            'CaseClass' => $case->ClassName,
            'CaseID' => $case->ID,
            'TemplateID' => $template->ID,
            'MemberID' => (int) $by?->ID,
        ]);
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

    private function title(DataObject $case, DocumentTemplate $template, ?Member $by): string
    {
        $pattern = trim((string) $template->DocumentTitle) ?: (string) $template->Title;
        return trim(Placeholders::singleton()->replace($pattern, $case, $by, false)) ?: (string) $template->Title;
    }
}
