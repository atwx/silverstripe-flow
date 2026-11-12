<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\MailDownload;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Download of a file sent as a link with a mail: flow/download/<token>,
 * without login. Unknown links answer 404, expired links 410. Every download
 * is counted.
 */
class MailDownloadController extends Controller
{
    private static array $url_handlers = [
        '$Key!' => 'download',
    ];

    private static array $allowed_actions = [
        'download',
    ];

    public function download(HTTPRequest $request): HTTPResponse
    {
        $download = MailDownload::findByToken((string) $request->param('Key'));
        $served = $download?->servedFile();
        if (!$download || !$served) {
            return $this->message(404, _t(self::class . '.NOT_FOUND', 'This download does not exist (any more).'));
        }
        if ($download->isExpired()) {
            return $this->message(410, _t(
                self::class . '.EXPIRED',
                'This download link has expired. Please ask the sender for a new one.'
            ));
        }
        $download->Downloads = (int) $download->Downloads + 1;
        $download->LastDownloadAt = DBDatetime::now()->Rfc2822();
        $download->write();

        [$file, $name] = $served;
        $stream = $file->getStream();
        if (!is_resource($stream)) {
            return $this->message(404, _t(self::class . '.NOT_FOUND', 'This download does not exist (any more).'));
        }
        $response = HTTPStreamResponse::create($stream, (int) $file->getAbsoluteSize());
        $response->addHeader('Content-Type', $file->getMimeType() ?: 'application/octet-stream');
        $response->addHeader('Content-Disposition', 'attachment; filename="' . addcslashes($name, '"\\') . '"');
        $response->addHeader('X-Robots-Tag', 'noindex');
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    private function message(int $status, string $text): HTTPResponse
    {
        $response = HTTPResponse::create(
            '<!doctype html><meta charset="utf-8"><meta name="robots" content="noindex"><title>'
            . htmlspecialchars($text, ENT_QUOTES) . '</title><p style="font-family:sans-serif;margin:2rem">'
            . htmlspecialchars($text, ENT_QUOTES) . '</p>',
            $status
        );
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }
}
