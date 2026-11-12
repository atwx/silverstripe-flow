<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Document\Placeholders;
use Atwx\Flow\Mail\RecipientResolver;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\CaseMail;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\MailDownload;
use Atwx\Flow\Model\MailTemplate;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Workflow;
use Psr\Log\LoggerInterface;
use SilverStripe\Assets\File;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Mails of case files: drafts from templates, sending (errors are logged and
 * recorded, never thrown), mail files for the member's mail program and
 * automatic mails when a case file enters a status. Every mail is recorded as
 * CaseMail with its full text.
 *
 * The transport is Silverstripe's mailer (Symfony Mailer, MAILER_DSN), so it can
 * be swapped (SMTP, Microsoft Graph, ...) without changes here.
 */
class MailService
{
    use Configurable;
    use Injectable;

    /**
     * Sender address; default: Email.admin_email.
     */
    private static ?string $from = null;

    /**
     * Depth of withoutAutomaticMails() calls.
     */
    protected static int $suppressed = 0;

    /**
     * Runs the callback without automatic mails (status changes and new case
     * files), e.g. for imports that create records through the normal write.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public static function withoutAutomaticMails(callable $callback): mixed
    {
        static::$suppressed++;
        try {
            return $callback();
        } finally {
            static::$suppressed--;
        }
    }

    /**
     * Templates of the case file's workflow.
     *
     * @return array<int, MailTemplate>
     */
    public function templatesFor(DataObject $case): array
    {
        $workflow = Workflow::forClass($case::class);
        return $workflow ? MailTemplate::get()->filter('WorkflowID', $workflow->ID)->toArray() : [];
    }

    /**
     * Entries of the sub-list of a template with ForEach (empty otherwise).
     *
     * @return array<int, DataObject>
     */
    public function entries(DataObject $case, MailTemplate $template): array
    {
        $relation = $template->forEachRelation();
        if ($relation === null || !MailTemplate::isListRelation($case::class, $relation)) {
            return [];
        }
        $list = $case->$relation();
        return $list instanceof SS_List ? iterator_to_array($list, false) : [];
    }

    /**
     * Draft of a mail: recipients resolved, placeholders filled; attachments are
     * the template's document templates (by ID) and no existing documents. With
     * an entry of the sub-list, Item.<field> refers to it.
     */
    public function draft(DataObject $case, MailTemplate $template, ?Member $me, ?DataObject $item = null): MailDraft
    {
        $this->assertTemplateOf($case, $template);
        $resolver = RecipientResolver::singleton();
        $placeholders = Placeholders::singleton();
        $draft = MailDraft::create();
        $draft->templateID = (int) $template->ID;
        $draft->item = $item;
        $draft->to = $resolver->resolve((string) $template->Recipients, $case, $me, $skippedTo, $item);
        $draft->cc = $resolver->resolve((string) $template->Cc, $case, $me, $skippedCc, $item);
        $draft->replyTo = $resolver->resolve((string) $template->ReplyTo, $case, $me, $skippedReplyTo, $item)[0] ?? '';
        $draft->skipped = array_values(array_unique(array_merge(
            (array) $skippedTo,
            (array) $skippedCc,
            (array) $skippedReplyTo
        )));
        $draft->subject = trim($placeholders->replace((string) $template->Subject, $case, $me, false, $item));
        $draft->body = $placeholders->replace((string) $template->Body, $case, $me, false, $item);
        foreach ($template->attachmentTemplates() as $documentTemplate) {
            $draft->documentTemplateIDs[] = (int) $documentTemplate->ID;
        }
        $draft->fileIDs = array_map(fn (File $file) => (int) $file->ID, $template->attachedFiles());
        $draft->asLinks = (bool) $template->AsLinks;
        return $draft;
    }

    /**
     * One mail per entry of a template with ForEach. $base holds the texts as
     * edited in the mail form (placeholders still in them, filled per entry),
     * Cc, Reply-To and attachments; without it the template is used. PDFs of
     * document templates are created once and attached to every mail.
     *
     * @param array<int, DataObject> $entries
     * @return array<int, CaseMail>
     */
    public function sendEach(
        DataObject $case,
        MailTemplate $template,
        array $entries,
        ?Member $by,
        ?MailDraft $base = null,
        bool $automatic = false
    ): array {
        $placeholders = Placeholders::singleton();
        $documentIDs = $base ? $base->documentIDs : [];
        $templateIDs = $base ? $base->documentTemplateIDs : array_map(
            fn ($documentTemplate) => (int) $documentTemplate->ID,
            $template->attachmentTemplates()
        );
        $documents = DocumentService::singleton();
        foreach ($templateIDs as $id) {
            $documentTemplate = DocumentTemplate::get()->byID($id);
            if ($documentTemplate && $entries) {
                $documentIDs[] = (int) $documents->create($case, $documentTemplate, $by)->ID;
            }
        }
        $mails = [];
        foreach ($entries as $item) {
            $draft = $this->draft($case, $template, $by, $item);
            if ($base) {
                $draft->subject = trim($placeholders->replace($base->subject, $case, $by, false, $item));
                $draft->body = $placeholders->replace($base->body, $case, $by, false, $item);
                $draft->cc = $base->cc;
                $draft->replyTo = $base->replyTo;
                $draft->fileIDs = $base->fileIDs;
                $draft->asLinks = $base->asLinks;
            }
            $draft->documentTemplateIDs = [];
            $draft->documentIDs = $documentIDs;
            $mails[] = $this->send($case, $draft, $by, $automatic);
        }
        return $mails;
    }

    /**
     * Sends the mail. A failure (transport, invalid configuration) does not throw:
     * it is logged and recorded as a failed mail.
     */
    public function send(DataObject $case, MailDraft $draft, ?Member $by, bool $automatic = false): CaseMail
    {
        $mail = $this->record($case, $draft, $by, $automatic);
        try {
            if (!$draft->to) {
                throw new \RuntimeException(_t(self::class . '.NO_RECIPIENTS', 'There are no recipients.'));
            }
            $names = [];
            $email = $this->email($case, $draft, $by, $names, $mail);
            $mail->AttachmentNames = implode(', ', $names);
            $email->send();
            $mail->Mode = CaseMail::SENT;
        } catch (Throwable $e) {
            $mail->Mode = CaseMail::FAILED;
            $mail->Error = $e->getMessage();
            Injector::inst()->get(LoggerInterface::class)->error(
                'Flow mail to ' . implode(', ', $draft->to) . ' failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
        $mail->write();
        return $mail;
    }

    /**
     * The mail as a file (.eml) to open in the member's mail program; recorded as
     * a mail file (not sent).
     *
     * @return array{0: CaseMail, 1: string} The record and the raw mail
     */
    public function eml(DataObject $case, MailDraft $draft, ?Member $by): array
    {
        $mail = $this->record($case, $draft, $by, false);
        $names = [];
        $email = $this->email($case, $draft, $by, $names, $mail);
        // Opened as a draft by mail programs that support it (Outlook, Thunderbird).
        $email->getHeaders()->addTextHeader('X-Unsent', '1');
        $mail->AttachmentNames = implode(', ', $names);
        $mail->Mode = CaseMail::EML;
        $mail->write();
        return [$mail, $email->toString()];
    }

    /**
     * Automatic mails of templates sent when the case file enters a status
     * (called after the status change is committed, and after a new case file
     * is written in the initial status).
     */
    public function onStatusChange(DataObject $case, StatusChange $change, ?Member $by): void
    {
        $workflow = Workflow::forClass($case::class);
        if (static::$suppressed > 0 || !$workflow || !$change->ToID) {
            return;
        }
        $templates = MailTemplate::get()->filter([
            'WorkflowID' => $workflow->ID,
            'SendOnStatusID' => (int) $change->ToID,
        ]);
        foreach ($templates as $template) {
            if ($template->forEachRelation() !== null) {
                try {
                    $this->sendEach($case, $template, $this->entries($case, $template), $by, null, true);
                } catch (Throwable $e) {
                    Injector::inst()->get(LoggerInterface::class)->error('Flow mails failed: ' . $e->getMessage());
                }
                continue;
            }
            try {
                $draft = $this->draft($case, $template, $by);
            } catch (Throwable $e) {
                Injector::inst()->get(LoggerInterface::class)->error('Flow mail draft failed: ' . $e->getMessage());
                continue;
            }
            $this->send($case, $draft, $by, true);
        }
    }

    private function record(DataObject $case, MailDraft $draft, ?Member $by, bool $automatic): CaseMail
    {
        if (!$case->isInDB()) {
            throw new TransitionException('The case file must be saved first.');
        }
        return CaseMail::create([
            'CaseClass' => $case->ClassName,
            'CaseID' => $case->ID,
            'TemplateID' => $draft->templateID,
            'MemberID' => (int) $by?->ID,
            'Recipients' => implode(', ', $draft->to),
            'Cc' => implode(', ', $draft->cc),
            'ReplyTo' => $draft->replyTo,
            'Subject' => $draft->subject,
            'Body' => $draft->body,
            'Automatic' => $automatic,
            'ItemClass' => $draft->item ? $draft->item->ClassName : null,
            'ItemID' => (int) $draft->item?->ID,
        ]);
    }

    /**
     * The mail with its attachments: new PDFs of the chosen document templates
     * (stored as documents of the case file), chosen existing documents and
     * files of the template. With $draft->asLinks they are not attached: the
     * text gets a download link for each (the mail record is written first,
     * the links belong to it, and its body keeps the links).
     *
     * @param array<int, string> $names Set to the attachment names
     */
    private function email(DataObject $case, MailDraft $draft, ?Member $by, array &$names, CaseMail $mail): Email
    {
        $names = [];
        $documentList = [];
        $documents = DocumentService::singleton();
        foreach ($draft->documentTemplateIDs as $id) {
            $template = DocumentTemplate::get()->byID($id);
            if ($template) {
                $documentList[] = $documents->create($case, $template, $by);
            }
        }
        foreach ($draft->documentIDs as $id) {
            $document = CaseDocument::get()->filter([
                'ID' => $id,
                'CaseClass' => $case->ClassName,
                'CaseID' => $case->ID,
            ])->first();
            if ($document) {
                $documentList[] = $document;
            }
        }
        $files = $draft->fileIDs ? Versioned::withVersionedMode(function () use ($draft) {
            Versioned::set_stage(Versioned::DRAFT);
            return File::get()->filter('ID', $draft->fileIDs)->toArray();
        }) : [];

        $body = $draft->body;
        $links = [];
        if ($draft->asLinks && ($documentList || $files)) {
            if (!$mail->isInDB()) {
                $mail->write();
            }
            foreach ($documentList as $document) {
                $links[] = MailDownload::createFor($mail, $document, null);
            }
            foreach ($files as $file) {
                $links[] = MailDownload::createFor($mail, null, $file);
            }
            $body = rtrim($body) . "\n\n" . $this->linkBlock($links);
            $mail->Body = $body;
        }

        $email = Email::create()
            ->setTo($draft->to)
            ->setSubject($draft->subject)
            ->text($body)
            ->html(nl2br(htmlspecialchars($body, ENT_QUOTES), false));
        $from = (string) (static::config()->get('from') ?: Email::config()->get('admin_email'));
        if ($from !== '') {
            $email->setFrom($from);
        }
        if ($draft->cc) {
            $email->setCc($draft->cc);
        }
        if ($draft->replyTo !== '') {
            $email->setReplyTo($draft->replyTo);
        }
        if ($links) {
            foreach ($links as $link) {
                $names[] = $link->Title . ' (' . _t(self::class . '.LINK', 'link') . ')';
            }
            return $email;
        }
        foreach ($documentList as $document) {
            $names[] = $this->attach($email, $document);
        }
        foreach ($files as $file) {
            if ($file->File->exists()) {
                $email->addAttachmentFromData(
                    (string) $file->getString(),
                    (string) $file->Name,
                    $file->getMimeType() ?: 'application/octet-stream'
                );
                $names[] = (string) $file->Name;
            }
        }
        return $email;
    }

    /**
     * Text block with the download links of a mail.
     *
     * @param array<int, MailDownload> $links
     */
    private function linkBlock(array $links): string
    {
        $expires = $links[0]->ExpiresAt ?? null;
        $lines = [$expires
            ? _t(
                self::class . '.LINKS_UNTIL',
                'Downloads (until {date}):',
                ['date' => date('d.m.Y', (int) strtotime((string) $expires))]
            )
            : _t(self::class . '.LINKS', 'Downloads:')];
        foreach ($links as $link) {
            $lines[] = '- ' . $link->Title . ': ' . $link->url();
        }
        return implode("\n", $lines) . "\n";
    }

    private function attach(Email $email, CaseDocument $document): string
    {
        $name = (preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $document->Title) ?: 'document') . '.pdf';
        $email->addAttachmentFromData((string) $document->File->getString(), $name, 'application/pdf');
        return $name;
    }

    private function assertTemplateOf(DataObject $case, MailTemplate $template): void
    {
        $workflow = Workflow::forClass($case::class);
        if (!$workflow || (int) $template->WorkflowID !== (int) $workflow->ID) {
            throw new TransitionException('The template does not belong to this case file.');
        }
    }
}
