<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Security\Member;

/**
 * A responsibility of the case files of a workflow ("Zuständigkeit"), e.g.
 * "Verantwortlich" or "Wo ist die Akte?": one member per case file, chosen
 * from the members of an internal role.
 *
 * @property string $Code
 * @property string $Title
 * @property int $Sort
 * @property int $WorkflowID
 * @property int $RoleID
 * @method Workflow Workflow()
 * @method FlowRole Role()
 */
class Responsibility extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_Responsibility';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
        'Role' => FlowRole::class,
    ];

    private static array $indexes = [
        'WorkflowCode' => ['type' => 'unique', 'columns' => ['WorkflowID', 'Code']],
    ];

    private static array $summary_fields = [
        'Sort',
        'Title',
        'Code',
        'Role.Title',
    ];

    private static string $default_sort = 'Sort ASC, ID ASC';

    /**
     * Members who can be assigned: the members of the role's group.
     *
     * @return array<int, Member>
     */
    public function candidates(): array
    {
        $role = $this->Role();
        $group = $role->exists() ? $role->Group() : null;
        if (!$group || !$group->exists()) {
            return [];
        }
        return Member::get()
            ->filter('Groups.ID', $group->collateFamilyIDs())
            ->sort(['FirstName' => 'ASC', 'Surname' => 'ASC'])
            ->toArray();
    }

    /**
     * @return array<int, string> member ID => name
     */
    public function candidateOptions(): array
    {
        $options = [];
        foreach ($this->candidates() as $member) {
            $options[(int) $member->ID] = (string) $member->getName();
        }
        return $options;
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Role.Title'] = _t(self::class . '.has_one_Role', 'Role');
        return $labels;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID']);
        $this->lockCode($fields);
        $role = $fields->dataFieldByName('RoleID');
        if ($role instanceof DropdownField) {
            $role->setSource(FlowRole::get()->filter([
                'WorkflowID' => (int) $this->WorkflowID,
                'IsExternal' => false,
            ])->map('ID', 'Title'));
            $role->setDescription(_t(
                self::class . '.ROLE_DESCRIPTION',
                'Members of this role can be assigned.'
            ));
        }
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
        $role = $this->Role();
        if (!$role->exists() || $role->IsExternal || (int) $role->WorkflowID !== (int) $this->WorkflowID) {
            $result->addFieldError('RoleID', _t(
                self::class . '.ROLE_REQUIRED',
                'Please choose an internal role of this workflow.'
            ));
        }
        return $result;
    }

    /**
     * Assignments go with the responsibility.
     */
    protected function onBeforeDelete(): void
    {
        parent::onBeforeDelete();
        foreach (Assignment::get()->filter('ResponsibilityID', $this->ID) as $assignment) {
            $assignment->delete();
        }
    }
}
