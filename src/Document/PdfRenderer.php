<?php

namespace Atwx\Flow\Document;

/**
 * Turns an HTML document into a PDF (bound in the Injector, replaceable).
 *
 * Default: MpdfPdfRenderer (atwx/silverstripe-mpdf). HeadlessPdfRenderer
 * (atwx/silverstripe-headless-pdf, headless Chrome) remains as alternative.
 */
interface PdfRenderer
{
    /**
     * Options are hints for the renderer; implementations ignore what they do
     * not know. Flow passes `context` ('document' for case documents,
     * 'receipt' for intake receipts). MpdfPdfRenderer also takes `profile`
     * and any option of Atwx\Mpdf\MpdfRenderer.
     *
     * @param array<string, mixed> $options
     * @return string PDF content
     */
    public function render(string $html, string $name, array $options = []): string;
}
