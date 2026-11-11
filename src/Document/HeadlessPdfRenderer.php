<?php

namespace Atwx\Flow\Document;

use Atwx\HeadlessPDF\Services\HeadlessPDFService;
use RuntimeException;

/**
 * Renders PDFs with atwx/silverstripe-headless-pdf (headless Chrome). The HTML
 * is written to a temporary file and printed from there, so no case data has
 * to be served through a URL.
 */
class HeadlessPdfRenderer implements PdfRenderer
{
    public function render(string $html, string $name): string
    {
        if (!class_exists(HeadlessPDFService::class)) {
            throw new RuntimeException('PDF rendering needs atwx/silverstripe-headless-pdf.');
        }
        $file = tempnam(sys_get_temp_dir(), 'flow-document-');
        if ($file === false) {
            throw new RuntimeException('Could not create a temporary file.');
        }
        $htmlFile = $file . '.html';
        rename($file, $htmlFile);
        try {
            file_put_contents($htmlFile, $html);
            $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name) ?: 'document';
            $pdf = HeadlessPDFService::create()->generatePdf('file://' . $htmlFile, $safeName . '-' . uniqid());
        } finally {
            @unlink($htmlFile);
        }
        if (!is_string($pdf) || $pdf === '') {
            throw new RuntimeException('The PDF could not be created.');
        }
        return $pdf;
    }
}
