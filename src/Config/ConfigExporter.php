<?php

namespace Atwx\Flow\Config;

use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Phase;
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
        return Yaml::dump($this->export($codes), 5, 2, Yaml::DUMP_COMPACT_NESTED_MAPPING);
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

        return $data;
    }
}
