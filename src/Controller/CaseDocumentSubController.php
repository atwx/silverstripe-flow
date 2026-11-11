<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\CaseDocument;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Documents of a case file: list and download (PDF). Documents are created
 * with the record action "Create document" and cannot be edited.
 */
class CaseDocumentSubController extends FrontdeskSubController
{
    private static string $managed_model = CaseDocument::class;

    private static string $url_segment = 'documents';

    private static string $title = 'Documents';

    private static array $allowed_actions = [
        'download',
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
     * Documents are only created, never edited here.
     *
     * @param Member|null $member
     */
    public function canEdit($member = null): bool
    {
        return false;
    }

    protected function defineColumns(): ColumnCollection
    {
        return ColumnCollection::fromSummaryFields(CaseDocument::class)
            ->make('Title', _t(CaseDocument::class . '.db_Title', 'Title'))->link('download/{ID}')->end();
    }

    protected function defineRowActions(DataObject $record): array
    {
        return [];
    }

    /**
     * Streams the PDF of a document of this case file.
     */
    public function download(HTTPRequest $request): HTTPResponse
    {
        $parent = $this->parentController;
        if (!$parent || !$parent->canView() || !$this->parentRecordIsVisible()) {
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

    /**
     * The case file must be in the parent controller's list (its permissions).
     */
    private function parentRecordIsVisible(): bool
    {
        $parent = $this->parentController;
        if (!$parent instanceof FlowCaseController) {
            return false;
        }
        return $parent->findCaseFile($this->getParentID()) !== null;
    }
}
