<?php

namespace Atwx\Flow\Document;

use Atwx\Mpdf\PdfRenderer as MpdfService;
use RuntimeException;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;

/**
 * Renders PDFs with atwx/silverstripe-mpdf (pure PHP, no browser).
 *
 * The profile is taken from the `profile` option, else from `profiles` by the
 * `context` option, else from `profiles.default`. The profiles themselves are
 * configured on Atwx\Mpdf\MpdfRenderer (flow ships flow_document and
 * flow_receipt), so a project adds fonts, CSS or letter paper there.
 * The document name becomes the PDF title unless `title` is given.
 */
class MpdfPdfRenderer implements PdfRenderer
{
    use Configurable;
    use Injectable;

    /**
     * mPDF profile per context; null or '' means no profile.
     *
     * @config
     * @var array<string, string|null>
     */
    private static array $profiles = [
        'default' => null,
        'document' => 'flow_document',
        'receipt' => 'flow_receipt',
    ];

    public function render(string $html, string $name, array $options = []): string
    {
        if (!interface_exists(MpdfService::class)) {
            throw new RuntimeException('PDF rendering needs atwx/silverstripe-mpdf.');
        }
        $options = $this->mpdfOptions($name, $options);
        $pdf = Injector::inst()->get(MpdfService::class)->renderHtml($html, $options);
        if ($pdf === '') {
            throw new RuntimeException('The PDF could not be created.');
        }
        return $pdf;
    }

    /**
     * Options for Atwx\Mpdf\MpdfRenderer::renderHtml().
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function mpdfOptions(string $name, array $options = []): array
    {
        $context = $options['context'] ?? null;
        unset($options['context']);
        if (!array_key_exists('profile', $options)) {
            $profiles = (array) static::config()->get('profiles');
            $options['profile'] = is_string($context) && array_key_exists($context, $profiles)
                ? $profiles[$context]
                : ($profiles['default'] ?? null);
        }
        if (empty($options['profile'])) {
            unset($options['profile']);
        }
        if (!isset($options['title']) && $name !== '') {
            $options['title'] = $name;
        }
        return $options;
    }
}
