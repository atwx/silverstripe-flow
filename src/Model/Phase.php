<?php

namespace Atwx\Flow\Model;

use Atwx\Flow\Security\FlowPermissions;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * A phase groups statuses of a workflow (e.g. "Planning" → "Publication" → "Execution").
 *
 * @property string $Code
 * @property string $Title
 * @property int $Sort
 * @property int $WorkflowID
 * @method Workflow Workflow()
 * @method HasManyList<Status> Statuses()
 */
class Phase extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_Phase';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
    ];

    private static array $has_many = [
        'Statuses' => Status::class,
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
        $fields->removeByName(['WorkflowID', 'Statuses']);
        $this->lockCode($fields);
        return $fields;
    }

    /**
     * A phase can only be deleted once it holds no statuses.
     */
    public function canDelete($member = null): bool
    {
        if ($this->isInDB() && $this->Statuses()->exists()) {
            return false;
        }
        return FlowPermissions::canConfigure($member);
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
