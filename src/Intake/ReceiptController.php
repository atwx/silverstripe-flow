<?php

namespace Atwx\Flow\Intake;

use Psr\Log\LoggerInterface;
use SilverStripe\Control\Controller;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use Throwable;

/**
 * flow/receipt/<token>: the receipt PDF of a submission for the submitter
 * (e.g. on the thank-you page), without login. The token is random, stored
 * only as a hash and valid for a few hours (IntakeService::receiptLink()).
 */
class ReceiptController extends Controller
{
    private static array $url_handlers = [
        '$Token!' => 'receipt',
    ];

    private static array $allowed_actions = [
        'receipt',
    ];

    public function receipt(HTTPRequest $request): HTTPResponse
    {
        $service = IntakeService::singleton();
        $submission = $service->submissionByReceiptToken((string) $request->param('Token'));
        if (!$submission) {
            return $this->message(404, _t(self::class . '.EXPIRED', 'This link is no longer valid.'));
        }
        try {
            $pdf = $service->receiptPdf($submission);
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error(
                'Flow intake: receipt ' . $submission->Reference . ' failed: ' . $e->getMessage()
            );
            return $this->message(500, _t(self::class . '.FAILED', 'The receipt could not be created.'));
        }
        $response = HTTPResponse::create($pdf);
        $response->addHeader('Content-Type', 'application/pdf');
        $response->addHeader('Content-Disposition', 'inline; filename="' . $submission->Reference . '.pdf"');
        $response->addHeader('Cache-Control', 'private, no-store');
        $response->addHeader('X-Robots-Tag', 'noindex');
        return $response;
    }

    private function message(int $status, string $text): HTTPResponse
    {
        $response = HTTPResponse::create(htmlspecialchars($text), $status);
        $response->addHeader('Content-Type', 'text/plain; charset=utf-8');
        $response->addHeader('Cache-Control', 'private, no-store');
        $response->addHeader('X-Robots-Tag', 'noindex');
        return $response;
    }
}
