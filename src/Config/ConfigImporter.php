<?php

namespace Atwx\Flow\Config;

use Atwx\Flow\Model\ChecklistItem;
use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\FollowUpRule;
use Atwx\Flow\Model\FieldPermission;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Phase;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\Transition;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Imports workflow configuration from YAML (see README for the format).
 *
 * Records are matched by their code and created or updated; nothing is deleted.
 * Groups of internal roles are matched by their code and created if missing.
 * The whole import runs in one transaction: an invalid entry imports nothing.
 */
class ConfigImporter
{
    use Injectable;

    /**
     * Counts of created and updated records, keyed by type.
     *
     * @var array<string, array{created: int, updated: int}>
     */
    private array $stats = [];

    /**
     * @return array<string, array{created: int, updated: int}>
     */
    public function importFile(string $path): array
    {
        if (!is_readable($path)) {
            throw new ConfigException("File not found: {$path}");
        }
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new ConfigException("Invalid YAML in {$path}: " . $e->getMessage(), 0, $e);
        }
        return $this->import(is_array($data) ? $data : []);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, array{created: int, updated: int}>
     */
    public function import(array $data): array
    {
        $this->stats = [];
        $workflows = $data['workflows'] ?? null;
        if (!is_array($workflows)) {
            throw new ConfigException('The file must contain a list "workflows".');
        }
        DB::get_conn()->withTransaction(function () use ($workflows) {
            foreach ($workflows as $index => $definition) {
                $this->importWorkflow($this->map($definition, "workflows[{$index}]"), "workflows[{$index}]");
            }
        });
        return $this->stats;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importWorkflow(array $definition, string $path): void
    {
        $code = $this->string($definition, 'code', $path);
        $path = "workflow {$code}";

        $workflow = Workflow::get()->find('Code', $code) ?? Workflow::create(['Code' => $code]);
        $workflow->Title = $this->string($definition, 'title', $path);
        $workflow->CaseClass = $this->string($definition, 'class', $path);
        $this->save($workflow, $path);

        // Order matters: statuses need phases, transitions need statuses and roles.
        $sections = [
            'roles' => $this->importRole(...),
            'responsibilities' => $this->importResponsibility(...),
            'phases' => $this->importPhase(...),
            'statuses' => $this->importStatus(...),
            'transitions' => $this->importTransition(...),
            'field_groups' => $this->importFieldGroup(...),
            'custom_fields' => $this->importCustomField(...),
            'documents' => $this->importDocumentTemplate(...),
            'follow_ups' => $this->importFollowUpRule(...),
            'checklist' => $this->importChecklistItem(...),
            'permissions' => $this->importPermission(...),
        ];
        foreach ($sections as $key => $importer) {
            foreach ($this->list($definition, $key, $path) as $index => $entry) {
                $entryPath = "{$path}, {$key}[{$index}]";
                $importer($workflow, $this->map($entry, $entryPath), $entryPath, $index);
            }
        }

        $initial = $definition['initial_status'] ?? null;
        $workflow->InitialStatusID = $initial === null
            ? 0
            : $this->status($workflow, (string) $initial, "{$path}, initial_status")->ID;
        $this->save($workflow, $path, false);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importRole(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $code = $this->string($definition, 'code', $path);
        $role = $this->child(FlowRole::class, $workflow, $code);
        $role->Title = $this->string($definition, 'title', $path);
        $role->IsExternal = (bool) ($definition['external'] ?? false);
        $role->Sort = $index + 1;
        $role->GroupID = 0;
        if (isset($definition['group'])) {
            $role->GroupID = $this->group((string) $definition['group'], $role->Title)->ID;
        }
        $this->save($role, "{$path} ({$code})");
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importResponsibility(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $code = $this->string($definition, 'code', $path);
        $responsibility = $this->child(Responsibility::class, $workflow, $code);
        $responsibility->Title = $this->string($definition, 'title', $path);
        $roleCode = $this->string($definition, 'role', $path);
        $role = FlowRole::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $roleCode])->first();
        if (!$role) {
            throw new ConfigException("{$path} ({$code}): unknown role \"{$roleCode}\".");
        }
        $responsibility->RoleID = $role->ID;
        $responsibility->Sort = $index + 1;
        $this->save($responsibility, "{$path} ({$code})");
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importPhase(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $code = $this->string($definition, 'code', $path);
        $phase = $this->child(Phase::class, $workflow, $code);
        $phase->Title = $this->string($definition, 'title', $path);
        $phase->Sort = $index + 1;
        $this->save($phase, "{$path} ({$code})");
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importStatus(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $code = $this->string($definition, 'code', $path);
        $status = $this->child(Status::class, $workflow, $code);
        $status->Title = $this->string($definition, 'title', $path);
        $status->IsFinal = (bool) ($definition['final'] ?? false);
        $status->Sort = $index + 1;
        $phaseCode = $this->string($definition, 'phase', $path);
        $phase = Phase::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $phaseCode])->first();
        if (!$phase) {
            throw new ConfigException("{$path} ({$code}): unknown phase \"{$phaseCode}\".");
        }
        $status->PhaseID = $phase->ID;
        $this->save($status, "{$path} ({$code})");
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importTransition(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $from = $this->status($workflow, $this->string($definition, 'from', $path), $path);
        $to = $this->status($workflow, $this->string($definition, 'to', $path), $path);
        $path .= " ({$from->Code} → {$to->Code})";

        $transition = Transition::get()->filter(['FromID' => $from->ID, 'ToID' => $to->ID])->first()
            ?? Transition::create(['WorkflowID' => $workflow->ID, 'FromID' => $from->ID, 'ToID' => $to->ID]);
        $transition->Title = (string) ($definition['title'] ?? '');
        $transition->Sort = $index + 1;
        $this->save($transition, $path);

        $roleIDs = [];
        foreach ($this->list($definition, 'roles', $path) as $roleCode) {
            $role = FlowRole::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => (string) $roleCode])->first();
            if (!$role) {
                throw new ConfigException("{$path}: unknown role \"{$roleCode}\".");
            }
            $roleIDs[] = $role->ID;
        }
        $transition->Roles()->setByIDList($roleIDs);
    }

    /**
     * Field groups are declared in code; the file sets title and order.
     *
     * @param array<string, mixed> $definition
     */
    private function importFieldGroup(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $code = $this->string($definition, 'code', $path);
        $group = $this->child(FieldGroup::class, $workflow, $code);
        if (!$group->isInDB() && !isset(FieldGroup::declaredFor($workflow->CaseClass)[$code])) {
            throw new ConfigException("{$path} ({$code}): field group is not declared by {$workflow->CaseClass}.");
        }
        $group->Title = $this->string($definition, 'title', $path);
        $group->Sort = $index + 1;
        $this->save($group, "{$path} ({$code})");
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importCustomField(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $code = $this->string($definition, 'code', $path);
        $custom = $this->child(CustomField::class, $workflow, $code);
        $custom->Title = $this->string($definition, 'title', $path);
        $custom->Type = $this->string($definition, 'type', $path);
        $groupCode = $this->string($definition, 'group', $path);
        $group = FieldGroup::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $groupCode])->first();
        if (!$group) {
            throw new ConfigException("{$path} ({$code}): unknown field group \"{$groupCode}\".");
        }
        $custom->FieldGroupID = $group->ID;
        $options = $definition['options'] ?? [];
        if (!is_array($options)) {
            throw new ConfigException("{$path} ({$code}): \"options\" must be a mapping.");
        }
        $custom->setOptionMap(array_map('strval', $options));
        $custom->Catalog = (string) ($definition['catalog'] ?? '');
        $custom->ShowInList = (bool) ($definition['list'] ?? false);
        $custom->Sort = $index + 1;
        $this->save($custom, "{$path} ({$code})");
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importDocumentTemplate(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $code = $this->string($definition, 'code', $path);
        $template = $this->child(DocumentTemplate::class, $workflow, $code);
        $template->Title = $this->string($definition, 'title', $path);
        $template->DocumentTitle = (string) ($definition['document_title'] ?? '');
        $template->Body = $this->string($definition, 'body', $path);
        $template->Sort = $index + 1;
        $this->save($template, "{$path} ({$code})");
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importFollowUpRule(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $code = $this->string($definition, 'code', $path);
        $rule = $this->child(FollowUpRule::class, $workflow, $code);
        $rule->Title = $this->string($definition, 'title', $path);
        $rule->StatusID = $this->status($workflow, $this->string($definition, 'status', $path), $path)->ID;
        $rule->Days = (int) ($definition['days'] ?? 0);
        $rule->CloseOnLeave = (bool) ($definition['close_on_leave'] ?? true);
        $rule->ResponsibilityID = 0;
        if (isset($definition['responsibility'])) {
            $responsibility = Responsibility::get()->filter([
                'WorkflowID' => $workflow->ID,
                'Code' => (string) $definition['responsibility'],
            ])->first();
            if (!$responsibility) {
                $unknown = (string) $definition['responsibility'];
                throw new ConfigException("{$path} ({$code}): unknown responsibility \"{$unknown}\".");
            }
            $rule->ResponsibilityID = $responsibility->ID;
        }
        $rule->Sort = $index + 1;
        $this->save($rule, "{$path} ({$code})");
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importChecklistItem(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $code = $this->string($definition, 'code', $path);
        $item = $this->child(ChecklistItem::class, $workflow, $code);
        $item->Title = $this->string($definition, 'title', $path);
        $item->RequiredBeforeID = isset($definition['required_before'])
            ? $this->status($workflow, (string) $definition['required_before'], $path)->ID
            : 0;
        $file = (string) ($definition['file'] ?? 'none');
        if (!array_key_exists($file, ChecklistItem::fileModeOptions())) {
            throw new ConfigException("{$path} ({$code}): file must be optional, required or none.");
        }
        $item->FileMode = $file;
        $item->Sort = $index + 1;
        $this->save($item, "{$path} ({$code})");
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function importPermission(Workflow $workflow, array $definition, string $path, int $index): void
    {
        $groupCode = $this->string($definition, 'group', $path);
        $group = FieldGroup::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $groupCode])->first();
        if (!$group) {
            throw new ConfigException("{$path}: unknown field group \"{$groupCode}\".");
        }
        $roleCode = $this->string($definition, 'role', $path);
        $role = FlowRole::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $roleCode])->first();
        if (!$role) {
            throw new ConfigException("{$path}: unknown role \"{$roleCode}\".");
        }
        $statusID = isset($definition['status'])
            ? $this->status($workflow, (string) $definition['status'], $path)->ID
            : 0;

        $filter = ['FieldGroupID' => $group->ID, 'RoleID' => $role->ID, 'StatusID' => $statusID];
        $permission = FieldPermission::get()->filter($filter)->first()
            ?? FieldPermission::create($filter + ['WorkflowID' => $workflow->ID]);
        $permission->Level = $this->string($definition, 'level', $path);
        $this->save($permission, $path);
    }

    /**
     * Find a record of the workflow by code, or a new one.
     *
     * @template T of DataObject
     * @param class-string<T> $class
     * @return T
     */
    private function child(string $class, Workflow $workflow, string $code): DataObject
    {
        return $class::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $code])->first()
            ?? $class::create(['WorkflowID' => $workflow->ID, 'Code' => $code]);
    }

    private function status(Workflow $workflow, string $code, string $path): Status
    {
        $status = Status::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $code])->first();
        if (!$status) {
            throw new ConfigException("{$path}: unknown status \"{$code}\".");
        }
        return $status;
    }

    private function group(string $code, string $title): Group
    {
        $group = Group::get()->find('Code', $code);
        if (!$group) {
            $group = Group::create(['Title' => $title, 'Code' => $code]);
            $group->write();
            $this->count(Group::class, true);
        }
        return $group;
    }

    private function save(DataObject $record, string $path, bool $count = true): void
    {
        $isNew = !$record->isInDB();
        $changed = $isNew || $record->isChanged(null, DataObject::CHANGE_VALUE);
        try {
            $record->write();
        } catch (ValidationException $e) {
            throw new ConfigException("{$path}: " . $e->getMessage(), 0, $e);
        }
        if ($count && $changed) {
            $this->count($record::class, $isNew);
        }
    }

    private function count(string $class, bool $created): void
    {
        $type = substr(strrchr($class, '\\') ?: $class, 1) ?: $class;
        $this->stats[$type] ??= ['created' => 0, 'updated' => 0];
        $this->stats[$type][$created ? 'created' : 'updated']++;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function string(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_scalar($value) || (string) $value === '') {
            throw new ConfigException("{$path}: \"{$key}\" is missing.");
        }
        return (string) $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, mixed>
     */
    private function list(array $data, string $key, string $path): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new ConfigException("{$path}: \"{$key}\" must be a list.");
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function map(mixed $value, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new ConfigException("{$path}: expected a mapping.");
        }
        return $value;
    }
}
