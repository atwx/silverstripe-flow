<?php

namespace Atwx\Flow\Tests\Document;

use Atwx\Flow\Document\MpdfPdfRenderer;
use Atwx\Flow\Document\PdfRenderer;
use Atwx\Mpdf\MpdfRenderer;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Model\ArrayData;

class MpdfPdfRendererTest extends SapphireTest
{
    public function testIsTheDefaultRenderer(): void
    {
        $this->assertInstanceOf(MpdfPdfRenderer::class, Injector::inst()->get(PdfRenderer::class));
    }

    public function testProfileFollowsTheContext(): void
    {
        $renderer = MpdfPdfRenderer::create();

        $this->assertSame(
            ['profile' => 'flow_document', 'title' => 'Brief'],
            $renderer->mpdfOptions('Brief', ['context' => 'document'])
        );
        $this->assertSame('flow_receipt', $renderer->mpdfOptions('X', ['context' => 'receipt'])['profile']);
        $this->assertArrayNotHasKey('profile', $renderer->mpdfOptions('X'), 'no default profile');
        $this->assertArrayNotHasKey('profile', $renderer->mpdfOptions('X', ['context' => 'unknown']));

        $options = $renderer->mpdfOptions('X', ['context' => 'document', 'profile' => 'letter', 'title' => 'T']);
        $this->assertSame('letter', $options['profile'], 'an explicit profile wins');
        $this->assertSame('T', $options['title']);

        Config::modify()->merge(MpdfPdfRenderer::class, 'profiles', ['default' => 'plain', 'document' => null]);
        $this->assertSame('plain', $renderer->mpdfOptions('X')['profile']);
        $this->assertArrayNotHasKey('profile', $renderer->mpdfOptions('X', ['context' => 'document']));
    }

    public function testFlowProfilesExist(): void
    {
        $profiles = Config::inst()->get(MpdfRenderer::class, 'profiles');
        $this->assertArrayHasKey('flow_document', $profiles);
        $this->assertArrayHasKey('flow_receipt', $profiles);
    }

    public function testRendersTheFlowTemplatesToPdf(): void
    {
        Config::modify()->set(MpdfRenderer::class, 'temp_dir', TEMP_PATH . '/flow-mpdf-test');
        $html = (string) ArrayData::create([
            'Title' => 'Bestätigung',
            'Date' => '21.11.2026',
            'Body' => '<h1>Bestätigung</h1><p>Größe: ÄÖÜ ß – „Zitat“</p>',
        ])->renderWith('Atwx\\Flow\\Document\\FlowDocument');

        $pdf = Injector::inst()->get(PdfRenderer::class)->render($html, 'Bestätigung', ['context' => 'document']);

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertStringContainsString('/Count 1', $pdf, 'one page');
    }
}
