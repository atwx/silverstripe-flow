<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\ManyManyList;

/**
 * An allowed status change, and the roles that may perform it.
 *
 * A transition is identified by its pair of statuses; the title is the label of
 * the action (e.g. "Done, back to the organisers").
 *
 * @property string $Title
 * @property int $Sort
 * @property int $WorkflowID
 * @property int $FromID
 * @property int $ToID
 * @method Workflow Workflow()
 * @method Status From()
 * @method Status To()
 * @method ManyManyList<FlowRole> Roles()
 */
class Transition extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_Transition';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
        'From' => Status::class,
        'To' => Status::class,
    ];

    private static array $many_many = [
        'Roles' => FlowRole::class,
    ];

    private static array $indexes = [
        'FromTo' => ['type' => 'unique', 'columns' => ['FromID', 'ToID']],
    ];

    private static array $summary_fields = [
        'From.Title',
        'To.Title',
        'Label',
        'RoleTitles',
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
        $labels['From.Title'] = _t(self::class . '.has_one_From', 'From');
        $labels['To.Title'] = _t(self::class . '.has_one_To', 'To');
        $labels['Label'] = _t(self::class . '.Label', 'Action');
        $labels['RoleTitles'] = _t(self::class . '.RoleTitles', 'Roles');
        return $labels;
    }

    /**
     * Label of the action: the title, or "From → To" if none is set.
     *
     * Kept separate from getTitle() so that the form field shows the stored value.
     */
    public function getLabel(): string
    {
        if ($this->Title) {
            return (string) $this->Title;
        }
        $from = $this->From();
        $to = $this->To();
        return ($from->exists() ? $from->Title : '?') . ' → ' . ($to->exists() ? $to->Title : '?');
    }

    /**
     * Roles allowed to perform this transition. Roles of other workflows never count,
     * even if they were attached programmatically.
     *
     * @return ManyManyList<FlowRole>
     */
    public function allowedRoles(): ManyManyList
    {
        return $this->Roles()->filter('WorkflowID', (int) $this->WorkflowID);
    }

    public function getRoleTitles(): string
    {
        return implode(', ', $this->allowedRoles()->column('Title'));
    }

    /**
     * Whether a member of one of the given roles may perform this transition.
     *
     * @param array<int, string> $roleCodes
     */
    public function allowsAnyRole(array $roleCodes): bool
    {
        return $roleCodes !== [] && $this->allowedRoles()->filter('Code', $roleCodes)->exists();
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID', 'Roles']);

        $statuses = Status::get()->filter('WorkflowID', (int) $this->WorkflowID)->map('ID', 'Title');
        $choose = _t(Workflow::class . '.CHOOSE_STATUS', '– choose –');
        $fields->replaceField(
            'FromID',
            DropdownField::create('FromID', $this->fieldLabel('From'), $statuses)->setEmptyString($choose)
        );
        $fields->replaceField(
            'ToID',
            DropdownField::create('ToID', $this->fieldLabel('To'), $statuses)->setEmptyString($choose)
        );
        $fields->addFieldToTab('Root.Main', CheckboxSetField::create(
            'Roles',
            $this->fieldLabel('Roles'),
            FlowRole::get()->filter('WorkflowID', (int) $this->WorkflowID)->map('ID', 'Title')
        ));
        $fields->dataFieldByName('Title')?->setDescription(_t(
            self::class . '.TITLE_DESCRIPTION',
            'Label of the action, e.g. "Send to review". Defaults to "From → To".'
        ));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->WorkflowID) {
            $result->addError(_t(ConfigRecord::class . '.WORKFLOW_REQUIRED', 'The record must belong to a workflow.'));
            return $result;
        }
        $foreign = _t(ConfigRecord::class . '.FOREIGN_RECORD', 'Please choose a record of the same workflow.');
        foreach (['FromID' => 'From', 'ToID' => 'To'] as $field => $relation) {
            if (!$this->$field) {
                $result->addFieldError($field, _t(self::class . '.STATUS_REQUIRED', 'Please choose a status.'));
                continue;
            }
            $status = $this->$relation();
            if (!$status->exists() || (int) $status->WorkflowID !== (int) $this->WorkflowID) {
                $result->addFieldError($field, $foreign);
            }
        }
        if ($this->FromID && (int) $this->FromID === (int) $this->ToID) {
            $result->addFieldError('ToID', _t(
                self::class . '.SAME_STATUS',
                'The target status must differ from the source status.'
            ));
        }
        $duplicate = self::get()
            ->filter(['FromID' => (int) $this->FromID, 'ToID' => (int) $this->ToID])
            ->exclude('ID', (int) $this->ID);
        if ($this->FromID && $this->ToID && $duplicate->exists()) {
            $result->addFieldError('ToID', _t(
                self::class . '.DUPLICATE',
                'This transition already exists.'
            ));
        }
        return $result;
    }

    /**
     * Detach roles of other workflows when the transition is saved (e.g. from the CMS form).
     */
    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        $foreign = $this->Roles()->exclude('WorkflowID', (int) $this->WorkflowID);
        foreach ($foreign as $role) {
            $this->Roles()->remove($role);
        }
    }
}
