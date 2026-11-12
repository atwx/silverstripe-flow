<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\AccessLink;
use Atwx\Flow\Model\Assignment;
use Atwx\Flow\Model\AssignmentChange;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\CaseMail;
use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\FieldPermission;
use Atwx\Flow\Model\Comment;
use Atwx\Flow\Model\LinkAccess;
use Atwx\Flow\Model\Note;
use Atwx\Flow\Model\Phase;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Service\AssignmentService;
use Atwx\Flow\Service\DocumentService;
use Atwx\Flow\Service\MailService;
use Atwx\Flow\Service\FieldAccess;
use Atwx\Flow\Ui\CustomFieldUi;
use Atwx\Flow\Ui\FlowFieldLayout;
use Atwx\Flow\Ui\FlowRecordActions;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Filter\FilterCollection;
use Atwx\SilverstripeFrontdeskKit\Filter\SelectFilter;
use Atwx\SilverstripeFrontdeskKit\Forms\FieldLayout;
use Atwx\SilverstripeFrontdeskKit\Table\ActionException;
use Atwx\SilverstripeFrontdeskKit\Table\Column;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\ListView;
use Atwx\SilverstripeFrontdeskKit\Table\RecordAction;
use Atwx\SilverstripeFrontdeskKit\Table\TimelineEntry;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

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
        $this->addCustomFieldColumns($columns);
        $this->addStatusColumns($columns);
        $this->addAssigneeColumns($columns);
        $this->protectColumns($columns);
        return $columns;
    }

    /**
     * Columns of the custom fields marked "Column in list" (also exported).
     */
    protected function addCustomFieldColumns(ColumnCollection $columns): void
    {
        foreach (CustomFieldUi::fieldsForClass($this->getManagedModel()) as $custom) {
            if (!$custom->ShowInList) {
                continue;
            }
            $code = (string) $custom->Code;
            $columns->make($custom->formFieldName(), (string) $custom->Title)
                ->format(function ($value, DataObject $record) use ($custom, $code) {
                    /** @var DataObject&CaseFileExtension $record */
                    return $custom->display($record->flowValue($code));
                })
                ->end();
        }
    }

    /**
     * One column per responsibility with the assigned member (column "Assignee_<code>").
     */
    protected function addAssigneeColumns(ColumnCollection $columns): void
    {
        foreach ($this->responsibilities() as $responsibility) {
            $code = (string) $responsibility->Code;
            $columns->make('Assignee_' . $code, (string) $responsibility->Title)
                ->format(fn ($value, DataObject $record) => (string) AssignmentService::singleton()
                    ->assignee($record, $responsibility)?->getName())
                ->end();
        }
    }

    /**
     * @return array<int, Responsibility>
     */
    protected function responsibilities(): array
    {
        return AssignmentService::singleton()->responsibilitiesFor($this->getManagedModel());
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
        $workflow = $this->flowWorkflow();
        $fields = [];
        foreach ($workflow ? FieldGroup::fieldsFor($workflow) : [] as $groupFields) {
            $fields = array_merge($fields, $groupFields);
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
        $filters
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
        foreach ($this->responsibilities() as $responsibility) {
            $filters->add(
                SelectFilter::create('Assignee_' . $responsibility->Code, (string) $responsibility->Title)
                    ->options(fn () => $responsibility->candidateOptions())
                    ->apply(fn ($list, $value) => $this->filterByAssignee(
                        $list,
                        [(int) $responsibility->ID],
                        (int) $value
                    ))
            );
        }
        foreach (CustomFieldUi::fieldsForClass($this->getManagedModel()) as $custom) {
            $filter = $this->customFieldFilter($custom);
            if ($filter) {
                $filters->add($filter);
            }
        }
        return $filters;
    }

    /**
     * Filter for a custom field of type choice or yes/no (on the JSON value).
     * Only offered to members who may read the field in some status.
     */
    /**
     * Case files assigned to the member in one of the responsibilities.
     *
     * @param DataList<DataObject> $list
     * @param array<int, int> $responsibilityIDs
     * @return DataList<DataObject>
     */
    protected function filterByAssignee(DataList $list, array $responsibilityIDs, int $memberID): DataList
    {
        $ids = Assignment::get()->filter([
            'CaseClass' => $this->getManagedModel(),
            'ResponsibilityID' => $responsibilityIDs ?: [0],
            'MemberID' => $memberID,
        ])->column('CaseID');
        return $list->filter('ID', $ids ?: [0]);
    }

    /**
     * "Meine Fälle": case files the current member is responsible for.
     *
     * @return array<int, ListView>
     */
    protected function defineListViews(): array
    {
        $views = parent::defineListViews();
        $ids = array_map(fn (Responsibility $r) => (int) $r->ID, $this->responsibilities());
        if ($ids) {
            $views[] = ListView::create('mine', _t(self::class . '.VIEW_MINE', 'My case files'))
                ->query(fn (DataList $list) => $this->filterByAssignee(
                    $list,
                    $ids,
                    (int) Security::getCurrentUser()?->ID
                ));
        }
        return $views;
    }

    protected function customFieldFilter(CustomField $custom): ?SelectFilter
    {
        $filterable = in_array($custom->Type, ['select', 'catalog', 'checkbox'], true);
        if (!$filterable || !$this->mayEverRead($custom->formFieldName())) {
            return null;
        }
        $options = $custom->Type === 'checkbox'
            ? ['1' => _t(CustomField::class . '.YES', 'Yes'), '0' => _t(CustomField::class . '.NO', 'No')]
            : $custom->labels();
        $class = $this->getManagedModel();
        $table = DataObject::getSchema()->tableForField($class, 'FlowData');
        $path = '$.' . $custom->Code;
        $isCheckbox = $custom->Type === 'checkbox';
        return SelectFilter::create($custom->formFieldName(), (string) $custom->Title)
            ->options($options)
            ->searchable($custom->Type === 'catalog')
            ->apply(function ($list, $value) use ($table, $path, $isCheckbox) {
                $column = sprintf('JSON_VALUE("%s"."FlowData", ?)', $table);
                if ($isCheckbox) {
                    // JSON true/false comes back as 1/0 (MariaDB) or true/false (MySQL).
                    return $value === '1'
                        ? $list->where([$column . " IN ('1', 'true')" => [$path]])
                        : $list->where([sprintf("COALESCE(%s, '0') IN ('0', 'false')", $column) => [$path]]);
                }
                return $list->where([$column . ' = ?' => [$path, (string) $value]]);
            });
    }

    /**
     * Whether the current member may read the field in at least one status
     * (used to offer filters on custom fields).
     */
    private function mayEverRead(string $field): bool
    {
        $actor = $this->flowActor();
        $workflow = $this->flowWorkflow();
        if (!$actor || !$workflow) {
            return false;
        }
        $class = $this->getManagedModel();
        foreach (Status::get()->filter('WorkflowID', $workflow->ID) as $status) {
            $record = $class::create();
            $record->setField('FlowStatusID', $status->ID);
            if (FieldAccess::singleton()->canRead($record, $actor, $field)) {
                return true;
            }
        }
        return false;
    }

    // ─── Form and view: custom fields ────────────────────────────────────────

    protected function formFields(FieldList $fields): FieldList
    {
        $fields = parent::formFields($fields);
        $id = $this->currentRecordID();
        $record = $id ? $this->findRecord($id, 'canEdit') : null;
        $class = $this->getManagedModel();
        return CustomFieldUi::addFormFields($fields, $record ?? $class::singleton());
    }

    protected function viewFieldRows(DataObject $record): array
    {
        return array_merge(parent::viewFieldRows($record), CustomFieldUi::viewRows($record));
    }

    // ─── Record actions and edit links ───────────────────────────────────────

    private static array $sub_controllers = [
        AccessLinkSubController::class,
        CaseDocumentSubController::class,
        CommentSubController::class,
        ChecklistSubController::class,
        CaseMailSubController::class,
    ];

    private static array $allowed_actions = [
        'links',
        'documents',
        'comments',
        'checklist',
        'mails',
    ];

    public function mails(HTTPRequest $request): CaseMailSubController
    {
        return CaseMailSubController::create()->setParentContext($this, (int) $request->param('ID'));
    }

    /**
     * Whether the current member may write mails from the case file: a role in the
     * workflow (the edit permission is checked by the caller).
     */
    public function mayWriteMails(DataObject $case): bool
    {
        $actor = $this->flowActor();
        $workflow = $this->flowWorkflow();
        return $actor && $workflow && $actor->flowRoleCodes($workflow, $case) !== [];
    }

    public function links(HTTPRequest $request): AccessLinkSubController
    {
        return AccessLinkSubController::create()->setParentContext($this, (int) $request->param('ID'));
    }

    public function checklist(HTTPRequest $request): ChecklistSubController
    {
        return ChecklistSubController::create()->setParentContext($this, (int) $request->param('ID'));
    }

    public function comments(HTTPRequest $request): CommentSubController
    {
        return CommentSubController::create()->setParentContext($this, (int) $request->param('ID'));
    }

    public function documents(HTTPRequest $request): CaseDocumentSubController
    {
        return CaseDocumentSubController::create()->setParentContext($this, (int) $request->param('ID'));
    }

    /**
     * A case file of this controller's list the member may view (null otherwise).
     */
    public function findCaseFile(int $id): ?DataObject
    {
        return $this->findRecord($id, 'canView');
    }

    /**
     * Edit links are only listed for members who may edit (also on record pages).
     */
    protected function canShowSubController(string $class, DataObject $record): bool
    {
        if (is_a($class, AccessLinkSubController::class, true)) {
            return $this->canEdit();
        }
        return parent::canShowSubController($class, $record);
    }

    /**
     * Status and phase in the header of a record view with record pages.
     *
     * @return ArrayList<ArrayData>|null
     */
    protected function defineHeaderFields(DataObject $record): ?ArrayList
    {
        if (!$record->hasExtension(CaseFileExtension::class)) {
            return null;
        }
        /** @var DataObject&CaseFileExtension $record */
        $status = $record->FlowStatus();
        if (!$status->exists()) {
            return null;
        }
        $fields = ArrayList::create([ArrayData::create([
            'Label' => _t(self::class . '.STATUS', 'Status'),
            'Value' => $status->Title,
        ])]);
        $phase = $status->Phase();
        if ($phase->exists()) {
            $fields->push(ArrayData::create([
                'Label' => _t(self::class . '.PHASE', 'Phase'),
                'Value' => $phase->Title,
            ]));
        }
        foreach ($this->responsibilities() as $responsibility) {
            $assignee = AssignmentService::singleton()->assignee($record, $responsibility);
            if ($assignee) {
                $fields->push(ArrayData::create([
                    'Label' => $responsibility->Title,
                    'Value' => $assignee->getName(),
                ]));
            }
        }
        return $fields;
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
            if ($item->Segment === ChecklistSubController::config()->get('url_segment')) {
                $item->Title = _t(ChecklistSubController::class . '.TITLE', 'Checklist');
            }
            if ($item->Segment === CommentSubController::config()->get('url_segment')) {
                $item->Title = _t(CommentSubController::class . '.TITLE', 'Comments');
            }
            if ($item->Segment === CaseDocumentSubController::config()->get('url_segment')) {
                $item->Title = _t(CaseDocumentSubController::class . '.TITLE', 'Documents');
            }
            if ($item->Segment === CaseMailSubController::config()->get('url_segment')) {
                $item->Title = _t(CaseMailSubController::class . '.TITLE', 'Mails');
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
        $document = $this->documentAction($record);
        if ($document) {
            $actions[] = $document;
        }
        $mail = $this->mailAction($record);
        if ($mail) {
            $actions[] = $mail;
        }
        $assign = $this->assignAction($record);
        if ($assign) {
            $actions[] = $assign;
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
    /**
     * "Create document": one option per template, for members who may edit and
     * have a role in the workflow.
     */
    protected function documentAction(DataObject $record): ?RecordAction
    {
        $templates = DocumentService::singleton()->templatesFor($record);
        $actor = $this->flowActor();
        $workflow = $this->flowWorkflow();
        if (!$templates || !$actor || !$workflow || !$record->isInDB() || !$this->canEdit()) {
            return null;
        }
        if ($actor->flowRoleCodes($workflow, $record) === []) {
            return null;
        }
        $options = [];
        foreach ($templates as $template) {
            $options[(string) $template->ID] = (string) $template->Title;
        }
        return RecordAction::create('flowdocument', _t(self::class . '.DOCUMENT', 'Create document'))
            ->options($options)
            ->submitLabel(_t(self::class . '.DOCUMENT_CREATE', 'Create'))
            ->handle(function (DataObject $record, ?string $option) {
                $template = DocumentTemplate::get()->byID((int) $option);
                if (!$template) {
                    throw new ActionException(_t(self::class . '.DOCUMENT_UNKNOWN', 'Unknown template.'));
                }
                $document = DocumentService::singleton()->create($record, $template, Security::getCurrentUser());
                return _t(
                    self::class . '.DOCUMENT_CREATED',
                    'Document "{title}" created.',
                    ['title' => $document->Title]
                );
            });
    }

    /**
     * "Write mail": choose a template, then the mail form (preview, edit, send).
     */
    protected function mailAction(DataObject $record): ?RecordAction
    {
        $templates = MailService::singleton()->templatesFor($record);
        if (!$templates || !$record->isInDB() || !$this->canEdit() || !$this->mayWriteMails($record)) {
            return null;
        }
        $options = [];
        foreach ($templates as $template) {
            $options[(string) $template->ID] = (string) $template->Title;
        }
        return RecordAction::create('flowmail', _t(self::class . '.MAIL', 'Write mail'))
            ->options($options)
            ->submitLabel(_t(self::class . '.MAIL_COMPOSE', 'Continue'))
            ->handle(fn (DataObject $record, ?string $option) => $this->redirect(
                $this->Link('mails/' . $record->ID . '/compose') . '?template=' . (int) $option
            ));
    }

    /**
     * "Responsibility": one select per responsibility, for members who may edit.
     */
    protected function assignAction(DataObject $record): ?RecordAction
    {
        $responsibilities = $this->responsibilities();
        $actor = $this->flowActor();
        $workflow = $this->flowWorkflow();
        if (!$responsibilities || !$actor || !$workflow || !$record->isInDB() || !$this->canEdit()) {
            return null;
        }
        // Like status changes: only members with a role in the workflow.
        if ($actor->flowRoleCodes($workflow, $record) === []) {
            return null;
        }
        $action = RecordAction::create('flowassign', _t(self::class . '.ASSIGN', 'Responsibility'))
            ->submitLabel(_t(self::class . '.ASSIGN_SAVE', 'Save'));
        foreach ($responsibilities as $responsibility) {
            $assignee = AssignmentService::singleton()->assignee($record, $responsibility);
            $action->select(
                (string) $responsibility->Code,
                (string) $responsibility->Title,
                $responsibility->candidateOptions(),
                $assignee ? (string) $assignee->ID : '',
                _t(self::class . '.ASSIGN_NOBODY', '– nobody –')
            );
        }
        return $action->handle(function (
            DataObject $record,
            ?string $option,
            string $comment,
            array $values
        ) use ($responsibilities) {
            $service = AssignmentService::singleton();
            $changed = false;
            foreach ($responsibilities as $responsibility) {
                $value = (int) ($values[$responsibility->Code] ?? 0);
                $member = $value ? Member::get()->byID($value) : null;
                $changed = $service->assign($record, $responsibility, $member, Security::getCurrentUser()) || $changed;
            }
            return $changed
                ? _t(self::class . '.ASSIGN_DONE', 'Responsibility changed.')
                : _t(self::class . '.ASSIGN_UNCHANGED', 'Nothing changed.');
        });
    }

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
        $comments = Comment::get()->filter(['RecordClass' => $record->ClassName, 'RecordID' => $record->ID]);
        foreach ($comments as $comment) {
            $creator = $comment->CreatedBy();
            $title = $comment->DueDate
                ? _t(self::class . '.TIMELINE_COMMENT_DATED', '{kind} on {date}: {text}', [
                    'kind' => $comment->getKindLabel(),
                    'date' => $comment->getDueLabel(),
                    'text' => $comment->Text,
                ])
                : _t(self::class . '.TIMELINE_COMMENT', '{kind}: {text}', [
                    'kind' => $comment->getKindLabel(),
                    'text' => $comment->Text,
                ]);
            $entries->push(TimelineEntry::create(
                (string) $comment->Created,
                $title,
                '',
                $creator->exists() ? (string) $creator->getName() : '',
                'comment'
            ));
            if ($comment->IsDone && $comment->DoneAt) {
                $doneBy = $comment->DoneBy();
                $entries->push(TimelineEntry::create(
                    (string) $comment->DoneAt,
                    _t(self::class . '.TIMELINE_COMMENT_DONE', 'Done: {text}', ['text' => $comment->Text]),
                    '',
                    $doneBy->exists() ? (string) $doneBy->getName() : '',
                    'comment'
                ));
            }
        }
        foreach (CaseDocument::get()->filter($case) as $document) {
            $member = $document->Member();
            $entries->push(TimelineEntry::create(
                (string) $document->Created,
                _t(self::class . '.TIMELINE_DOCUMENT', 'Document created: {title}', ['title' => $document->Title]),
                '',
                $member->exists() ? (string) $member->getName() : '',
                'document'
            ));
        }
        foreach (CaseMail::get()->filter($case) as $mail) {
            $entries->push($this->mailEntry($mail));
        }
        foreach (AssignmentChange::get()->filter($case) as $change) {
            $entries->push($this->assignmentChangeEntry($change));
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

    /**
     * Timeline entry of a mail: subject, recipients and the full text (failed
     * mails with the error).
     */
    protected function mailEntry(CaseMail $mail): ArrayData
    {
        $subject = ['subject' => $mail->Subject];
        $title = match ((string) $mail->Mode) {
            CaseMail::FAILED => _t(self::class . '.TIMELINE_MAIL_FAILED', 'Mail not sent: {subject}', $subject),
            CaseMail::EML => _t(
                self::class . '.TIMELINE_MAIL_EML',
                'Mail created for the mail program: {subject}',
                $subject
            ),
            default => _t(self::class . '.TIMELINE_MAIL_SENT', 'Mail sent: {subject}', $subject),
        };
        $labels = $mail->fieldLabels();
        $text = $labels['Recipients'] . ': ' . $mail->Recipients;
        if ($mail->Cc) {
            $text .= "\n" . $labels['Cc'] . ': ' . $mail->Cc;
        }
        if ($mail->AttachmentNames) {
            $text .= "\n" . $labels['AttachmentNames'] . ': ' . $mail->AttachmentNames;
        }
        if ($mail->Mode === CaseMail::FAILED && $mail->Error) {
            $text .= "\n" . $labels['Error'] . ': ' . $mail->Error;
        }
        $text .= "\n\n" . $mail->Body;
        return TimelineEntry::create((string) $mail->Created, $title, $text, $mail->getSenderName(), 'mail');
    }

    protected function assignmentChangeEntry(AssignmentChange $change): ArrayData
    {
        $nobody = _t(self::class . '.ASSIGN_NOBODY_SHORT', 'nobody');
        $from = $change->From();
        $to = $change->To();
        $member = $change->Member();
        $responsibility = $change->Responsibility();
        return TimelineEntry::create(
            (string) $change->Created,
            _t(self::class . '.TIMELINE_ASSIGN', '{responsibility}: {from} → {to}', [
                'responsibility' => $responsibility->exists() ? $responsibility->Title : '?',
                'from' => $from->exists() ? $from->getName() : $nobody,
                'to' => $to->exists() ? $to->getName() : $nobody,
            ]),
            '',
            $member->exists() ? (string) $member->getName() : '',
            'assignment'
        );
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
