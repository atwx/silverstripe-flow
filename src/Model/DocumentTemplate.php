<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;

/**
 * A letter template of a workflow ("Vorlage"): title and body with
 * placeholders such as {{Title}}, {{Child.FirstName}}, {{Flow.visa}},
 * {{Assignee.responsible}}, {{Today}} or {{Me.Name}} (see Placeholders).
 * Documents are created from it as PDF in the case file.
 *
 * In the dialog "Create document" the member can enter a free text
 * ({{FreeText}}, with FreeText on), choose entries of a list of the case file
 * (Select = relation name; {{#Selected}}…{{/Selected}}) and the signing member
 * ({{Signer.Name}}; default: the member of SignerResponsibility, else the
 * current member).
 *
 * @property string $Code
 * @property string $Title
 * @property string $DocumentTitle
 * @property string $Body
 * @property int $Sort
 * @property ?string $Select
 * @property bool $FreeText
 * @property ?string $SignerResponsibility
 * @property int $WorkflowID
 * @method Workflow Workflow()
 */
class DocumentTemplate extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_DocumentTemplate';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'DocumentTitle' => 'Varchar(255)',
        'Body' => 'HTMLText',
        'Sort' => 'Int',
        'Select' => 'Varchar(100)',
        'FreeText' => 'Boolean',
        'SignerResponsibility' => 'Varchar(64)',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
    ];

    private static array $indexes = [
        'WorkflowCode' => ['type' => 'unique', 'columns' => ['WorkflowID', 'Code']],
    ];

    private static array $summary_fields = [
        'Sort',
        'Title',
        'Code',
    ];

    private static string $default_sort = 'Sort ASC, ID ASC';

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID']);
        $this->lockCode($fields);
        $fields->replaceField('Body', TextareaField::create('Body', $this->fieldLabel('Body'))
            ->setRows(20)
            ->setDescription(_t(
                self::class . '.BODY_DESCRIPTION',
                'HTML with placeholders: {{Field}}, {{Relation.Field}}, {{Flow.<custom field>}}, '
                . '{{Assignee.<responsibility>}}, {{Status}}, {{Today}}, {{Me.Name}}, {{Signer.Name}}, '
                . '{{FreeText}}, {{Block.<code>}}, {{#Relation sort=Field filter=Field}}…{{/Relation}}, '
                . '{{#Selected}}…{{/Selected}}.'
            )));
        $fields->dataFieldByName('DocumentTitle')?->setDescription(_t(
            self::class . '.DOCUMENT_TITLE_DESCRIPTION',
            'Title of the created document, with placeholders; default: the title of the template.'
        ));
        return $fields;
    }

    /**
     * The relation whose entries are chosen in the dialog, if any.
     */
    public function selectRelation(): ?string
    {
        $relation = trim((string) $this->Select);
        return $relation !== '' ? $relation : null;
    }

    /**
     * The responsibility whose member signs by default, if set and known.
     */
    public function signerResponsibility(): ?Responsibility
    {
        $code = trim((string) $this->SignerResponsibility);
        if ($code === '') {
            return null;
        }
        return Responsibility::get()->filter(['WorkflowID' => (int) $this->WorkflowID, 'Code' => $code])->first();
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->WorkflowID) {
            $result->addError(_t(ConfigRecord::class . '.WORKFLOW_REQUIRED', 'The record must belong to a workflow.'));
        }
        $this->validateCode($result, ['WorkflowID' => (int) $this->WorkflowID]);
        if (!$this->Title) {
            $result->addFieldError('Title', _t(ConfigRecord::class . '.TITLE_REQUIRED', 'Please enter a title.'));
        }
        return $result;
    }
}
