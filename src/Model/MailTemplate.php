<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;

/**
 * Mail template of a workflow: subject and plain-text body with the placeholders
 * of the document templates ({{Title}}, {{Flow.code}}, {{Assignee.code}}, ...),
 * recipients as expressions (see RecipientResolver), PDFs of document templates
 * as attachments. Sent from the case file (with a preview to edit before sending)
 * or automatically when the case file enters a status (SendOnStatus); with the
 * initial status also when a case file is created (e.g. a notification about a
 * new application from a public form, with {{Link}} to it).
 *
 * With ForEach (a has_many or many_many relation of the case file) one mail is
 * sent per entry of that sub-list: {{Item.<field>}} in the texts and
 * Item.<field> in the recipients refer to the entry.
 *
 * @property string $Code
 * @property string $Title
 * @property string $Subject
 * @property string $Body
 * @property string $Recipients
 * @property string $Cc
 * @property string $ReplyTo
 * @property string $Attachments Comma-separated codes of document templates
 * @property string $ForEach Relation of the case file: one mail per entry
 * @property int $Sort
 * @property int $WorkflowID
 * @property int $SendOnStatusID
 * @method Workflow Workflow()
 * @method Status SendOnStatus()
 */
class MailTemplate extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_MailTemplate';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Subject' => 'Varchar(255)',
        'Body' => 'Text',
        'Recipients' => 'Varchar(255)',
        'Cc' => 'Varchar(255)',
        'ReplyTo' => 'Varchar(255)',
        'Attachments' => 'Varchar(255)',
        'ForEach' => 'Varchar(100)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
        'SendOnStatus' => Status::class,
    ];

    private static array $indexes = [
        'WorkflowCode' => ['type' => 'unique', 'columns' => ['WorkflowID', 'Code']],
    ];

    private static array $summary_fields = [
        'Sort',
        'Title',
        'Code',
        'SendOnStatus.Title',
    ];

    private static string $default_sort = 'Sort ASC, ID ASC';

    /**
     * The relation whose entries get one mail each (null: one mail).
     */
    public function forEachRelation(): ?string
    {
        $relation = trim((string) $this->ForEach);
        return $relation !== '' ? $relation : null;
    }

    /**
     * Whether a relation of the class is a list (has_many or many_many).
     */
    public static function isListRelation(string $class, string $relation): bool
    {
        $schema = DataObject::getSchema();
        return class_exists($class)
            && ($schema->hasManyComponent($class, $relation) || $schema->manyManyComponent($class, $relation));
    }

    /**
     * Document templates whose PDFs are attached.
     *
     * @return array<int, DocumentTemplate>
     */
    public function attachmentTemplates(): array
    {
        $codes = array_values(array_filter(array_map('trim', explode(',', (string) $this->Attachments))));
        if (!$codes || !$this->WorkflowID) {
            return [];
        }
        return DocumentTemplate::get()->filter(['WorkflowID' => $this->WorkflowID, 'Code' => $codes])->toArray();
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['SendOnStatus.Title'] = _t(self::class . '.has_one_SendOnStatus', 'Send automatically on');
        $labels['ForEach'] = _t(self::class . '.db_ForEach', 'One mail per entry of');
        return $labels;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID']);
        $this->lockCode($fields);
        $fields->replaceField('Body', TextareaField::create('Body', $this->fieldLabel('Body'))
            ->setRows(16)
            ->setDescription(_t(
                self::class . '.BODY_DESCRIPTION',
                'Plain text with placeholders: {{Field}}, {{Relation.Field}}, {{Flow.<custom field>}}, '
                . '{{Assignee.<responsibility>}}, {{Status}}, {{Today}}, {{Me.Name}}, {{Link}} (address of the '
                . 'case file); {{Field|-}} gives "-" for empty values.'
            )));
        $fields->dataFieldByName('Subject')
            ?->setDescription(_t(self::class . '.SUBJECT_DESCRIPTION', 'With placeholders.'));
        $recipients = _t(
            self::class . '.RECIPIENTS_DESCRIPTION',
            'Comma-separated: addresses, fields of the case file (Email, Contact.Email, Parents.Email), '
            . 'Assignee.<responsibility>, Me.'
        );
        foreach (['Recipients', 'Cc', 'ReplyTo'] as $name) {
            $fields->dataFieldByName($name)?->setDescription($recipients);
        }
        $fields->dataFieldByName('Attachments')?->setDescription(_t(
            self::class . '.ATTACHMENTS_DESCRIPTION',
            'Codes of document templates, comma-separated: their PDFs are created and attached.'
        ));
        $fields->dataFieldByName('ForEach')?->setDescription(_t(
            self::class . '.FOR_EACH_DESCRIPTION',
            'Optional: a sub-list of the case file (relation name, e.g. Registrations). One mail is sent per '
            . 'entry; {{Item.Field}} and Item.Email (recipients) refer to the entry.'
        ));
        $fields->replaceField('SendOnStatusID', DropdownField::create(
            'SendOnStatusID',
            $this->fieldLabel('SendOnStatus'),
            Status::get()->filter('WorkflowID', (int) $this->WorkflowID)->map('ID', 'Title')
        )->setEmptyString(_t(self::class . '.SEND_MANUALLY', '– only from the case file –'))
            ->setDescription(_t(
                self::class . '.SEND_ON_DESCRIPTION',
                'Sent when a case file enters this status; with the initial status also when a case file '
                . 'is created. {{Link}} is the address of the case file.'
            )));
        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->WorkflowID) {
            $result->addError(_t(ConfigRecord::class . '.WORKFLOW_REQUIRED', 'The record must belong to a workflow.'));
            return $result;
        }
        $this->validateCode($result, ['WorkflowID' => (int) $this->WorkflowID]);
        if (!$this->Title) {
            $result->addFieldError('Title', _t(ConfigRecord::class . '.TITLE_REQUIRED', 'Please enter a title.'));
        }
        if (trim((string) $this->Subject) === '') {
            $result->addFieldError('Subject', _t(self::class . '.SUBJECT_REQUIRED', 'Please enter a subject.'));
        }
        $relation = $this->forEachRelation();
        $workflow = $this->Workflow();
        $caseClass = $workflow->exists() ? (string) $workflow->CaseClass : '';
        if ($relation !== null && !self::isListRelation($caseClass, $relation)) {
            $result->addFieldError('ForEach', _t(
                self::class . '.FOR_EACH_UNKNOWN',
                '"{relation}" is not a list of the case file.',
                ['relation' => $relation]
            ));
        }
        $status = $this->SendOnStatus();
        if ($status->exists() && (int) $status->WorkflowID !== (int) $this->WorkflowID) {
            $result->addFieldError('SendOnStatusID', _t(
                ConfigRecord::class . '.FOREIGN_RECORD',
                'Please choose a record of the same workflow.'
            ));
        }
        if ($status->exists() && trim((string) $this->Recipients) === '') {
            $result->addFieldError('Recipients', _t(
                self::class . '.RECIPIENTS_REQUIRED',
                'Automatic mails need recipients.'
            ));
        }
        return $result;
    }
}
