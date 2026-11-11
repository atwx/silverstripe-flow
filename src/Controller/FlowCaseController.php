<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\FieldPermission;
use Atwx\Flow\Model\Note;
use Atwx\Flow\Model\Phase;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Service\FieldAccess;
use Atwx\Flow\Service\TransitionService;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Filter\FilterCollection;
use Atwx\SilverstripeFrontdeskKit\Filter\SelectFilter;
use Atwx\SilverstripeFrontdeskKit\Forms\FieldLayout;
use Atwx\SilverstripeFrontdeskKit\Table\Column;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\RecordAction;
use Atwx\SilverstripeFrontdeskKit\Table\TimelineEntry;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;

/**
 * Frontdesk controller for case files.
 *
 * On top of the kit's list, form and view it adds:
 * - fields arranged by field group, with the access level of the current
 *   member from the permission matrix (FieldAccess); saving resets changes to
 *   fields that are not writable,
 * - status and phase as columns and filters; columns of fields the member may
 *   not see stay empty (also in the export),
 * - "Change status" with the transitions available to the member,
 * - the status history as timeline.
 *
 * Projects extend it like any frontdesk controller (managed_model, url_segment,
 * title) and may add to the timeline via updateFlowTimeline().
 */
abstract class FlowCaseController extends FrontdeskController
{
    /**
     * Field levels per status (memo for list columns).
     *
     * @var array<int, array<string, string>>
     */
    private array $levelsByStatus = [];

    protected function flowActor(): ?FlowActor
    {
        return MemberActor::current();
    }

    protected function flowWorkflow(): ?Workflow
    {
        return Workflow::forClass($this->getManagedModel());
    }

    // ─── Form and view ───────────────────────────────────────────────────────

    protected function defineFieldLayout(?DataObject $record): ?FieldLayout
    {
        $workflow = $this->flowWorkflow();
        if (!$workflow) {
            return null;
        }
        if (!$record) {
            // A new record starts in the initial status; rights are those of that status.
            $class = $this->getManagedModel();
            $record = $class::create();
            $record->setField('FlowStatusID', (int) $workflow->InitialStatusID);
        }

        $layout = FieldLayout::create()->setDefaultLevel(FieldLayout::HIDDEN);
        $declared = FieldGroup::declaredFor($record->ClassName);
        foreach (FieldGroup::get()->filter('WorkflowID', $workflow->ID) as $group) {
            if (isset($declared[$group->Code])) {
                $layout->group((string) $group->Title, $declared[$group->Code]['fields']);
            }
        }
        $actor = $this->flowActor();
        if ($actor) {
            $layout->setLevels(FieldAccess::singleton()->fieldLevels($record, $actor));
        }
        return $layout;
    }

    // ─── List ────────────────────────────────────────────────────────────────

    protected function defineColumns(): ColumnCollection
    {
        $columns = parent::defineColumns();
        $this->addStatusColumns($columns);
        $this->protectColumns($columns);
        return $columns;
    }

    protected function addStatusColumns(ColumnCollection $columns): void
    {
        $columns->make('FlowStatus.Title', _t(self::class . '.STATUS', 'Status'))->type('badge')->end();
        $columns->make('FlowStatus.Phase.Title', _t(self::class . '.PHASE', 'Phase'))->end();
    }

    /**
     * Cells of fields the member may not read stay empty (list and export).
     * Columns that are no declared field (e.g. status, computed values) are
     * left alone.
     */
    protected function protectColumns(ColumnCollection $columns): void
    {
        $model = DataObject::singleton($this->getManagedModel());
        $fields = [];
        foreach (FieldGroup::declaredFor($model::class) as $group) {
            $fields = array_merge($fields, $group['fields']);
        }
        foreach ($columns as $column) {
            /** @var Column $column */
            $field = $this->fieldForColumn($column->getName(), $model, $fields);
            if ($field !== null) {
                $column->visibleWhen(fn (DataObject $record) => $this->canReadField($record, $field));
            }
        }
    }

    /**
     * @param array<int, string> $fields
     */
    private function fieldForColumn(string $name, DataObject $model, array $fields): ?string
    {
        if (in_array($name, $fields, true)) {
            return $name;
        }
        $relation = strtok($name, '.');
        if ($relation !== $name && DataObject::getSchema()->hasOneComponent($model::class, (string) $relation)) {
            return in_array($relation . 'ID', $fields, true) ? $relation . 'ID' : null;
        }
        return null;
    }

    private function canReadField(DataObject $record, string $field): bool
    {
        $actor = $this->flowActor();
        if (!$actor) {
            return false;
        }
        $statusID = (int) $record->getField('FlowStatusID');
        $this->levelsByStatus[$statusID] ??= FieldAccess::singleton()->fieldLevels($record, $actor);
        return ($this->levelsByStatus[$statusID][$field] ?? FieldPermission::HIDDEN) !== FieldPermission::HIDDEN;
    }

    protected function defineFilters(): FilterCollection
    {
        $filters = parent::defineFilters();
        $workflow = $this->flowWorkflow();
        if (!$workflow) {
            return $filters;
        }
        $workflowID = $workflow->ID;
        return $filters
            ->add(
                SelectFilter::create('FlowPhase', _t(self::class . '.PHASE', 'Phase'))
                    ->options(fn () => Phase::get()->filter('WorkflowID', $workflowID)->map('ID', 'Title')->toArray())
                    ->apply(fn ($list, $value) => $list->filter('FlowStatus.PhaseID', (int) $value))
            )
            ->add(
                SelectFilter::create('FlowStatus', _t(self::class . '.STATUS', 'Status'))
                    ->options(fn () => Status::get()->filter('WorkflowID', $workflowID)->map('ID', 'Title')->toArray())
                    ->apply(fn ($list, $value) => $list->filter('FlowStatusID', (int) $value))
            );
    }

    // ─── Status change ───────────────────────────────────────────────────────

    protected function defineRecordActions(DataObject $record): array
    {
        $actions = parent::defineRecordActions($record);
        $actor = $this->flowActor();
        if (!$actor) {
            return $actions;
        }
        $status = $this->statusAction($record, $actor);
        if ($status) {
            $actions[] = $status;
        }
        if ($record->hasMethod('canAddFlowNote') && $record->canAddFlowNote($actor)) {
            $actions[] = RecordAction::create('flownote', _t(self::class . '.ADD_NOTE', 'Add note'))
                ->withComment(_t(self::class . '.NOTE', 'Note'), true)
                ->submitLabel(_t(self::class . '.SAVE_NOTE', 'Save note'))
                ->handle(function (DataObject $record, ?string $option, string $text) use ($actor) {
                    /** @var DataObject&CaseFileExtension $record */
                    $record->addFlowNote($text, $actor);
                    return _t(self::class . '.NOTE_ADDED', 'The note has been added.');
                });
        }
        return $actions;
    }

    /**
     * "Change status" with the transitions the actor may perform, or null if none.
     */
    protected function statusAction(DataObject $record, FlowActor $actor): ?RecordAction
    {
        $options = [];
        foreach (TransitionService::singleton()->availableTransitions($record, $actor) as $transition) {
            $to = $transition->To();
            if ($to->exists()) {
                $options[$to->Code] = $transition->getLabel();
            }
        }
        if ($options === []) {
            return null;
        }
        return RecordAction::create('flowstatus', _t(self::class . '.CHANGE_STATUS', 'Change status'))
            ->options($options)
            ->withComment(_t(self::class . '.COMMENT', 'Comment (optional)'))
            ->submitLabel(_t(self::class . '.CHANGE_STATUS', 'Change status'))
            ->handle(function (DataObject $record, ?string $option, string $comment) use ($actor) {
                $change = TransitionService::singleton()->transition($record, (string) $option, $actor, $comment);
                $to = $change->To();
                return _t(
                    self::class . '.STATUS_CHANGED',
                    'The status is now "{status}".',
                    ['status' => $to->exists() ? $to->Title : '']
                );
            });
    }

    // ─── Timeline ────────────────────────────────────────────────────────────

    /**
     * @return ArrayList<ArrayData>
     */
    protected function defineTimeline(DataObject $record): ?ArrayList
    {
        $entries = ArrayList::create();
        $changes = StatusChange::get()->filter(['CaseClass' => $record->ClassName, 'CaseID' => $record->ID]);
        foreach ($changes as $change) {
            $entries->push($this->statusChangeEntry($change));
        }
        $notes = Note::get()->filter(['CaseClass' => $record->ClassName, 'CaseID' => $record->ID]);
        foreach ($notes as $note) {
            $member = $note->Member();
            $entries->push(TimelineEntry::create(
                (string) $note->Created,
                _t(self::class . '.NOTE', 'Note'),
                (string) $note->Text,
                $member->exists() ? (string) $member->getName() : '',
                'note'
            ));
        }
        $this->extend('updateFlowTimeline', $entries, $record);
        return $entries;
    }

    protected function statusChangeEntry(StatusChange $change): ArrayData
    {
        $from = $change->From();
        $to = $change->To();
        $toTitle = $to->exists() ? (string) $to->Title : '?';
        $title = $from->exists()
            ? _t(self::class . '.TIMELINE_CHANGE', 'Status: {from} → {to}', ['from' => $from->Title, 'to' => $toTitle])
            : _t(self::class . '.TIMELINE_CREATED', 'Created, status: {to}', ['to' => $toTitle]);
        $member = $change->Member();
        return TimelineEntry::create(
            (string) $change->Created,
            $title,
            (string) $change->Comment,
            $member->exists() ? (string) $member->getName() : '',
            'status'
        );
    }

    protected function timelineTitle(): string
    {
        return _t(self::class . '.TIMELINE', 'History');
    }
}
