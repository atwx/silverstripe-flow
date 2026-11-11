<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

/**
 * Creates a follow-up ("Wiedervorlage") when a case file enters a status,
 * due after a number of days and assigned to the member responsible (if
 * given). With "close on leave" the follow-up is closed when the case file
 * leaves the status again.
 *
 * @property string $Code
 * @property string $Title
 * @property int $Days
 * @property bool $CloseOnLeave
 * @property int $Sort
 * @property int $WorkflowID
 * @property int $StatusID
 * @property int $ResponsibilityID
 * @method Workflow Workflow()
 * @method Status Status()
 * @method Responsibility Responsibility()
 */
class FollowUpRule extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_FollowUpRule';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Days' => 'Int',
        'CloseOnLeave' => 'Boolean(1)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
        'Status' => Status::class,
        'Responsibility' => Responsibility::class,
    ];

    private static array $indexes = [
        'WorkflowCode' => ['type' => 'unique', 'columns' => ['WorkflowID', 'Code']],
    ];

    private static array $summary_fields = [
        'Title',
        'Code',
        'Status.Title',
        'Days',
        'Responsibility.Title',
    ];

    private static string $default_sort = 'Sort ASC, ID ASC';

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Status.Title'] = _t(self::class . '.has_one_Status', 'Status');
        $labels['Responsibility.Title'] = _t(self::class . '.has_one_Responsibility', 'Responsibility');
        return $labels;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID']);
        $this->lockCode($fields);
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
        $status = $this->Status();
        if (!$status->exists() || (int) $status->WorkflowID !== (int) $this->WorkflowID) {
            $result->addFieldError('StatusID', _t(
                self::class . '.STATUS_REQUIRED',
                'Please choose a status of this workflow.'
            ));
        }
        if ((int) $this->Days < 0) {
            $result->addFieldError('Days', _t(
                self::class . '.DAYS_NEGATIVE',
                'The number of days cannot be negative.'
            ));
        }
        return $result;
    }
}
