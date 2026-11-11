<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * A status of a workflow, assigned to one phase.
 *
 * @property string $Code
 * @property string $Title
 * @property bool $IsFinal
 * @property int $Sort
 * @property int $WorkflowID
 * @property int $PhaseID
 * @method Workflow Workflow()
 * @method Phase Phase()
 * @method HasManyList<Transition> OutgoingTransitions()
 * @method HasManyList<Transition> IncomingTransitions()
 */
class Status extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_Status';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'IsFinal' => 'Boolean',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
        'Phase' => Phase::class,
    ];

    private static array $has_many = [
        'OutgoingTransitions' => Transition::class . '.From',
        'IncomingTransitions' => Transition::class . '.To',
    ];

    private static array $cascade_deletes = [
        'OutgoingTransitions',
        'IncomingTransitions',
    ];

    private static array $indexes = [
        'WorkflowCode' => ['type' => 'unique', 'columns' => ['WorkflowID', 'Code']],
    ];

    private static array $summary_fields = [
        'Sort',
        'Phase.Title',
        'Title',
        'Code',
        'IsFinal.Nice',
    ];

    private static string $default_sort = 'Sort ASC, ID ASC';

    /**
     * Labels for summary columns that point into relations or getters.
     *
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Phase.Title'] = _t(self::class . '.has_one_Phase', 'Phase');
        $labels['IsFinal.Nice'] = _t(self::class . '.db_IsFinal', 'Is final');
        return $labels;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID', 'OutgoingTransitions', 'IncomingTransitions']);
        $this->lockCode($fields);

        $fields->replaceField('PhaseID', DropdownField::create(
            'PhaseID',
            $this->fieldLabel('Phase'),
            Phase::get()->filter('WorkflowID', (int) $this->WorkflowID)->map('ID', 'Title')
        )->setEmptyString(_t(Workflow::class . '.CHOOSE_STATUS', '– choose –')));

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
        if (!$this->PhaseID) {
            $result->addFieldError('PhaseID', _t(self::class . '.PHASE_REQUIRED', 'Please choose a phase.'));
        } elseif (!$this->Phase()->exists() || (int) $this->Phase()->WorkflowID !== (int) $this->WorkflowID) {
            $result->addFieldError('PhaseID', _t(
                ConfigRecord::class . '.FOREIGN_RECORD',
                'Please choose a record of the same workflow.'
            ));
        }
        return $result;
    }

    /**
     * Clear the initial status of the workflow when this status is removed.
     */
    protected function onBeforeDelete(): void
    {
        parent::onBeforeDelete();
        $workflow = $this->Workflow();
        if ($workflow->isInDB() && (int) $workflow->InitialStatusID === (int) $this->ID) {
            $workflow->InitialStatusID = 0;
            $workflow->write();
        }
    }
}
