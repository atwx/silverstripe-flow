<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\Comment;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use Atwx\SilverstripeFrontdeskKit\Filter\FilterCollection;
use Atwx\SilverstripeFrontdeskKit\Filter\SelectFilter;
use Atwx\SilverstripeFrontdeskKit\Table\BulkAction;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\ListView;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Open follow-ups and to-dos of all records the member may see: views "Mine,
 * due", "My follow-ups", "My to-dos", "To-do basket" (to-dos without anyone
 * responsible) and "Overdue", filters by kind and member, link to the record,
 * bulk actions "Done" and "Take over". Projects route it, e.g.
 * 'followups//$Action/$ID/$OtherID'.
 */
class FollowUpController extends FrontdeskController
{
    private static string $managed_model = Comment::class;

    private static string $url_segment = 'followups';

    private static string $title = 'Wiedervorlagen';

    /**
     * Entries are added in the record.
     */
    private static bool $allow_add = false;

    /**
     * Record controllers by managed class (the first one found for a class).
     *
     * @var array<string, FrontdeskController>|null
     */
    protected static ?array $caseControllerCache = null;

    /**
     * Days ahead that countAppointments() counts (0: all upcoming ones).
     */
    private static int $badge_appointment_days = 7;

    /**
     * Badge of the navigation: the current member's open follow-ups and to-dos
     * due today or earlier (the view "Mine, due").
     *
     *   badge: 'Atwx\Flow\Controller\FollowUpController::countDue'
     */
    public static function countDue(): int
    {
        $me = (int) Security::getCurrentUser()?->ID;
        if (!$me) {
            return 0;
        }
        return self::singleton()->getBaseList()
            ->filter(['MemberID' => $me, 'DueDate:LessThanOrEqual' => date('Y-m-d')])
            ->count();
    }

    /**
     * Badge: the current member's open to-dos (any date).
     */
    public static function countTodos(): int
    {
        $me = (int) Security::getCurrentUser()?->ID;
        if (!$me) {
            return 0;
        }
        return self::singleton()->getBaseList()->filter(['MemberID' => $me, 'Kind' => Comment::TODO])->count();
    }

    /**
     * Badge: the current member's appointments from today on, within
     * badge_appointment_days.
     */
    public static function countAppointments(): int
    {
        $me = (int) Security::getCurrentUser()?->ID;
        if (!$me) {
            return 0;
        }
        $list = self::singleton()->openComments()->filter([
            'Kind' => Comment::APPOINTMENT,
            'MemberID' => $me,
            'DueDate:GreaterThanOrEqual' => date('Y-m-d'),
        ]);
        $days = (int) static::config()->get('badge_appointment_days');
        if ($days > 0) {
            $list = $list->filter('DueDate:LessThanOrEqual', date('Y-m-d', (int) strtotime('+' . $days . ' days')));
        }
        return $list->count();
    }

    /**
     * 'table' or 'stream' (entries as in the comment stream, each with a link
     * to its record).
     */
    private static string $display = 'table';

    /**
     * Width of the stream on large screens (Tailwind classes), e.g.
     * 'lg:w-1/2' as the old work list; '' for the full width.
     */
    private static string $stream_width = 'lg:w-1/2';

    public function isStream(): bool
    {
        return static::config()->get('display') === 'stream';
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method
    public function StreamWidthClass(): string
    {
        return (string) static::config()->get('stream_width');
    }

    /**
     * @return mixed
     */
    public function index(HTTPRequest $request)
    {
        if (!$this->isStream()) {
            return parent::index($request);
        }
        if ($this->isHtmxRequest()) {
            return $this->renderPartial('Atwx\\Flow\\Includes\\FollowUpStream', ['Items' => $this->getItems()]);
        }
        return $this->renderWith([self::class . '_stream', 'Page']);
    }

    /**
     * Open follow-ups and to-dos of records whose list the member may view.
     *
     * @return DataList<Comment>
     */
    protected function getBaseList(): DataList
    {
        // The view "appointments" lists appointments, all others follow-ups and to-dos
        $kinds = $this->getRequest()->getVar('view') === 'appointments' ? [Comment::APPOINTMENT] : Comment::TASK_KINDS;
        return $this->openComments()
            ->filter('Kind', $kinds)
            ->sort(['DueDate' => 'ASC', 'DueTime' => 'ASC', 'Created' => 'ASC', 'ID' => 'ASC']);
    }

    /**
     * Comments not done, of any kind, of records whose list the member may view.
     *
     * @return DataList<Comment>
     */
    protected function openComments(): DataList
    {
        $classes = [];
        foreach (self::recordControllers() as $class => $controller) {
            if ($controller->canView()) {
                $classes[] = $class;
            }
        }
        // @phpstan-ignore return.type
        return Comment::get()->filter(['IsDone' => false, 'RecordClass' => $classes ?: ['-']]);
    }

    /**
     * Frontdesk controllers of records (not sub-lists, not this list), by the
     * class they manage. Comments and follow-ups link to them.
     *
     * @return array<string, FrontdeskController>
     */
    protected static function recordControllers(): array
    {
        if (self::$caseControllerCache === null) {
            self::$caseControllerCache = [];
            // Case controllers first: a case class is linked to its case file.
            $classes = array_merge(
                ClassInfo::subclassesFor(FlowCaseController::class, false),
                ClassInfo::subclassesFor(FrontdeskController::class, false)
            );
            foreach ($classes as $class) {
                $skip = is_a($class, FrontdeskSubController::class, true) || is_a($class, self::class, true)
                    || (new \ReflectionClass($class))->isAbstract();
                if ($skip) {
                    continue;
                }
                $controller = singleton($class);
                $model = (string) $controller->config()->get('managed_model');
                if ($model !== '' && !isset(self::$caseControllerCache[$model])) {
                    self::$caseControllerCache[$model] = $controller;
                }
            }
        }
        return self::$caseControllerCache;
    }

    /**
     * The controller of records of a class (for links and permissions), if there is one.
     */
    public static function recordControllerFor(string $class): ?FrontdeskController
    {
        return self::recordControllers()[$class] ?? null;
    }

    /**
     * The case controller of case files of a class, if there is one.
     */
    public static function caseControllerFor(string $class): ?FlowCaseController
    {
        $controller = self::recordControllerFor($class);
        return $controller instanceof FlowCaseController ? $controller : null;
    }

    /**
     * Link to the view of a record (null without a controller).
     */
    public static function recordLink(DataObject $record): ?string
    {
        $controller = self::recordControllerFor($record::class);
        return $controller && $record->isInDB() ? $controller->Link('view/' . $record->ID) : null;
    }

    protected function defineColumns(): ColumnCollection
    {
        $labels = Comment::singleton()->fieldLabels();
        return ColumnCollection::create()
            ->make('DueDate', $labels['DueDate'])->format(FollowUpColumns::dueFormatter())->type('html')->end()
            ->make('RecordTitle', $labels['RecordTitle'])
                ->format(fn ($value, DataObject $record) => $this->recordAnchor($record))
                ->type('html')
                ->end()
            ->make('Summary', $labels['Text'])->format(FollowUpColumns::summaryFormatter())->type('html')->end()
            ->make('CreatedBy.Name', $labels['CreatedBy'])
                ->format(fn ($value, DataObject $record) => $value
                    ? $value . ', ' . date('d.m.Y', (int) strtotime((string) $record->Created))
                    : '')
                ->end();
    }

    private function recordAnchor(DataObject $comment): string
    {
        if (!$comment instanceof Comment) {
            return '';
        }
        $record = $comment->Record();
        if (!$record->exists()) {
            return '';
        }
        $title = htmlspecialchars((string) $record->getTitle());
        $href = self::recordLink($record);
        return $href ? '<a class="link" href="' . htmlspecialchars($href) . '">' . $title . '</a>' : $title;
    }

    protected function defineRowActions(DataObject $record): array
    {
        return [];
    }

    protected function defineFilters(): FilterCollection
    {
        $labels = Comment::singleton()->fieldLabels();
        $kinds = array_intersect_key(Comment::kindOptions(), array_flip(Comment::TASK_KINDS));
        return FilterCollection::create()
            ->add(
                SelectFilter::create('Kind', $labels['Kind'])
                    ->options($kinds)
                    ->apply(fn (DataList $list, $value) => $list->filter('Kind', $value))
            )
            ->add(
                SelectFilter::create('Member', $labels['MemberID'])
                    ->options(fn () => $this->responsibleOptions())
                    ->apply(fn (DataList $list, $value) => $list->filter('MemberID', (int) $value))
            );
    }

    /**
     * Members with open entries.
     *
     * @return array<int, string>
     */
    private function responsibleOptions(): array
    {
        $ids = $this->getBaseList()->filter('MemberID:GreaterThan', 0)->columnUnique('MemberID');
        $options = [];
        foreach ($ids ? Member::get()->byIDs($ids) : [] as $member) {
            $options[(int) $member->ID] = (string) $member->getName();
        }
        asort($options);
        return $options;
    }

    /**
     * @return array<int, ListView>
     */
    protected function defineListViews(): array
    {
        $me = (int) Security::getCurrentUser()?->ID;
        return [
            ListView::create('mine-due', _t(self::class . '.VIEW_MINE_DUE', 'Mine, due'))
                ->query(fn (DataList $list) => $list->filter([
                    'MemberID' => $me,
                    'DueDate:LessThanOrEqual' => date('Y-m-d'),
                ])),
            ListView::create('mine', _t(self::class . '.VIEW_MINE', 'My follow-ups'))
                ->query(fn (DataList $list) => $list->filter(['MemberID' => $me, 'Kind' => Comment::FOLLOW_UP])),
            ListView::create('todos', _t(self::class . '.VIEW_TODOS', 'My to-dos'))
                ->query(fn (DataList $list) => $list->filter(['MemberID' => $me, 'Kind' => Comment::TODO])),
            ListView::create('basket', _t(self::class . '.VIEW_BASKET', 'To-do basket'))
                ->query(fn (DataList $list) => $list->filter(['MemberID' => 0, 'Kind' => Comment::TODO])),
            ListView::create('appointments', _t(self::class . '.VIEW_APPOINTMENTS', 'My appointments'))
                ->query(fn (DataList $list) => $list->filter([
                    'MemberID' => $me,
                    'DueDate:GreaterThanOrEqual' => date('Y-m-d'),
                ])),
            ListView::create('overdue', _t(self::class . '.VIEW_OVERDUE', 'Overdue'))
                ->query(fn (DataList $list) => $list->filter('DueDate:LessThan', date('Y-m-d'))),
        ];
    }

    /**
     * @return array<int, BulkAction>
     */
    protected function defineBulkActions(): array
    {
        return [
            BulkAction::create('done', _t(self::class . '.DONE', 'Mark as done'), function (DataList $records) {
                $count = 0;
                foreach ($records as $comment) {
                    if ($comment->canEdit()) {
                        $comment->IsDone = true;
                        $comment->write();
                        $count++;
                    }
                }
                return _t(self::class . '.DONE_COUNT', '{count} entries done.', ['count' => $count]);
            }),
            BulkAction::create('take', _t(self::class . '.TAKE', 'Take over'), function (DataList $records) {
                $me = (int) Security::getCurrentUser()?->ID;
                $count = 0;
                foreach ($records as $comment) {
                    if ($me && $comment->canEdit()) {
                        $comment->MemberID = $me;
                        $comment->write();
                        $count++;
                    }
                }
                return _t(self::class . '.TAKE_COUNT', '{count} entries taken over.', ['count' => $count]);
            }),
            BulkAction::create('delete', _t(self::class . '.DELETE', 'Delete'), function (DataList $records) {
                $count = 0;
                foreach ($records as $comment) {
                    if ($comment->canDelete()) {
                        $comment->delete();
                        $count++;
                    }
                }
                return _t(self::class . '.DELETE_COUNT', '{count} entries deleted.', ['count' => $count]);
            })->confirm(_t(self::class . '.DELETE_CONFIRM', 'Delete the selected entries?')),
        ];
    }
}
