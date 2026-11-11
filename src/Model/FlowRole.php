<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use SilverStripe\ORM\ManyManyList;
use SilverStripe\Security\Group;

/**
 * A role within a workflow.
 *
 * Internal roles map to a Silverstripe group; members of that group act in this
 * role. External roles have no account and act only through an edit link.
 *
 * @property string $Code
 * @property string $Title
 * @property bool $IsExternal
 * @property int $Sort
 * @property int $WorkflowID
 * @property int $GroupID
 * @method Workflow Workflow()
 * @method Group Group()
 * @method ManyManyList<Transition> Transitions()
 * @method HasManyList<FieldPermission> FieldPermissions()
 */
class FlowRole extends DataObject
{
    use ConfigRecord;

    private static string $table_name = 'Flow_Role';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'IsExternal' => 'Boolean',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
        'Group' => Group::class,
    ];

    private static array $has_many = [
        'FieldPermissions' => FieldPermission::class . '.Role',
    ];

    private static array $cascade_deletes = [
        'FieldPermissions',
    ];

    private static array $belongs_many_many = [
        'Transitions' => Transition::class . '.Roles',
    ];

    private static array $indexes = [
        'WorkflowCode' => ['type' => 'unique', 'columns' => ['WorkflowID', 'Code']],
    ];

    private static array $summary_fields = [
        'Title',
        'Code',
        'IsExternal.Nice',
        'Group.Title',
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
        $labels['IsExternal.Nice'] = _t(self::class . '.db_IsExternal', 'External');
        $labels['Group.Title'] = _t(self::class . '.has_one_Group', 'Group');
        return $labels;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID', 'Transitions', 'FieldPermissions']);
        $this->lockCode($fields);
        $fields->dataFieldByName('GroupID')?->setDescription(_t(
            self::class . '.GROUP_DESCRIPTION',
            'Members of this group act in this role. Leave empty for external roles.'
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
        if ($this->IsExternal && $this->GroupID) {
            $result->addFieldError('GroupID', _t(
                self::class . '.EXTERNAL_NO_GROUP',
                'External roles act through edit links and cannot have a group.'
            ));
        }
        if (!$this->IsExternal && !$this->GroupID) {
            $result->addFieldError('GroupID', _t(
                self::class . '.INTERNAL_NEEDS_GROUP',
                'Internal roles need a group.'
            ));
        }
        return $result;
    }
}
