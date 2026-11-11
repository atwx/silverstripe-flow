<?php

namespace Atwx\Flow\Model;

use Atwx\Flow\Security\FlowPermissions;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * A group of fields of the case file class; field permissions are granted per group.
 *
 * Which fields belong to a group is declared in code on the domain class; the
 * records only carry title and order and are created from the declaration:
 *
 *   private static array $flow_field_groups = [
 *       'basics' => ['title' => 'Eckdaten', 'fields' => ['Title', 'Date', 'Venue']],
 *       'announcement' => ['Description', 'Audience'],   // short form, title = code
 *   ];
 *
 * @property string $Code
 * @property string $Title
 * @property int $Sort
 * @property int $WorkflowID
 * @method Workflow Workflow()
 * @method HasManyList<FieldPermission> Permissions()
 * @method HasManyList<CustomField> CustomFields()
 */
class FieldGroup extends DataObject
{
    use ConfigRecord;

    /**
     * Name of the configuration property on the domain class.
     */
    public const string CONFIG = 'flow_field_groups';

    private static string $table_name = 'Flow_FieldGroup';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
    ];

    private static array $has_many = [
        'Permissions' => FieldPermission::class,
        'CustomFields' => CustomField::class,
    ];

    private static array $cascade_deletes = [
        'Permissions',
        'CustomFields',
    ];

    private static array $indexes = [
        'WorkflowCode' => ['type' => 'unique', 'columns' => ['WorkflowID', 'Code']],
    ];

    private static array $summary_fields = [
        'Sort',
        'Title',
        'Code',
        'FieldNames',
    ];

    private static string $default_sort = 'Sort ASC, ID ASC';

    /**
     * Field groups declared by a class, keyed by code.
     *
     * @return array<string, array{title: string, fields: array<int, string>}>
     */
    public static function declaredFor(string $class): array
    {
        $declared = Config::inst()->get($class, self::CONFIG) ?: [];
        $groups = [];
        foreach ($declared as $code => $definition) {
            $fields = $definition['fields'] ?? $definition;
            $groups[(string) $code] = [
                'title' => (string) ($definition['title'] ?? $code),
                'fields' => array_values(array_filter(array_map('strval', (array) $fields))),
            ];
        }
        return $groups;
    }

    /**
     * Fields per group code of a workflow: the fields declared by the class and
     * the custom fields of the group (as form field names "Flow_<code>").
     *
     * @return array<string, array<int, string>>
     */
    public static function fieldsFor(Workflow $workflow): array
    {
        $fields = [];
        foreach (self::declaredFor((string) $workflow->CaseClass) as $code => $definition) {
            $fields[$code] = $definition['fields'];
        }
        $groupCodes = self::get()->filter('WorkflowID', $workflow->ID)->map('ID', 'Code')->toArray();
        foreach (CustomField::get()->filter('WorkflowID', $workflow->ID) as $custom) {
            $code = $groupCodes[$custom->FieldGroupID] ?? null;
            if ($code !== null) {
                $fields[$code][] = $custom->formFieldName();
            }
        }
        return $fields;
    }

    /**
     * Create the declared groups of the workflow's class that do not exist yet.
     */
    public static function syncWorkflow(Workflow $workflow): void
    {
        if (!$workflow->isInDB() || !$workflow->CaseClass || !class_exists($workflow->CaseClass)) {
            return;
        }
        $sort = 0;
        foreach (self::declaredFor($workflow->CaseClass) as $code => $definition) {
            $sort++;
            $exists = self::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $code])->exists();
            if (!$exists) {
                self::create([
                    'WorkflowID' => $workflow->ID,
                    'Code' => $code,
                    'Title' => $definition['title'],
                    'Sort' => $sort,
                ])->write();
            }
        }
    }

    /**
     * Fields of this group according to the declaration.
     *
     * @return array<int, string>
     */
    public function declaredFieldNames(): array
    {
        $class = $this->caseClass();
        return $class ? (self::declaredFor($class)[$this->Code]['fields'] ?? []) : [];
    }

    private function caseClass(): string
    {
        $workflow = $this->Workflow();
        return $workflow->exists() ? (string) $workflow->CaseClass : '';
    }

    public function getFieldNames(): string
    {
        return implode(', ', $this->declaredFieldNames());
    }

    public function isDeclared(): bool
    {
        $class = $this->caseClass();
        return $class && isset(self::declaredFor($class)[$this->Code]);
    }

    /**
     * Groups come from code; they are created by the sync, not in the CMS.
     *
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    /**
     * Only groups no longer declared in code can be removed.
     */
    public function canDelete($member = null): bool
    {
        return !$this->isDeclared() && FlowPermissions::canConfigure($member);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID', 'Permissions', 'CustomFields']);
        $this->lockCode($fields);
        return $fields;
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['FieldNames'] = _t(self::class . '.FieldNames', 'Fields');
        return $labels;
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
