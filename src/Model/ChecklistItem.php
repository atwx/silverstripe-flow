<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

/**
 * An item of the checklist of a workflow's case files ("Unterlagen und
 * Aufgaben"), e.g. "Zeugnisse" or "Health Form". With "required before" the
 * case file cannot enter that status until the item is done (or not needed).
 *
 * @property string $Code
 * @property string $Title
 * @property int $Sort
 * @property int $WorkflowID
 * @property int $RequiredBeforeID
 * @method Workflow Workflow()
 * @method Status RequiredBefore()
 */
class ChecklistItem extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_ChecklistItem';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Sort' => 'Int',
        'FileMode' => "Enum('none,optional,required', 'none')",
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
        'RequiredBefore' => Status::class,
    ];

    private static array $indexes = [
        'WorkflowCode' => ['type' => 'unique', 'columns' => ['WorkflowID', 'Code']],
    ];

    private static array $summary_fields = [
        'Sort',
        'Title',
        'Code',
        'RequiredBefore.Title',
    ];

    private static string $default_sort = 'Sort ASC, ID ASC';

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['RequiredBefore.Title'] = _t(self::class . '.has_one_RequiredBefore', 'Required before');
        $labels['FileMode'] = _t(self::class . '.db_FileMode', 'File');
        return $labels;
    }

    /**
     * @return array<string, string>
     */
    public static function fileModeOptions(): array
    {
        return [
            'none' => _t(self::class . '.FILE_NONE', 'no file'),
            'optional' => _t(self::class . '.FILE_OPTIONAL', 'file optional'),
            'required' => _t(self::class . '.FILE_REQUIRED', 'file required when done'),
        ];
    }

    public function hasFile(): bool
    {
        return in_array((string) $this->FileMode, ['optional', 'required'], true);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID']);
        $this->lockCode($fields);
        $fields->replaceField('FileMode', DropdownField::create(
            'FileMode',
            $this->fieldLabel('FileMode'),
            self::fileModeOptions()
        ));
        return $fields;
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
        $status = $this->RequiredBefore();
        if ($status->exists() && (int) $status->WorkflowID !== (int) $this->WorkflowID) {
            $result->addFieldError('RequiredBeforeID', _t(
                self::class . '.STATUS_FOREIGN',
                'The status must belong to this workflow.'
            ));
        }
        return $result;
    }
}
