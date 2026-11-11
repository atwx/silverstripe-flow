<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * A workflow: phases, statuses and transitions of the case files of one domain class.
 *
 * @property string $Code
 * @property string $Title
 * @property string $CaseClass
 * @property int $InitialStatusID
 * @method Status InitialStatus()
 * @method HasManyList<Phase> Phases()
 * @method HasManyList<Status> Statuses()
 * @method HasManyList<Transition> Transitions()
 * @method HasManyList<FlowRole> Roles()
 */
class Workflow extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_Workflow';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'CaseClass' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'InitialStatus' => Status::class,
    ];

    private static array $has_many = [
        'Phases' => Phase::class,
        'Statuses' => Status::class,
        'Transitions' => Transition::class,
        'Roles' => FlowRole::class,
    ];

    private static array $cascade_deletes = [
        'Transitions',
        'Statuses',
        'Phases',
        'Roles',
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
        'CaseClass' => ['type' => 'unique', 'columns' => ['CaseClass']],
    ];

    private static array $summary_fields = [
        'Title',
        'Code',
        'CaseClass',
    ];

    private static string $default_sort = 'Title ASC';

    /**
     * The workflow of the given domain class, if any.
     */
    public static function forClass(string $class): ?Workflow
    {
        return self::get()->find('CaseClass', $class);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $this->lockCode($fields);

        $fields->replaceField('CaseClass', DropdownField::create(
            'CaseClass',
            $this->fieldLabel('CaseClass'),
            $this->caseClassOptions()
        )->setEmptyString(_t(self::class . '.CHOOSE_CLASS', '– choose –')));

        $fields->replaceField('InitialStatusID', DropdownField::create(
            'InitialStatusID',
            $this->fieldLabel('InitialStatus'),
            $this->Statuses()->map('ID', 'Title')
        )->setEmptyString(_t(self::class . '.CHOOSE_STATUS', '– choose –')));
        if (!$this->isInDB()) {
            $fields->removeByName('InitialStatusID');
        }

        // Child records can only be created inside their workflow, never linked across workflows.
        foreach (['Phases', 'Statuses', 'Transitions', 'Roles'] as $relation) {
            $grid = $fields->dataFieldByName($relation);
            if ($grid instanceof GridField) {
                $grid->setConfig(GridFieldConfig_RecordEditor::create());
            }
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $this->validateCode($result);

        if (!$this->Title) {
            $result->addFieldError('Title', _t(ConfigRecord::class . '.TITLE_REQUIRED', 'Please enter a title.'));
        }

        $class = (string) $this->CaseClass;
        if ($class === '' || !class_exists($class) || !is_subclass_of($class, DataObject::class)) {
            $result->addFieldError('CaseClass', _t(
                self::class . '.CASECLASS_INVALID',
                'Please choose a data class for the case files.'
            ));
        } elseif (self::get()->filter('CaseClass', $class)->exclude('ID', (int) $this->ID)->exists()) {
            $result->addFieldError('CaseClass', _t(
                self::class . '.CASECLASS_TAKEN',
                'There already is a workflow for this class.'
            ));
        }

        $initial = $this->InitialStatus();
        if ($this->InitialStatusID && (!$initial->exists() || (int) $initial->WorkflowID !== (int) $this->ID)) {
            $result->addFieldError('InitialStatusID', _t(
                self::class . '.INITIALSTATUS_FOREIGN',
                'The initial status must belong to this workflow.'
            ));
        }

        return $result;
    }

    /**
     * Data classes a workflow can be attached to, keyed by class name.
     *
     * @return array<string, string>
     */
    protected function caseClassOptions(): array
    {
        $options = [];
        foreach ($this->config()->get('case_classes') ?: [] as $class) {
            if (class_exists($class)) {
                $options[$class] = DataObject::singleton($class)->i18n_singular_name() . " ({$class})";
            }
        }
        if ($this->CaseClass && !isset($options[$this->CaseClass])) {
            $options[$this->CaseClass] = $this->CaseClass;
        }
        return $options;
    }
}
