<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\Comment;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;

/**
 * Pages of the work list instead of one list with many views (config pages
 * of FollowUpController): each page has a title, the choice "By member" and
 * columns side by side, e.g. "My to-do list" and "To-do basket". A page is
 * reached at followups/<key>; ?view=<key> links can be sent to a page
 * (config view_pages). Each entry is ticked off, edited, deleted or taken
 * over in place.
 */
trait FollowUpPagesTrait
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function followUpPages(): array
    {
        return (array) static::config()->get('pages');
    }

    public function hasAction($action)
    {
        return isset($this->followUpPages()[(string) $action]) || parent::hasAction($action);
    }

    public function checkAccessAction($action)
    {
        if (isset($this->followUpPages()[(string) $action])) {
            return $this->canView();
        }
        return parent::checkAccessAction($action);
    }

    protected function handleAction($request, $action)
    {
        if (isset($this->followUpPages()[(string) $action])) {
            return $this->showFollowUpPage((string) $action);
        }
        return parent::handleAction($request, $action);
    }

    /**
     * With pages: ?view=<key> of view_pages and the plain list go to a page;
     * other views still show the list.
     */
    protected function redirectToFollowUpPage(HTTPRequest $request): ?HTTPResponse
    {
        $pages = $this->followUpPages();
        if (!$pages || $this->isHtmxRequest()) {
            return null;
        }
        $view = (string) $request->getVar('view');
        $target = $view !== '' ? ((array) static::config()->get('view_pages'))[$view] ?? null : array_key_first($pages);
        return $target !== null && isset($pages[$target]) ? $this->redirect($this->Link($target)) : null;
    }

    /**
     * @return mixed
     */
    protected function showFollowUpPage(string $key)
    {
        $page = $this->followUpPages()[$key];
        $member = $this->chosenMember();
        $columns = ArrayList::create();
        foreach ((array) ($page['columns'] ?? []) as $index => $column) {
            $columns->push($this->followUpColumn($key, (int) $index, (array) $column, $member));
        }
        return $this->customise([
            'Title' => (string) ($page['title'] ?? $key),
            'PageKey' => $key,
            'PageLink' => $this->Link($key),
            'Columns' => $columns,
            'ColumnCount' => $columns->count(),
            'MemberChoice' => $this->hasSelectableColumn($page) ? $this->memberChoice($member) : null,
        ])->renderWith([self::class . '_page', 'Page']);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function hasSelectableColumn(array $page): bool
    {
        foreach ((array) ($page['columns'] ?? []) as $column) {
            if (($column['member'] ?? 'selectable') === 'selectable') {
                return true;
            }
        }
        return false;
    }

    /**
     * "By member" is set to "All" (?member=all): the columns that follow it
     * show the entries of all members.
     */
    protected function allMembersChosen(): bool
    {
        return $this->getRequest()->getVar('member') === 'all';
    }

    /**
     * The member chosen with ?member=<ID> (only one with open entries), else
     * the current member.
     */
    protected function chosenMember(): ?Member
    {
        $me = Security::getCurrentUser();
        $id = (int) $this->getRequest()->getVar('member');
        if ($id && $id !== (int) $me?->ID && isset($this->memberOptions()[$id])) {
            return Member::get()->byID($id);
        }
        return $me;
    }

    /**
     * Members with open entries, and the current member.
     *
     * @return array<int, string>
     */
    protected function memberOptions(): array
    {
        $ids = $this->openComments()->filter('MemberID:GreaterThan', 0)->columnUnique('MemberID');
        $me = Security::getCurrentUser();
        $options = [];
        foreach ($ids ? Member::get()->byIDs($ids) : [] as $member) {
            $options[(int) $member->ID] = (string) $member->getName();
        }
        if ($me) {
            $options[(int) $me->ID] = (string) $me->getName();
        }
        asort($options);
        return $options;
    }

    private function memberChoice(?Member $chosen): ArrayData
    {
        $all = $this->allMembersChosen();
        $options = ArrayList::create([ArrayData::create([
            'ID' => 'all',
            'Name' => _t(FollowUpController::class . '.ALL_MEMBERS', 'All'),
            'Selected' => $all,
        ])]);
        foreach ($this->memberOptions() as $id => $name) {
            $options->push(ArrayData::create([
                'ID' => $id,
                'Name' => $name,
                'Selected' => !$all && $id === (int) $chosen?->ID,
            ]));
        }
        return ArrayData::create(['Options' => $options]);
    }

    /**
     * One column: title (with the chosen member's name), number and entries.
     *
     * @param array<string, mixed> $column
     */
    protected function followUpColumn(string $pageKey, int $index, array $column, ?Member $chosen): ArrayData
    {
        $me = Security::getCurrentUser();
        $mode = (string) ($column['member'] ?? 'selectable');
        $kinds = array_values(array_intersect((array) ($column['kind'] ?? Comment::TASK_KINDS), Comment::OPEN_KINDS));
        $list = $this->openComments()->filter('Kind', $kinds ?: Comment::TASK_KINDS);
        $title = (string) ($column['title'] ?? '');
        switch ($mode) {
            case 'none':
                $list = $list->filter('MemberID', 0);
                break;
            case 'me':
                $list = $list->filter('MemberID', (int) $me?->ID);
                break;
            case 'any':
                break;
            default:
                if ($this->allMembersChosen()) {
                    $all = (string) ($column['title_all'] ?? '');
                    $allLabel = _t(FollowUpController::class . '.ALL_MEMBERS', 'All');
                    $title = $all !== '' ? $all : $title . ' – ' . $allLabel;
                    break;
                }
                $list = $list->filter('MemberID', (int) $chosen?->ID);
                if ($chosen && (int) $chosen->ID !== (int) $me?->ID) {
                    $other = (string) ($column['title_other'] ?? '');
                    $title = $other !== ''
                        ? str_replace('{name}', (string) $chosen->getName(), $other)
                        : $title . ' – ' . $chosen->getName();
                }
        }
        $list = $list->orderBy(
            'CASE WHEN "Flow_Comment"."DueDate" IS NULL THEN 1 ELSE 0 END, "Flow_Comment"."DueDate" ASC,'
            . ' "Flow_Comment"."DueTime" ASC, "Flow_Comment"."Created" ASC, "Flow_Comment"."ID" ASC'
        );
        $limit = (int) static::config()->get('page_column_limit');
        $actions = (array) ($column['actions'] ?? []);
        $member = (string) $this->getRequest()->getVar('member');
        $memberQuery = $member === 'all' ? '?member=all' : ((int) $member ? '?member=' . (int) $member : '');
        $backURL = $this->Link($pageKey) . $memberQuery;
        $entries = ArrayList::create();
        foreach ($list->limit($limit) as $comment) {
            $entries->push($this->followUpEntry($comment, $actions, $backURL));
        }
        return ArrayData::create([
            'Key' => $pageKey . '-' . $index,
            'Title' => $title,
            'Count' => $list->count(),
            'More' => max(0, $list->count() - $limit),
            'Entries' => $entries,
        ]);
    }

    /**
     * @param array<int, string> $actions extra actions of the column ("take")
     */
    protected function followUpEntry(Comment $comment, array $actions, string $backURL): ArrayData
    {
        $canEdit = $comment->canEdit();
        $controller = self::recordControllerFor((string) $comment->RecordClass);
        $editLink = $canEdit && $controller && $controller->hasMethod('comments')
            ? $controller->Link('comments/' . $comment->RecordID . '/edit/' . $comment->ID)
                . '?BackURL=' . rawurlencode($backURL)
            : null;
        return ArrayData::create([
            'Comment' => $comment,
            'CanEdit' => $canEdit,
            'CanDelete' => $comment->canDelete(),
            'EditLink' => $editLink,
            'DoneLink' => $this->Link('done/' . $comment->ID),
            'RemoveLink' => $this->Link('remove/' . $comment->ID),
            'TakeLink' => in_array('take', $actions, true) && $canEdit ? $this->Link('take/' . $comment->ID) : null,
            'Token' => SecurityToken::inst()->getValue(),
        ]);
    }

    /**
     * Ticks an entry off (POST with token); the entry leaves the page.
     */
    public function done(HTTPRequest $request): HTTPResponse
    {
        return $this->changeEntry($request, 'canEdit', function (Comment $comment) {
            $comment->IsDone = true;
            $comment->write();
        });
    }

    /**
     * The current member takes an entry over (e.g. from the to-do basket);
     * the page is reloaded so the entry shows in the member's column.
     */
    public function take(HTTPRequest $request): HTTPResponse
    {
        $response = $this->changeEntry($request, 'canEdit', function (Comment $comment) {
            $comment->MemberID = (int) Security::getCurrentUser()?->ID;
            $comment->write();
        });
        return $response->getStatusCode() === 200 ? $response->addHeader('HX-Refresh', 'true') : $response;
    }

    /**
     * Deletes an entry (POST with token).
     */
    public function remove(HTTPRequest $request): HTTPResponse
    {
        return $this->changeEntry($request, 'canDelete', fn (Comment $comment) => $comment->delete());
    }

    private function changeEntry(HTTPRequest $request, string $permission, callable $change): HTTPResponse
    {
        if (!$request->isPOST() || !SecurityToken::inst()->checkRequest($request)) {
            return $this->httpError(400);
        }
        $comment = $this->openComments()->byID((int) $request->param('ID'));
        if (!$comment instanceof Comment || !$comment->$permission()) {
            return $this->httpError(404);
        }
        $change($comment);
        if ($this->isHtmxRequest()) {
            return HTTPResponse::create('', 200);
        }
        $back = (string) $request->postVar('BackURL');
        return $this->redirect($back !== '' && Director::is_site_url($back) ? $back : $this->Link());
    }
}
