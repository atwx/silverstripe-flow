<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Document\Placeholders;
use Atwx\Flow\Mail\RecipientResolver;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\CaseMail;
use Atwx\Flow\Model\MailTemplate;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Workflow;
use Psr\Log\LoggerInterface;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
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
     * Draft of a mail: recipients resolved, placeholders filled; attachments are
     * the template's document templates (by ID) and no existing documents.
     */
    public function draft(DataObject $case, MailTemplate $template, ?Member $me): MailDraft
    {
        $this->assertTemplateOf($case, $template);
        $resolver = RecipientResolver::singleton();
        $placeholders = Placeholders::singleton();
        $draft = MailDraft::create();
        $draft->templateID = (int) $template->ID;
        $draft->to = $resolver->resolve((string) $template->Recipients, $case, $me, $skippedTo);
        $draft->cc = $resolver->resolve((string) $template->Cc, $case, $me, $skippedCc);
        $draft->replyTo = $resolver->resolve((string) $template->ReplyTo, $case, $me, $skippedReplyTo)[0] ?? '';
        $draft->skipped = array_values(array_unique(array_merge(
            (array) $skippedTo,
            (array) $skippedCc,
            (array) $skippedReplyTo
        )));
        $draft->subject = trim($placeholders->replace((string) $template->Subject, $case, $me, false));
        $draft->body = $placeholders->replace((string) $template->Body, $case, $me, false);
        foreach ($template->attachmentTemplates() as $documentTemplate) {
            $draft->documentTemplateIDs[] = (int) $documentTemplate->ID;
        }
        return $draft;
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
            $email = $this->email($case, $draft, $by, $names);
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
        $email = $this->email($case, $draft, $by, $names);
        // Opened as a draft by mail programs that support it (Outlook, Thunderbird).
        $email->getHeaders()->addTextHeader('X-Unsent', '1');
        $mail->AttachmentNames = implode(', ', $names);
        $mail->Mode = CaseMail::EML;
        $mail->write();
        return [$mail, $email->toString()];
    }

    /**
     * Automatic mails of templates sent when the case file enters a status
     * (called after the status change is committed).
     */
    public function onStatusChange(DataObject $case, StatusChange $change, ?Member $by): void
    {
        $workflow = Workflow::forClass($case::class);
        if (!$workflow || !$change->ToID) {
            return;
        }
        $templates = MailTemplate::get()->filter([
            'WorkflowID' => $workflow->ID,
            'SendOnStatusID' => (int) $change->ToID,
        ]);
        foreach ($templates as $template) {
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
        ]);
    }

    /**
     * The mail with its attachments: new PDFs of the chosen document templates
     * (stored as documents of the case file) and chosen existing documents.
     *
     * @param array<int, string> $names Set to the attachment names
     */
    private function email(DataObject $case, MailDraft $draft, ?Member $by, array &$names = []): Email
    {
        $names = [];
        $email = Email::create()
            ->setTo($draft->to)
            ->setSubject($draft->subject)
            ->text($draft->body)
            ->html(nl2br(htmlspecialchars($draft->body, ENT_QUOTES), false));
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
        $documents = DocumentService::singleton();
        foreach ($draft->documentTemplateIDs as $id) {
            $template = \Atwx\Flow\Model\DocumentTemplate::get()->byID($id);
            if (!$template) {
                continue;
            }
            $document = $documents->create($case, $template, $by);
            $names[] = $this->attach($email, $document);
        }
        foreach ($draft->documentIDs as $id) {
            $document = CaseDocument::get()->filter([
                'ID' => $id,
                'CaseClass' => $case->ClassName,
                'CaseID' => $case->ID,
            ])->first();
            if ($document) {
                $names[] = $this->attach($email, $document);
            }
        }
        return $email;
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
