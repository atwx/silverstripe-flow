<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Model\AccessLink;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\FieldPermission;
use Atwx\Flow\Model\LinkAccess;
use Atwx\Flow\Model\Note;
use Atwx\Flow\Model\Phase;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Service\FieldAccess;
use Atwx\Flow\Ui\FlowFieldLayout;
use Atwx\Flow\Ui\FlowRecordActions;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Filter\FilterCollection;
use Atwx\SilverstripeFrontdeskKit\Filter\SelectFilter;
use Atwx\SilverstripeFrontdeskKit\Forms\FieldLayout;
use Atwx\SilverstripeFrontdeskKit\Table\Column;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\RecordAction;
use Atwx\SilverstripeFrontdeskKit\Table\TimelineEntry;
use SilverStripe\Control\HTTPRequest;
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
        return FlowFieldLayout::build($record, $this->flowActor());
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

    // ─── Record actions and edit links ───────────────────────────────────────

    private static array $sub_controllers = [
        AccessLinkSubController::class,
    ];

    private static array $allowed_actions = [
        'links',
    ];

    public function links(HTTPRequest $request): AccessLinkSubController
    {
        return AccessLinkSubController::create()->setParentContext($this, (int) $request->param('ID'));
    }

    /**
     * Only members who may edit see the edit links.
     *
     * @return ArrayList<ArrayData>
     */
    protected function getSubControllerData(DataObject $record): ArrayList
    {
        $data = parent::getSubControllerData($record);
        $segment = AccessLinkSubController::config()->get('url_segment');
        if (!$this->canEdit()) {
            return $data->exclude('Segment', $segment);
        }
        foreach ($data as $item) {
            if ($item->Segment === $segment) {
                $item->Title = _t(AccessLinkSubController::class . '.TITLE', 'Edit links');
            }
        }
        return $data;
    }

    protected function defineRecordActions(DataObject $record): array
    {
        $actions = parent::defineRecordActions($record);
        $actor = $this->flowActor();
        if ($actor) {
            $actions = array_merge($actions, FlowRecordActions::forActor($record, $actor));
        }
        $revoke = $this->revokeLinkAction($record);
        if ($revoke) {
            $actions[] = $revoke;
        }
        return $actions;
    }

    /**
     * "Revoke edit link" with the usable links of the case file, for members who may edit.
     */
    protected function revokeLinkAction(DataObject $record): ?RecordAction
    {
        if (!$this->canEdit()) {
            return null;
        }
        $options = [];
        $links = AccessLink::get()->filter(['CaseClass' => $record->ClassName, 'CaseID' => $record->ID]);
        foreach ($links as $link) {
            if ($link->isUsable()) {
                $options[(string) $link->ID] = $link->getLabel();
            }
        }
        if ($options === []) {
            return null;
        }
        return RecordAction::create('flowrevoke', _t(self::class . '.REVOKE_LINK', 'Revoke edit link'))
            ->options($options)
            ->description(_t(self::class . '.REVOKE_DESCRIPTION', 'The link stops working immediately.'))
            ->handle(function (DataObject $record, ?string $option) {
                $link = AccessLink::get()
                    ->filter(['CaseClass' => $record->ClassName, 'CaseID' => $record->ID])
                    ->byID((int) $option);
                $link?->revoke();
                return _t(self::class . '.LINK_REVOKED', 'The edit link has been revoked.');
            });
    }

    // ─── Timeline ────────────────────────────────────────────────────────────

    /**
     * @return ArrayList<ArrayData>
     */
    protected function defineTimeline(DataObject $record): ?ArrayList
    {
        $entries = ArrayList::create();
        $case = ['CaseClass' => $record->ClassName, 'CaseID' => $record->ID];
        foreach (StatusChange::get()->filter($case) as $change) {
            $entries->push($this->statusChangeEntry($change));
        }
        foreach (Note::get()->filter($case) as $note) {
            $entries->push(TimelineEntry::create(
                (string) $note->Created,
                _t(self::class . '.NOTE', 'Note'),
                (string) $note->Text,
                $note->getActorName(),
                'note'
            ));
        }
        foreach (AccessLink::get()->filter($case) as $link) {
            $creator = $link->CreatedBy();
            $entries->push(TimelineEntry::create(
                (string) $link->Created,
                _t(
                    self::class . '.TIMELINE_LINK_CREATED',
                    'Edit link created for {name}',
                    ['name' => $link->getLabel()]
                ),
                '',
                $creator->exists() ? (string) $creator->getName() : '',
                'link'
            ));
            if ($link->RevokedAt) {
                $entries->push(TimelineEntry::create(
                    (string) $link->RevokedAt,
                    _t(
                        self::class . '.TIMELINE_LINK_REVOKED',
                        'Edit link revoked: {name}',
                        ['name' => $link->getLabel()]
                    ),
                    '',
                    '',
                    'link'
                ));
            }
            foreach ($link->Accesses() as $access) {
                $saved = $access->Kind === LinkAccess::SAVE;
                $entries->push(TimelineEntry::create(
                    (string) $access->Created,
                    $saved
                        ? _t(self::class . '.TIMELINE_LINK_SAVED', 'Edited via link')
                        : _t(self::class . '.TIMELINE_LINK_VIEWED', 'Opened via link'),
                    $saved ? (string) $access->Fields : '',
                    $link->getLabel(),
                    'access'
                ));
            }
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
        return TimelineEntry::create(
            (string) $change->Created,
            $title,
            (string) $change->Comment,
            $change->getActorName(),
            'status'
        );
    }

    protected function timelineTitle(): string
    {
        return _t(self::class . '.TIMELINE', 'History');
    }
}
