<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\Comment;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Workflow;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataList;
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

    private static array $allowed_actions = [
        'deleteselected' => '->canEdit',
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

    public function isStream(): bool
    {
        return static::config()->get('display') === 'stream';
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

    private function renderStream(string $message = ''): HTTPResponse
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
        // Order: kind and type, text, date and time, responsible, marks.
        foreach (['TypeID', 'Text', 'DueDate', 'DueTime', 'MemberID', 'Important', 'IsDone'] as $name) {
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
     * Members who may edit in the parent controller: the internal roles of the
     * workflow for case files, otherwise the members with the parent's edit
     * permission.
     *
     * @return array<int, string>
     */
    private function memberOptions(): array
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
