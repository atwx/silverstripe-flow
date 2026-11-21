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

    /** @var array<string, mixed> */
    public array $lastOptions = [];

    public function render(string $html, string $name, array $options = []): string
    {
        $this->lastHtml = $html;
        $this->lastOptions = $options;
        return '%PDF-fake ' . $name;
    }
}
