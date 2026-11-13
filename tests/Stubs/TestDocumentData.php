<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Model\DocumentTemplate;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Adds letterhead data to documents (updateFlowDocumentData).
 *
 * @extends Extension<\Atwx\Flow\Service\DocumentService>
 */
class TestDocumentData extends Extension implements TestOnly
{
    /**
     * @param array<string, mixed> $data
     */
    protected function updateFlowDocumentData(array &$data, DataObject $case, DocumentTemplate $template): void
    {
        $data['Title'] = 'Hook: ' . $template->Title;
    }
}
