<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\Comment;
use Atwx\Flow\Model\CommentReference;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Workflow;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use Atwx\SilverstripeFrontdeskKit\Catalog\Catalogs;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\RowAction;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\Validation\RequiredFieldsValidator;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\SecurityToken;

/**
 * Comments of a record: list (upcoming dates first, then important ones, then
 * the rest), add, edit, mark as done, delete. Part of every FlowCaseController;
 * other controllers get it with CommentsControllerExtension.
 */
class CommentSubController extends FrontdeskSubController
{
    private static string $managed_model = Comment::class;

    private static string $url_segment = 'comments';

    private static string $title = 'Kommentare';

    /**
     * 'table': the comments as a sub-list table; 'stream': as a stream
     * (meta line, kind and responsible member, text; appointments and
     * important ones first and highlighted).
     */
    private static string $display = 'table';

    /**
     * Stream only: area "Open (n)" above the stream with the open to-dos,
     * follow-ups and appointments, each with a checkbox to mark it as done.
     */
    private static bool $stream_open = true;

    /**
     * Stream only: icon and label of the comment type on each entry, filter
     * chips "All" + types. Off: no types in the stream and in the form.
     */
    private static bool $stream_types = true;

    /**
     * References to further records (the comment record's
     * flowCommentReferences()): multiple choice in the form, labels on the
     * entries and a select field as filter in the stream.
     */
    private static bool $stream_references = true;

    /**
     * Stream only: switch "Show done" (done entries are hidden by default).
     */
    private static bool $stream_done_toggle = true;

    /**
     * Stream only: row action "Follow-up" that turns an entry into a
     * follow-up (date and member responsible).
     */
    private static bool $stream_followup_action = true;

    private static array $allowed_actions = [
        'deleteselected' => '->canEdit',
        'toggledone' => '->canEdit',
        'followup' => '->canEdit',
        'FollowUpForm' => '->canEdit',
    ];

    /**
     * The record the comments belong to (null when the parent does not exist).
     */
    public function commentRecord(): ?DataObject
    {
        $parent = $this->parentController;
        $class = (string) $parent?->getManagedModel();
        if (!$parent || $class === '' || !class_exists($class)) {
            return null;
        }
        $record = DataObject::get_by_id($class, $this->getParentID());
        if (!$record) {
            return null;
        }
        $record = Comment::recordFor($record);
        if ($parent->hasMethod('commentRecordFor')) {
            $record = $parent->commentRecordFor($record);
        }
        return $record instanceof DataObject && $record->exists() ? $record : null;
    }

    /**
     * @return DataList<Comment>
     */
    protected function getBaseQuery(): DataList
    {
        $record = $this->commentRecord();
        $list = $record ? Comment::forRecord($record) : Comment::get()->filter('ID', 0);
        return $this->isStream() ? Comment::streamOrder($list) : Comment::inListOrder($list);
    }

    /**
     * The stream lists what is not under "Open" (and no done entries unless
     * shown), filtered by type and reference. Finding a record for editing
     * uses the base query without these filters.
     *
     * @return DataList<Comment>
     */
    protected function getQuery(): DataList
    {
        $list = parent::getQuery();
        if (!$this->isStream()) {
            return $list;
        }
        $list = $this->applyStreamFilters($list);
        if ($this->streamFeature('stream_open')) {
            $list = $list->exclude(['Kind' => Comment::OPEN_KINDS, 'IsDone' => 0]);
        }
        if ($this->streamFeature('stream_done_toggle') && !$this->showsDone()) {
            $list = $list->filter('IsDone', 0);
        }
        return $list;
    }

    public function isStream(): bool
    {
        return static::config()->get('display') === 'stream';
    }

    /**
     * Whether a stream feature (config stream_*) is on; always off in the
     * table display. References also count for the table display (form).
     */
    public function streamFeature(string $name): bool
    {
        if (!static::config()->get($name)) {
            return false;
        }
        return $name === 'stream_references' || $this->isStream();
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function ShowTypes(): bool
    {
        return $this->streamFeature('stream_types');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function ShowReferences(): bool
    {
        return $this->streamFeature('stream_references') && $this->referenceOptions() !== [];
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function ShowOpen(): bool
    {
        return $this->streamFeature('stream_open');
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function ShowDoneToggle(): bool
    {
        return $this->streamFeature('stream_done_toggle');
    }

    public function showsDone(): bool
    {
        return (bool) $this->getRequest()->getVar('done');
    }

    /**
     * Filters of the stream from the query string: type=<catalog code>,
     * ref=<reference key>.
     *
     * @param DataList<Comment> $list
     * @return DataList<Comment>
     */
    protected function applyStreamFilters(DataList $list): DataList
    {
        $request = $this->getRequest();
        $type = (string) $request->getVar('type');
        if ($type !== '' && $this->streamFeature('stream_types')) {
            $entry = Catalogs::singleton()->all(Comment::TYPE_CATALOG)->find('Code', $type);
            $list = $list->filter('TypeID', $entry ? (int) $entry->ID : 0);
        }
        $ref = CommentReference::parseKey((string) $request->getVar('ref'));
        if ($ref && $this->streamFeature('stream_references')) {
            $list = $list->filter(['References.TargetClass' => $ref[0], 'References.TargetID' => $ref[1]]);
        }
        return $list;
    }

    /**
     * Open to-dos, follow-ups and appointments of the record (stream filters
     * applied), earliest date first.
     *
     * @return DataList<Comment>
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function OpenItems(): DataList
    {
        $record = $this->commentRecord();
        $list = $record ? Comment::forRecord($record) : Comment::get()->filter('ID', 0);
        return $this->applyStreamFilters($list)
            ->filter(['Kind' => Comment::OPEN_KINDS, 'IsDone' => 0])
            ->orderBy('CASE WHEN "Flow_Comment"."DueDate" IS NULL THEN 1 ELSE 0 END, "Flow_Comment"."DueDate" ASC,'
                . ' "Flow_Comment"."DueTime" ASC, "Flow_Comment"."ID" ASC');
    }

    /**
     * Link to the stream with the current filters, changed by $change (null
     * removes a parameter).
     *
     * @param array<string, string|null> $change
     */
    public function streamLink(array $change = [], ?string $action = null): string
    {
        $keep = array_keys(array_filter([
            'type' => $this->streamFeature('stream_types'),
            'ref' => $this->streamFeature('stream_references'),
            'done' => $this->streamFeature('stream_done_toggle'),
        ]));
        $vars = array_intersect_key($this->getRequest()->getVars(), array_flip($keep));
        foreach ($change as $key => $value) {
            if ($value === null || $value === '') {
                unset($vars[$key]);
            } else {
                $vars[$key] = $value;
            }
        }
        $query = http_build_query($vars);
        return $this->Link($action) . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Filter chips: "All" and the active comment types.
     *
     * @return ArrayList<ArrayData>
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function TypeChips(): ArrayList
    {
        $chips = ArrayList::create();
        if (!$this->ShowTypes()) {
            return $chips;
        }
        $current = (string) $this->getRequest()->getVar('type');
        $chips->push(ArrayData::create([
            'Title' => _t(self::class . '.FILTER_ALL', 'All'),
            'Icon' => '',
            'Link' => $this->streamLink(['type' => null]),
            'Active' => $current === '',
        ]));
        foreach (Catalogs::singleton()->all(Comment::TYPE_CATALOG)->filter('Active', true) as $entry) {
            $chips->push(ArrayData::create([
                'Title' => $entry->getLabel(),
                'Icon' => (string) $entry->getField('Icon'),
                'Link' => $this->streamLink(['type' => (string) $entry->Code]),
                'Active' => $current === (string) $entry->Code,
            ]));
        }
        return $chips->count() > 2 ? $chips : ArrayList::create();
    }

    /**
     * Options of the reference filter (the first one: all).
     *
     * @return ArrayList<ArrayData>
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function ReferenceFilter(): ArrayList
    {
        $options = ArrayList::create();
        if (!$this->ShowReferences() || !$this->isStream()) {
            return $options;
        }
        $current = (string) $this->getRequest()->getVar('ref');
        $options->push(ArrayData::create([
            'Title' => $this->ReferencesTitle() . ': ' . _t(self::class . '.FILTER_ALL_REFERENCES', 'All'),
            'Link' => $this->streamLink(['ref' => null]),
            'Selected' => $current === '',
        ]));
        foreach ($this->referenceOptions() as $key => $target) {
            $options->push(ArrayData::create([
                'Title' => $target->getTitle(),
                'Link' => $this->streamLink(['ref' => $key]),
                'Selected' => $current === $key,
            ]));
        }
        return $options;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function ReferencesTitle(): string
    {
        $record = $this->commentRecord();
        return $record ? Comment::referencesTitleFor($record) : '';
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function DoneToggleLink(): string
    {
        return $this->streamLink(['done' => $this->showsDone() ? null : '1']);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function EditLinkFor(int $id): string
    {
        return $this->Link('edit/' . $id);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function ToggleDoneLink(int $id): string
    {
        return $this->streamLink([], 'toggledone/' . $id);
    }

    /**
     * Records the comments may refer to (see Comment::referenceOptionsFor()).
     *
     * @return array<string, DataObject>
     */
    public function referenceOptions(): array
    {
        $record = $this->commentRecord();
        return $record && static::config()->get('stream_references') ? Comment::referenceOptionsFor($record) : [];
    }

    /**
     * Marks an entry as done or open again (POST with security token) and
     * returns the stream (HTMX) or goes back to the record.
     */
    public function toggledone(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !SecurityToken::inst()->checkRequest($request)) {
            return $this->httpError(400);
        }
        /** @var Comment|null $comment */
        $comment = $this->findRecord((int) $request->param('ID'), 'canEdit');
        if (!$comment) {
            return $this->httpError(404);
        }
        $comment->IsDone = !$comment->IsDone;
        $comment->write();
        if ($this->isHtmxRequest()) {
            return $this->renderStream();
        }
        return $this->redirect($this->afterSaveLink());
    }

    /**
     * Dialog "Follow-up" for an entry: date and member responsible.
     *
     * @return mixed
     */
    public function followup(HTTPRequest $request)
    {
        /** @var Comment|null $comment */
        $comment = $this->findRecord((int) $request->param('ID'), 'canEdit');
        if (!$comment) {
            return $this->httpError(404);
        }
        $form = $this->FollowUpForm();
        $form->loadDataFrom([
            'ID' => $comment->ID,
            'DueDate' => $comment->DueDate,
            'MemberID' => $comment->MemberID ?: (int) \SilverStripe\Security\Security::getCurrentUser()?->ID,
        ]);
        $title = _t(self::class . '.FOLLOW_UP_TITLE', 'Follow-up');
        if ($this->isHtmxRequest()) {
            return $this->renderPartial(
                'Atwx\\SilverstripeFrontdeskKit\\Includes\\EditFormModal',
                ['Form' => $form, 'Title' => $title, 'Item' => $comment]
            );
        }
        return ['Title' => $title, 'Form' => $form, 'Actions' => false];
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- form name
    public function FollowUpForm(): Form
    {
        $labels = Comment::singleton()->fieldLabels();
        $fields = FieldList::create(
            HiddenField::create('ID'),
            DateField::create('DueDate', _t(self::class . '.FOLLOW_UP_DATE', 'Follow up on')),
            DropdownField::create('MemberID', $labels['MemberID'], $this->memberOptions())->setEmptyString('–')
        );
        $form = Form::create($this, 'FollowUpForm', $fields, FieldList::create(
            FormAction::create('doFollowUp', _t(FrontdeskController::class . '.ACTION_SAVE', 'Save')),
            LiteralField::create('Cancel', '<a href="javascript:void(0);" onclick="var d=this.closest(\'dialog\'); '
                . 'if(d){d.close();return false;} history.back();" class="btn">'
                . _t(FrontdeskController::class . '.ACTION_CANCEL', 'Cancel') . '</a>')
        ), RequiredFieldsValidator::create(['DueDate']));
        $form->setFormAction($this->Link('FollowUpForm'));
        return $form;
    }

    /**
     * Turns the entry into an open follow-up with the date and member.
     *
     * @param array<string, mixed> $data
     */
    public function doFollowUp(array $data, Form $form): HTTPResponse
    {
        /** @var Comment|null $comment */
        $comment = $this->findRecord((int) ($data['ID'] ?? 0), 'canEdit');
        if (!$comment) {
            return $this->httpError(404);
        }
        $options = $this->memberOptions();
        $memberID = (int) ($data['MemberID'] ?? 0);
        $comment->Kind = Comment::FOLLOW_UP;
        $comment->IsDone = false;
        $comment->DueDate = (string) $data['DueDate'];
        $comment->MemberID = isset($options[$memberID]) ? $memberID : 0;
        $comment->write();
        $this->flash(_t(self::class . '.FOLLOW_UP_SAVED', 'Follow-up set.'));
        return $this->redirect($this->afterSaveLink());
    }

    /**
     * Edit and delete, plus "Follow-up" in the stream (not for open follow-ups).
     *
     * @return array<int, RowAction>
     */
    protected function defineRowActions(DataObject $record): array
    {
        $actions = parent::defineRowActions($record);
        $openFollowUp = $record instanceof Comment && $record->Kind === Comment::FOLLOW_UP && !$record->IsDone;
        if ($this->streamFeature('stream_followup_action') && $this->canEdit() && !$openFollowUp) {
            $label = _t(self::class . '.ACTION_FOLLOW_UP', 'Follow-up');
            array_splice($actions, 1, 0, [
                RowAction::htmx($label, $this->Link('followup/' . $record->ID))->withIcon('alarm-clock')->direct(),
            ]);
        }
        return $actions;
    }

    /**
     * The comments as a stream for HTMX requests in stream display.
     *
     * @return mixed
     */
    public function index(HTTPRequest $request)
    {
        if ($this->isStream() && $this->isHtmxRequest() && !$this->isTab()) {
            return $this->renderStream();
        }
        return parent::index($request);
    }

    /**
     * Id of the form that deletes the selected comments.
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function StreamBulkForm(): string
    {
        return 'fdk-comment-bulk-' . $this->getParentID();
    }

    /**
     * Deletes the selected comments (ids[], POST with security token); only
     * those the member may delete.
     */
    public function deleteselected(HTTPRequest $request): HTTPResponse
    {
        if (!$request->isPOST() || !SecurityToken::inst()->checkRequest($request)) {
            return $this->httpError(400);
        }
        $count = 0;
        foreach ((array) $request->postVar('ids') as $id) {
            $comment = $this->findRecord((int) $id, 'canDelete');
            if ($comment) {
                $this->beforeDelete($comment);
                $comment->delete();
                $this->afterDelete($comment);
                $count++;
            }
        }
        $message = _t(self::class . '.DELETED_COUNT', '{count} comments deleted.', ['count' => $count]);
        if ($this->isHtmxRequest()) {
            return $this->renderStream($message);
        }
        $this->flash($message);
        return $this->redirect($this->afterSaveLink());
    }

    protected function renderStream(string $message = ''): HTTPResponse
    {
        return $this->renderPartial('Atwx\\Flow\\Includes\\CommentStream', [
            'Items' => $this->getItems(),
            'Message' => $message,
        ]);
    }

    protected function defineColumns(): ColumnCollection
    {
        return FollowUpColumns::forRecord();
    }

    protected function formFields(FieldList $fields): FieldList
    {
        $fields = parent::formFields($fields);
        $fields->removeByName([
            'RecordID', 'RecordClass', 'Record', 'RuleID', 'CreatedByID', 'LastEditedByID', 'DoneByID', 'DoneAt',
        ]);
        $labels = Comment::singleton()->fieldLabels();
        $fields->replaceField('Kind', DropdownField::create('Kind', $labels['Kind'], Comment::kindOptions()));
        $fields->replaceField('Text', TextareaField::create('Text', $labels['Text'])
            ->setRows(5)
            ->setAttribute('maxlength', (string) Comment::config()->get('max_length')));
        $fields->replaceField('MemberID', DropdownField::create(
            'MemberID',
            $labels['MemberID'],
            $this->memberOptions()
        )->setEmptyString('–'));
        // TimeField shows midnight when empty (and would save it): a plain time input stays empty.
        $fields->replaceField('DueTime', TextField::create('DueTime', $labels['DueTime'])->setInputType('time'));
        if ($this->isStream() && !static::config()->get('stream_types')) {
            $fields->removeByName('TypeID');
        }
        $references = $this->referenceOptions();
        if ($references) {
            $current = $this->currentRecordID() ? Comment::get()->byID($this->currentRecordID()) : null;
            $fields->push(CheckboxSetField::create(
                'FlowReferences',
                $this->ReferencesTitle(),
                array_map(fn (DataObject $target) => (string) $target->getTitle(), $references)
            )->setValue($current ? $current->referenceKeys() : []));
            $fields->push(HiddenField::create('FlowReferencesShown', '', '1'));
        }
        // Order: kind and type, text, references, date and time, responsible, marks.
        $order = ['TypeID', 'Text', 'FlowReferences', 'DueDate', 'DueTime', 'MemberID', 'Important', 'IsDone'];
        foreach ($order as $name) {
            $field = $fields->dataFieldByName($name);
            if ($field) {
                $fields->removeByName($name);
                $fields->push($field);
            }
        }
        return $fields;
    }

    /**
     * The polymorphic relation needs the class of the record, too.
     *
     * @param array<string, mixed> $data
     */
    protected function beforeWrite(DataObject $item, array $data): void
    {
        parent::beforeWrite($item, $data);
        $record = $this->commentRecord();
        if (!$item->isInDB() && $record) {
            $item->setField('RecordClass', $record->ClassName);
            $item->setField('RecordID', $record->ID);
        }
    }

    /**
     * Saves the references chosen in the form (only offered ones).
     *
     * @param array<string, mixed> $data
     */
    protected function afterWrite(DataObject $item, array $data): void
    {
        parent::afterWrite($item, $data);
        if (!$item instanceof Comment || empty($data['FlowReferencesShown'])) {
            return;
        }
        $options = $this->referenceOptions();
        $keys = array_map('strval', (array) ($data['FlowReferences'] ?? []));
        $chosen = array_intersect_key($options, array_flip($keys));
        $item->setReferences($chosen);
    }

    /**
     * Members who may edit in the parent controller: the internal roles of the
     * workflow for case files, otherwise the members with the parent's edit
     * permission.
     *
     * @return array<int, string>
     */
    protected function memberOptions(): array
    {
        $parent = $this->parentController;
        $workflow = Workflow::forClass((string) $parent?->getManagedModel());
        if ($workflow) {
            $groupIDs = FlowRole::get()
                ->filter(['WorkflowID' => $workflow->ID, 'IsExternal' => false])
                ->column('GroupID');
            $members = $groupIDs ? Member::get()->filter('Groups.ID', $groupIDs) : null;
        } else {
            $code = $parent ? $parent->getPermissionCode('EDIT') : '';
            $members = $code !== '' ? Permission::get_members_by_permission($code) : null;
        }
        $options = [];
        foreach ($members ?? [] as $member) {
            $options[(int) $member->ID] = (string) $member->getName();
        }
        asort($options);
        return $options;
    }
}
