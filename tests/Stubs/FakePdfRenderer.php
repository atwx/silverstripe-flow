<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Document\PdfRenderer;
use SilverStripe\Dev\TestOnly;

/**
 * Returns the HTML as "PDF", so tests run without a browser.
 */
class FakePdfRenderer implements PdfRenderer, TestOnly
{
    public string $lastHtml = '';

    public function render(string $html, string $name): string
    {
        $this->lastHtml = $html;
        return '%PDF-fake ' . $name;
    }
}
