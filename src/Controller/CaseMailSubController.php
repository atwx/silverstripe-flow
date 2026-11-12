<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Mail\RecipientResolver;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\CaseMail;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\MailTemplate;
use Atwx\Flow\Service\MailDraft;
use Atwx\Flow\Service\MailService;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\RowAction;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Mails of a case file: the list (sent, failed, mail files) with the full text,
 * and the mail form (compose/<case ID>?template=<ID>): recipients, subject, text
 * and attachments from the template, editable before sending or downloading as
 * a mail file.
 */
class CaseMailSubController extends FrontdeskSubController
{
    private static string $managed_model = CaseMail::class;

    private static string $url_segment = 'mails';

    private static string $title = 'Mails';

    private static bool $allow_add = false;

    private static array $allowed_actions = [
        'compose' => '->canCompose',
        'MailForm' => '->canCompose',
    ];

    /**
     * @return DataList<CaseMail>
     */
    protected function getBaseQuery(): DataList
    {
        // PHPStan reports static<T> from DataList::filter() as incompatible with DataList<T>.
        // @phpstan-ignore return.type
        return CaseMail::get()->filter([
            'CaseClass' => $this->parentController?->getManagedModel(),
            'CaseID' => $this->caseFile() ? $this->getParentID() : 0,
        ]);
    }

    /**
     * Mails are only written through the mail form, never edited.
     *
     * @param Member|null $member
     */
    public function canEdit($member = null): bool
    {
        return false;
    }

    /**
     * Writing mails needs the edit permission of the case files and a role in the
     * workflow; the case file must be in the parent's list.
     */
    public function canCompose(): bool
    {
        $parent = $this->parentController;
        if (!$parent instanceof FlowCaseController || !$parent->canEdit()) {
            return false;
        }
        $case = $this->caseFile();
        return $case !== null && $parent->mayWriteMails($case);
    }

    protected function defineColumns(): ColumnCollection
    {
        $labels = CaseMail::singleton()->fieldLabels();
        return ColumnCollection::create()
            ->make('Created.Nice', $labels['Created.Nice'])->link('view/{ID}')->end()
            ->make('Subject', $labels['Subject'])->link('view/{ID}')->end()
            ->make('Recipients', $labels['Recipients'])->end()
            ->make('ModeLabel', $labels['ModeLabel'])
                ->format(fn ($value, DataObject $record) => $this->modeBadge($record))
                ->type('html')
                ->end()
            ->make('SenderName', _t(CaseMail::class . '.has_one_Member', 'By'))->end();
    }

    private function modeBadge(DataObject $record): string
    {
        if (!$record instanceof CaseMail) {
            return '';
        }
        $class = match ((string) $record->Mode) {
            CaseMail::SENT => 'badge-success',
            CaseMail::FAILED => 'badge-error',
            default => 'badge-ghost',
        };
        return '<span class="badge ' . $class . '">' . htmlspecialchars($record->getModeLabel()) . '</span>';
    }

    /**
     * @return array<int, RowAction>
     */
    protected function defineRowActions(DataObject $record): array
    {
        return [RowAction::link(_t(self::class . '.VIEW', 'View'), $this->Link('view/' . $record->ID))];
    }

    public function compose(HTTPRequest $request): \SilverStripe\ORM\FieldType\DBHTMLText
    {
        $template = $this->requestedTemplate();
        $title = $template
            ? _t(self::class . '.COMPOSE_TITLE', 'Mail: {template}', ['template' => $template->Title])
            : _t(self::class . '.COMPOSE', 'Write mail');
        return $this->getViewer('edit')->process($this->customise([
            'Title' => $title,
            'Form' => $this->mailForm(),
            'Actions' => false,
        ]));
    }

    public function mailForm(): Form
    {
        $case = $this->caseFile();
        $template = $this->requestedTemplate();
        $labels = CaseMail::singleton()->fieldLabels();

        $attachments = [];
        foreach (DocumentTemplate::get()->filter('WorkflowID', (int) $template?->WorkflowID) as $documentTemplate) {
            $attachments['tpl-' . $documentTemplate->ID] = _t(
                self::class . '.ATTACH_NEW',
                'New PDF: {title}',
                ['title' => $documentTemplate->Title]
            );
        }
        if ($case) {
            $documents = CaseDocument::get()->filter(['CaseClass' => $case->ClassName, 'CaseID' => $case->ID]);
            foreach ($documents as $document) {
                $attachments['doc-' . $document->ID] = (string) $document->Title;
            }
        }

        $hint = _t(self::class . '.ADDRESS_HINT', 'Several addresses separated by commas.');
        $fields = FieldList::create(
            HiddenField::create('TemplateID', 'TemplateID', (int) $template?->ID),
            TextField::create('Recipients', $labels['Recipients'])->setDescription($hint),
            TextField::create('Cc', $labels['Cc']),
            TextField::create('ReplyTo', $labels['ReplyTo']),
            TextField::create('Subject', $labels['Subject']),
            TextareaField::create('Body', $labels['Body'])->setRows(16)
        );
        if ($attachments) {
            $fields->push(CheckboxSetField::create('Attachments', $labels['AttachmentNames'], $attachments));
        }
        $form = Form::create($this, 'MailForm', $fields, FieldList::create(
            FormAction::create('doSend', _t(self::class . '.SEND', 'Send')),
            FormAction::create('doEml', _t(self::class . '.EML', 'Open in mail program'))->addExtraClass('btn-ghost'),
            LiteralField::create(
                'Cancel',
                '<a href="' . htmlspecialchars($this->caseLink(), ENT_QUOTES) . '" class="btn">'
                . _t(self::class . '.CANCEL', 'Cancel') . '</a>'
            )
        ));
        $form->setValidationResponseCallback(fn (ValidationResult $result) => $this->redirectBack());

        if ($case && $template && !$this->getRequest()->isPOST()) {
            $draft = MailService::singleton()->draft($case, $template, Security::getCurrentUser());
            if ($draft->skipped) {
                $fields->unshift(LiteralField::create('Skipped', '<div role="status" class="alert alert-warning mb-4">'
                    . htmlspecialchars(_t(
                        self::class . '.SKIPPED',
                        'Without a valid address, left out: {list}',
                        ['list' => implode(', ', $draft->skipped)]
                    )) . '</div>'));
            }
            $form->loadDataFrom([
                'Recipients' => implode(', ', $draft->to),
                'Cc' => implode(', ', $draft->cc),
                'ReplyTo' => $draft->replyTo,
                'Subject' => $draft->subject,
                'Body' => $draft->body,
                'Attachments' => array_map(fn ($id) => 'tpl-' . $id, $draft->documentTemplateIDs),
            ]);
        }
        return $form;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doSend(array $data, Form $form): HTTPResponse
    {
        $case = $this->caseFile();
        $draft = $case ? $this->draftFrom($data, $form) : null;
        if (!$case || !$draft) {
            return $this->redirectBack();
        }
        $mail = MailService::singleton()->send($case, $draft, Security::getCurrentUser());
        if ($mail->Mode === CaseMail::SENT) {
            $this->flash(_t(self::class . '.SENT', 'The mail has been sent.'));
        } else {
            $this->flash(_t(
                self::class . '.FAILED',
                'The mail could not be sent: {error}',
                ['error' => $mail->Error]
            ), 'error');
        }
        return $this->redirect($this->caseLink());
    }

    /**
     * @param array<string, mixed> $data
     */
    public function doEml(array $data, Form $form): HTTPResponse
    {
        $case = $this->caseFile();
        $draft = $case ? $this->draftFrom($data, $form) : null;
        if (!$case || !$draft) {
            return $this->redirectBack();
        }
        [, $raw] = MailService::singleton()->eml($case, $draft, Security::getCurrentUser());
        $name = (preg_replace('/[^A-Za-z0-9_-]+/', '-', $draft->subject) ?: 'mail') . '.eml';
        $response = HTTPResponse::create($raw, 200);
        $response->addHeader('Content-Type', 'message/rfc822');
        $response->addHeader('Content-Disposition', 'attachment; filename="' . $name . '"');
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    /**
     * Draft from the submitted form; invalid addresses refuse the form.
     *
     * @param array<string, mixed> $data
     */
    private function draftFrom(array $data, Form $form): ?MailDraft
    {
        $resolver = RecipientResolver::singleton();
        $draft = MailDraft::create();
        $template = $this->requestedTemplate();
        $draft->templateID = (int) $template?->ID;
        $errors = [];
        foreach (['Recipients' => 'to', 'Cc' => 'cc', 'ReplyTo' => 'replyTo'] as $field => $property) {
            [$valid, $invalid] = $resolver->parseAddresses((string) ($data[$field] ?? ''));
            if ($invalid) {
                $errors[$field] = _t(
                    self::class . '.INVALID_ADDRESS',
                    'Invalid address: {address}',
                    ['address' => implode(', ', $invalid)]
                );
            }
            $draft->$property = $property === 'replyTo' ? ($valid[0] ?? '') : $valid;
        }
        if (!$draft->to) {
            $errors['Recipients'] = _t(self::class . '.NO_RECIPIENTS', 'Please enter at least one recipient.');
        }
        $draft->subject = trim((string) ($data['Subject'] ?? ''));
        if ($draft->subject === '') {
            $errors['Subject'] = _t(MailTemplate::class . '.SUBJECT_REQUIRED', 'Please enter a subject.');
        }
        $draft->body = (string) ($data['Body'] ?? '');
        foreach ((array) ($data['Attachments'] ?? []) as $key) {
            if (preg_match('/^tpl-(\d+)$/', (string) $key, $m)) {
                $draft->documentTemplateIDs[] = (int) $m[1];
            } elseif (preg_match('/^doc-(\d+)$/', (string) $key, $m)) {
                $draft->documentIDs[] = (int) $m[1];
            }
        }
        if ($errors) {
            foreach ($errors as $field => $message) {
                $form->sessionMessage($message, 'bad');
                $form->Fields()->dataFieldByName($field)?->setMessage($message, 'bad');
            }
            $form->setSessionData($data);
            return null;
        }
        // Only document templates of the case file's workflow.
        if ($draft->documentTemplateIDs) {
            $draft->documentTemplateIDs = DocumentTemplate::get()->filter([
                'ID' => $draft->documentTemplateIDs,
                'WorkflowID' => (int) $template?->WorkflowID,
            ])->column('ID');
        }
        return $draft;
    }

    private function requestedTemplate(): ?MailTemplate
    {
        $request = $this->getRequest();
        $id = (int) ($request->postVar('TemplateID') ?: $request->getVar('template'));
        $case = $this->caseFile();
        if (!$id || !$case) {
            return null;
        }
        foreach (MailService::singleton()->templatesFor($case) as $template) {
            if ((int) $template->ID === $id) {
                return $template;
            }
        }
        return null;
    }

    private function caseFile(): ?DataObject
    {
        $parent = $this->parentController;
        return $parent instanceof FlowCaseController ? $parent->findCaseFile($this->getParentID()) : null;
    }

    private function caseLink(): string
    {
        return (string) $this->parentController?->Link('view/' . $this->getParentID());
    }
}
