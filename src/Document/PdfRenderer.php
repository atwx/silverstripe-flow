<?php

namespace Atwx\Flow\Document;

/**
 * Turns an HTML document into a PDF (bound in the Injector, replaceable).
 */
interface PdfRenderer
{
    /**
     * @return string PDF content
     */
    public function render(string $html, string $name): string;
}
