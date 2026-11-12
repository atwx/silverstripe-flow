<?php

namespace Atwx\Flow\Service;

use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * A mail before it is sent: filled from a template, edited in the mail form.
 */
class MailDraft
{
    use Injectable;

    public int $templateID = 0;

    /**
     * @var array<int, string>
     */
    public array $to = [];

    /**
     * @var array<int, string>
     */
    public array $cc = [];

    public string $replyTo = '';

    public string $subject = '';

    /**
     * Recipient expressions that gave no valid address (shown in the mail form).
     *
     * @var array<int, string>
     */
    public array $skipped = [];

    public string $body = '';

    /**
     * Document templates whose PDFs are created and attached.
     *
     * @var array<int, int>
     */
    public array $documentTemplateIDs = [];

    /**
     * Existing documents of the case file to attach.
     *
     * @var array<int, int>
     */
    public array $documentIDs = [];

    /**
     * Files of the mail template (asset library) to attach.
     *
     * @var array<int, int>
     */
    public array $fileIDs = [];

    /**
     * Send the attachments as download links in the text instead of attaching them.
     */
    public bool $asLinks = false;

    /**
     * The sub-list entry of a template with ForEach (recorded with the mail).
     */
    public ?DataObject $item = null;
}
