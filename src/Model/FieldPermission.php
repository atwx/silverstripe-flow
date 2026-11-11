<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

/**
 * Access level of a role to a field group, in one status or in all statuses.
 *
 * Resolution (see FieldAccess): an entry for the status beats an entry for all
 * statuses; without an entry the group is hidden. An actor with several roles
 * gets the highest level of them.
 *
 * @property string $Level
 * @property int $WorkflowID
 * @property int $FieldGroupID
 * @property int $RoleID
 * @property int $StatusID
 * @method Workflow Workflow()
 * @method FieldGroup FieldGroup()
 * @method FlowRole Role()
 * @method Status Status()
 */
class FieldPermission extends DataObject
{
    use ConfigRecord;

    public const string HIDDEN = 'hidden';
    public const string READ = 'read';
    public const string WRITE = 'write';

    /**
     * Levels in ascending order.
     */
    public const array LEVELS = [self::HIDDEN, self::READ, self::WRITE];

    private static string $table_name = 'Flow_FieldPermission';

    private static array $db = [
        'Level' => "Enum('hidden,read,write', 'read')",
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
        'FieldGroup' => FieldGroup::class,
        'Role' => FlowRole::class,
        'Status' => Status::class,
    ];

    private static array $indexes = [
        'GroupRoleStatus' => ['type' => 'unique', 'columns' => ['FieldGroupID', 'RoleID', 'StatusID']],
    ];

    private static array $summary_fields = [
        'FieldGroup.Title',
        'Role.Title',
        'StatusTitle',
        'LevelTitle',
    ];

    private static string $default_sort = 'ID ASC';

    public static function rank(string $level): int
    {
        $rank = array_search($level, self::LEVELS, true);
        return $rank === false ? 0 : $rank;
    }

    /**
     * @return array<string, string>
     */
    public static function levelOptions(): array
    {
        return [
            self::HIDDEN => _t(self::class . '.LEVEL_HIDDEN', 'hidden'),
            self::READ => _t(self::class . '.LEVEL_READ', 'read'),
            self::WRITE => _t(self::class . '.LEVEL_WRITE', 'write'),
        ];
    }

    public function getStatusTitle(): string
    {
        $status = $this->Status();
        return $status->exists() ? (string) $status->Title : _t(self::class . '.ALL_STATUSES', 'all statuses');
    }

    public function getLevelTitle(): string
    {
        return self::levelOptions()[$this->Level] ?? (string) $this->Level;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID']);
        $workflowID = (int) $this->WorkflowID;
        $choose = _t(Workflow::class . '.CHOOSE_STATUS', '– choose –');

        $fields->replaceField('FieldGroupID', DropdownField::create(
            'FieldGroupID',
            $this->fieldLabel('FieldGroup'),
            FieldGroup::get()->filter('WorkflowID', $workflowID)->map('ID', 'Title')
        )->setEmptyString($choose));
        $fields->replaceField('RoleID', DropdownField::create(
            'RoleID',
            $this->fieldLabel('Role'),
            FlowRole::get()->filter('WorkflowID', $workflowID)->map('ID', 'Title')
        )->setEmptyString($choose));
        $fields->replaceField('StatusID', DropdownField::create(
            'StatusID',
            $this->fieldLabel('Status'),
            Status::get()->filter('WorkflowID', $workflowID)->map('ID', 'Title')
        )->setEmptyString(_t(self::class . '.ALL_STATUSES', 'all statuses')));
        $fields->replaceField(
            'Level',
            DropdownField::create('Level', $this->fieldLabel('Level'), self::levelOptions())
        );

        return $fields;
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['FieldGroup.Title'] = _t(self::class . '.has_one_FieldGroup', 'Field group');
        $labels['Role.Title'] = _t(self::class . '.has_one_Role', 'Role');
        $labels['StatusTitle'] = _t(self::class . '.has_one_Status', 'Status');
        $labels['LevelTitle'] = _t(self::class . '.db_Level', 'Access');
        return $labels;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->WorkflowID) {
            $result->addError(_t(ConfigRecord::class . '.WORKFLOW_REQUIRED', 'The record must belong to a workflow.'));
            return $result;
        }
        $foreign = _t(ConfigRecord::class . '.FOREIGN_RECORD', 'Please choose a record of the same workflow.');
        $required = ['FieldGroupID' => 'FieldGroup', 'RoleID' => 'Role'];
        foreach ($required as $field => $relation) {
            if (!$this->$field) {
                $result->addFieldError($field, _t(self::class . '.REQUIRED', 'Please make a choice.'));
                continue;
            }
            $record = $this->$relation();
            if (!$record->exists() || (int) $record->WorkflowID !== (int) $this->WorkflowID) {
                $result->addFieldError($field, $foreign);
            }
        }
        if ($this->StatusID) {
            $status = $this->Status();
            if (!$status->exists() || (int) $status->WorkflowID !== (int) $this->WorkflowID) {
                $result->addFieldError('StatusID', $foreign);
            }
        }
        if (!in_array($this->Level, self::LEVELS, true)) {
            $result->addFieldError('Level', _t(self::class . '.REQUIRED', 'Please make a choice.'));
        }
        $duplicate = self::get()
            ->filter([
                'FieldGroupID' => (int) $this->FieldGroupID,
                'RoleID' => (int) $this->RoleID,
                'StatusID' => (int) $this->StatusID,
            ])
            ->exclude('ID', (int) $this->ID);
        if ($duplicate->exists()) {
            $result->addError(_t(
                self::class . '.DUPLICATE',
                'There already is an entry for this field group, role and status.'
            ));
        }
        return $result;
    }
}
