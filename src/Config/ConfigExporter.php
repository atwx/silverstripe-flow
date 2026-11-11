<?php

namespace Atwx\Flow\Config;

use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\FieldPermission;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Phase;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\Transition;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Core\Injector\Injectable;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports workflow configuration in the import format.
 *
 * The output is canonical (fixed key order, defaults omitted), so exporting an
 * imported file reproduces it as long as the file is written the same way.
 */
class ConfigExporter
{
    use Injectable;

    /**
     * @param array<int, string> $codes Workflow codes to export; all if empty
     */
    public function toYaml(array $codes = []): string
    {
        return Yaml::dump(
            $this->export($codes),
            5,
            2,
            Yaml::DUMP_COMPACT_NESTED_MAPPING | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
        );
    }

    /**
     * @param array<int, string> $codes Workflow codes to export; all if empty
     * @return array{workflows: array<int, array<string, mixed>>}
     */
    public function export(array $codes = []): array
    {
        $workflows = Workflow::get()->sort('Code');
        if ($codes !== []) {
            $workflows = $workflows->filter('Code', $codes);
        }
        $result = [];
        foreach ($workflows as $workflow) {
            $result[] = $this->exportWorkflow($workflow);
        }
        return ['workflows' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function exportWorkflow(Workflow $workflow): array
    {
        $data = [
            'code' => $workflow->Code,
            'title' => $workflow->Title,
            'class' => $workflow->CaseClass,
        ];
        $initial = $workflow->InitialStatus();
        if ($initial->exists()) {
            $data['initial_status'] = $initial->Code;
        }

        $data['roles'] = [];
        foreach (FlowRole::get()->filter('WorkflowID', $workflow->ID) as $role) {
            $entry = ['code' => $role->Code, 'title' => $role->Title];
            if ($role->IsExternal) {
                $entry['external'] = true;
            }
            $group = $role->Group();
            if ($group->exists()) {
                $entry['group'] = $group->Code;
            }
            $data['roles'][] = $entry;
        }

        // Only written when there are responsibilities, so older files stay unchanged.
        $responsibilities = [];
        foreach (Responsibility::get()->filter('WorkflowID', $workflow->ID) as $responsibility) {
            $role = $responsibility->Role();
            $responsibilities[] = [
                'code' => $responsibility->Code,
                'title' => $responsibility->Title,
                'role' => $role->exists() ? $role->Code : '',
            ];
        }
        if ($responsibilities) {
            $data['responsibilities'] = $responsibilities;
        }

        $data['phases'] = [];
        foreach (Phase::get()->filter('WorkflowID', $workflow->ID) as $phase) {
            $data['phases'][] = ['code' => $phase->Code, 'title' => $phase->Title];
        }

        $data['statuses'] = [];
        foreach (Status::get()->filter('WorkflowID', $workflow->ID) as $status) {
            $phase = $status->Phase();
            $entry = [
                'code' => $status->Code,
                'title' => $status->Title,
                'phase' => $phase->exists() ? $phase->Code : '',
            ];
            if ($status->IsFinal) {
                $entry['final'] = true;
            }
            $data['statuses'][] = $entry;
        }

        $data['transitions'] = [];
        foreach (Transition::get()->filter('WorkflowID', $workflow->ID) as $transition) {
            $from = $transition->From();
            $to = $transition->To();
            $entry = [
                'from' => $from->exists() ? $from->Code : '',
                'to' => $to->exists() ? $to->Code : '',
            ];
            if ($transition->Title) {
                $entry['title'] = $transition->Title;
            }
            $entry['roles'] = $transition->allowedRoles()->sort(['Sort' => 'ASC', 'ID' => 'ASC'])->column('Code');
            $data['transitions'][] = $entry;
        }

        $data['field_groups'] = [];
        $groupSort = [];
        foreach (FieldGroup::get()->filter('WorkflowID', $workflow->ID) as $group) {
            $data['field_groups'][] = ['code' => $group->Code, 'title' => $group->Title];
            $groupSort[$group->ID] = count($groupSort);
        }

        $data['custom_fields'] = [];
        foreach (CustomField::get()->filter('WorkflowID', $workflow->ID) as $custom) {
            $group = $custom->FieldGroup();
            $entry = [
                'code' => $custom->Code,
                'title' => $custom->Title,
                'type' => $custom->Type,
                'group' => $group->exists() ? $group->Code : '',
            ];
            if ($custom->optionMap() !== []) {
                $entry['options'] = $custom->optionMap();
            }
            if ($custom->ShowInList) {
                $entry['list'] = true;
            }
            $data['custom_fields'][] = $entry;
        }

        // Only written when there are templates, so older files stay unchanged.
        $documents = [];
        foreach (DocumentTemplate::get()->filter('WorkflowID', $workflow->ID) as $template) {
            $entry = ['code' => $template->Code, 'title' => $template->Title];
            if ($template->DocumentTitle) {
                $entry['document_title'] = $template->DocumentTitle;
            }
            $entry['body'] = (string) $template->Body;
            $documents[] = $entry;
        }
        if ($documents) {
            $data['documents'] = $documents;
        }

        $roleSort = array_flip(FlowRole::get()->filter('WorkflowID', $workflow->ID)->column('ID'));
        $statusSort = array_flip(Status::get()->filter('WorkflowID', $workflow->ID)->column('ID'));
        $permissions = FieldPermission::get()->filter('WorkflowID', $workflow->ID)->toArray();
        usort($permissions, fn (FieldPermission $a, FieldPermission $b) => [
            $groupSort[$a->FieldGroupID] ?? PHP_INT_MAX,
            $roleSort[$a->RoleID] ?? PHP_INT_MAX,
            $a->StatusID ? ($statusSort[$a->StatusID] ?? PHP_INT_MAX) : -1,
        ] <=> [
            $groupSort[$b->FieldGroupID] ?? PHP_INT_MAX,
            $roleSort[$b->RoleID] ?? PHP_INT_MAX,
            $b->StatusID ? ($statusSort[$b->StatusID] ?? PHP_INT_MAX) : -1,
        ]);
        $data['permissions'] = [];
        foreach ($permissions as $permission) {
            $group = $permission->FieldGroup();
            $role = $permission->Role();
            if (!$group->exists() || !$role->exists()) {
                continue;
            }
            $entry = ['group' => $group->Code, 'role' => $role->Code];
            $status = $permission->Status();
            if ($status->exists()) {
                $entry['status'] = $status->Code;
            }
            $entry['level'] = $permission->Level;
            $data['permissions'][] = $entry;
        }

        return $data;
    }
}
