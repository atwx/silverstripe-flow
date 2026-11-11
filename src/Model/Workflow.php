<?php

namespace Atwx\Flow\Model;

use Atwx\Flow\Extension\CaseFileExtension;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
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
 * @method HasManyList<FieldGroup> FieldGroups()
 * @method HasManyList<FieldPermission> FieldPermissions()
 * @method HasManyList<CustomField> CustomFields()
 * @method HasManyList<Responsibility> Responsibilities()
 * @method HasManyList<DocumentTemplate> DocumentTemplates()
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
        'FieldGroups' => FieldGroup::class,
        'FieldPermissions' => FieldPermission::class,
        'CustomFields' => CustomField::class,
        'Responsibilities' => Responsibility::class,
        'DocumentTemplates' => DocumentTemplate::class,
    ];

    private static array $cascade_deletes = [
        'DocumentTemplates',
        'Responsibilities',
        'CustomFields',
        'FieldPermissions',
        'FieldGroups',
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
        $relations = [
            'Phases', 'Statuses', 'Transitions', 'Roles', 'FieldPermissions', 'CustomFields', 'Responsibilities',
            'DocumentTemplates',
        ];
        foreach ($relations as $relation) {
            $grid = $fields->dataFieldByName($relation);
            if ($grid instanceof GridField) {
                $grid->setConfig(GridFieldConfig_RecordEditor::create());
            }
        }
        // Field groups come from code: titles and order are editable, nothing else.
        $grid = $fields->dataFieldByName('FieldGroups');
        if ($grid instanceof GridField) {
            $config = GridFieldConfig_RecordEditor::create();
            $config->removeComponentsByType(GridFieldAddNewButton::class);
            $grid->setConfig($config);
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
        if (!in_array($class, self::caseClasses(), true)) {
            $result->addFieldError('CaseClass', _t(
                self::class . '.CASECLASS_INVALID',
                'Please choose a data class with the case file extension.'
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

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        FieldGroup::syncWorkflow($this);
    }

    /**
     * Create declared field groups on dev/build (e.g. after a deployment added one).
     */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();
        foreach (self::get() as $workflow) {
            FieldGroup::syncWorkflow($workflow);
        }
    }

    /**
     * Data classes that carry the case file extension.
     *
     * @return array<int, class-string<DataObject>>
     */
    public static function caseClasses(): array
    {
        $classes = [];
        foreach (ClassInfo::subclassesFor(DataObject::class, false) as $class) {
            if (DataObject::has_extension($class, CaseFileExtension::class)) {
                $classes[] = $class;
            }
        }
        sort($classes);
        return $classes;
    }

    /**
     * Data classes a workflow can be attached to, keyed by class name.
     *
     * @return array<string, string>
     */
    protected function caseClassOptions(): array
    {
        $options = [];
        foreach (self::caseClasses() as $class) {
            $options[$class] = DataObject::singleton($class)->i18n_singular_name() . " ({$class})";
        }
        if ($this->CaseClass && !isset($options[$this->CaseClass])) {
            $options[$this->CaseClass] = $this->CaseClass;
        }
        return $options;
    }
}
