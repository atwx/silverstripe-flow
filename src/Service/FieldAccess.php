<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\FieldPermission;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * The single place that decides which fields of a case file an actor may see
 * and change. Forms, saving, the external link controller and exports all ask
 * this service.
 *
 * Rules:
 * - Permissions are granted per field group, role and status.
 * - An entry for the current status beats an entry for all statuses.
 * - Without an entry, a group is hidden.
 * - An actor with several roles gets the highest level of them.
 * - Fields that belong to no declared group are hidden.
 */
class FieldAccess
{
    use Injectable;

    /**
     * Access level per field group code.
     *
     * @return array<string, string>
     */
    public function groupLevels(DataObject $case, FlowActor $actor): array
    {
        $workflow = $this->workflowOf($case);
        if (!$workflow) {
            return [];
        }
        $groups = FieldGroup::get()->filter('WorkflowID', $workflow->ID)->map('ID', 'Code')->toArray();
        $levels = array_fill_keys(array_values($groups), FieldPermission::HIDDEN);

        $roleCodes = $actor->flowRoleCodes($workflow, $case);
        if ($roleCodes === [] || $groups === []) {
            return $levels;
        }
        $roleIDs = FlowRole::get()
            ->filter(['WorkflowID' => $workflow->ID, 'Code' => $roleCodes])
            ->column('ID');
        if ($roleIDs === []) {
            return $levels;
        }

        $statusID = (int) $case->getField('FlowStatusID');
        $permissions = FieldPermission::get()->filter([
            'WorkflowID' => $workflow->ID,
            'RoleID' => $roleIDs,
            'StatusID' => array_values(array_unique([0, $statusID])),
        ]);

        // Per group and role: the status-specific entry wins over the general one.
        $perRole = [];
        foreach ($permissions as $permission) {
            $key = $permission->FieldGroupID . ':' . $permission->RoleID;
            $specific = (int) $permission->StatusID !== 0;
            if (!isset($perRole[$key]) || $specific) {
                $perRole[$key] = [(int) $permission->FieldGroupID, (string) $permission->Level];
            }
        }
        // Across roles: the highest level wins.
        foreach ($perRole as [$groupID, $level]) {
            $code = $groups[$groupID] ?? null;
            if ($code !== null && FieldPermission::rank($level) > FieldPermission::rank($levels[$code])) {
                $levels[$code] = $level;
            }
        }
        return $levels;
    }

    /**
     * Access level per field name, for all fields in declared groups.
     *
     * @return array<string, string>
     */
    public function fieldLevels(DataObject $case, FlowActor $actor): array
    {
        $groupLevels = $this->groupLevels($case, $actor);
        $result = [];
        foreach (FieldGroup::declaredFor($case->ClassName) as $code => $definition) {
            $level = $groupLevels[$code] ?? FieldPermission::HIDDEN;
            foreach ($definition['fields'] as $field) {
                // A field listed in several groups gets the highest level.
                if (!isset($result[$field]) || FieldPermission::rank($level) > FieldPermission::rank($result[$field])) {
                    $result[$field] = $level;
                }
            }
        }
        return $result;
    }

    public function fieldLevel(DataObject $case, FlowActor $actor, string $field): string
    {
        return $this->fieldLevels($case, $actor)[$field] ?? FieldPermission::HIDDEN;
    }

    public function canRead(DataObject $case, FlowActor $actor, string $field): bool
    {
        return $this->fieldLevel($case, $actor, $field) !== FieldPermission::HIDDEN;
    }

    public function canWrite(DataObject $case, FlowActor $actor, string $field): bool
    {
        return $this->fieldLevel($case, $actor, $field) === FieldPermission::WRITE;
    }

    /**
     * Field names the actor may change.
     *
     * @return array<int, string>
     */
    public function writableFields(DataObject $case, FlowActor $actor): array
    {
        return array_keys(array_filter(
            $this->fieldLevels($case, $actor),
            fn (string $level) => $level === FieldPermission::WRITE
        ));
    }

    /**
     * Field names the actor may see (read or write).
     *
     * @return array<int, string>
     */
    public function readableFields(DataObject $case, FlowActor $actor): array
    {
        return array_keys(array_filter(
            $this->fieldLevels($case, $actor),
            fn (string $level) => $level !== FieldPermission::HIDDEN
        ));
    }

    /**
     * Keep only the submitted values the actor may write; everything else is
     * dropped silently (e.g. a manipulated POST on a locked field).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function filterWritable(DataObject $case, FlowActor $actor, array $data): array
    {
        return array_intersect_key($data, array_flip($this->writableFields($case, $actor)));
    }

    private function workflowOf(DataObject $case): ?Workflow
    {
        return $case->hasExtension(CaseFileExtension::class) ? Workflow::forClass($case->ClassName) : null;
    }
}
