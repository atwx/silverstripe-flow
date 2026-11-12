<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\FollowUp;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Table\BulkAction;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\ListView;
use SilverStripe\Core\ClassInfo;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Security;

/**
 * Open follow-ups of all case files the member may see ("Wiedervorlagen"):
 * views "Mine, due" and "Mine", link to the case file, bulk action "Done".
 * Projects route it, e.g. 'followups//$Action/$ID/$OtherID'.
 */
class FollowUpController extends FrontdeskController
{
    private static string $managed_model = FollowUp::class;

    private static string $url_segment = 'followups';

    private static string $title = 'Wiedervorlagen';

    /**
     * Follow-ups are added in the case file.
     */
    private static bool $allow_add = false;

    /**
     * Case controllers by case class (the first subclass found for a class).
     *
     * @var array<string, FlowCaseController>|null
     */
    protected static ?array $caseControllerCache = null;

    /**
     * Open follow-ups of case files whose list the member may view.
     *
     * @return DataList<FollowUp>
     */
    protected function getBaseList(): DataList
    {
        $classes = [];
        foreach (self::caseControllers() as $class => $controller) {
            if ($controller->canView()) {
                $classes[] = $class;
            }
        }
        // PHPStan reports static<T> from DataList::filter() as incompatible with DataList<T>.
        // @phpstan-ignore return.type
        return FollowUp::get()->filter(['IsDone' => false, 'CaseClass' => $classes ?: ['-']]);
    }

    /**
     * @return array<string, FlowCaseController>
     */
    protected static function caseControllers(): array
    {
        if (self::$caseControllerCache === null) {
            self::$caseControllerCache = [];
            foreach (ClassInfo::subclassesFor(FlowCaseController::class, false) as $class) {
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
     * The controller of case files of a class (for links), if there is one.
     */
    public static function caseControllerFor(string $class): ?FlowCaseController
    {
        return self::caseControllers()[$class] ?? null;
    }

    protected function defineColumns(): ColumnCollection
    {
        $labels = FollowUp::singleton()->fieldLabels();
        return ColumnCollection::create()
            ->make('DueDate', $labels['DueDate'])->format(FollowUpColumns::dueFormatter())->type('html')->end()
            ->make('CaseTitle', _t(self::class . '.CASE', 'Case file'))
                ->format(fn ($value, DataObject $record) => $this->caseLink($record))
                ->type('html')
                ->end()
            ->make('Text', $labels['Text'])->end()
            ->make('Member.Name', $labels['Member.Name'])->end();
    }

    private function caseLink(DataObject $followUp): string
    {
        /** @var FollowUp $followUp */
        $case = $followUp->Case();
        if (!$case->exists()) {
            return '';
        }
        $title = htmlspecialchars((string) $case->getTitle());
        $controller = self::caseControllerFor($case::class);
        if (!$controller) {
            return $title;
        }
        $href = htmlspecialchars($controller->Link('view/' . $case->ID));
        return '<a class="link" href="' . $href . '">' . $title . '</a>';
    }

    protected function defineRowActions(DataObject $record): array
    {
        return [];
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
            ListView::create('mine', _t(self::class . '.VIEW_MINE', 'Mine'))
                ->query(fn (DataList $list) => $list->filter('MemberID', $me)),
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
                foreach ($records as $followUp) {
                    if ($followUp->canEdit()) {
                        $followUp->IsDone = true;
                        $followUp->write();
                        $count++;
                    }
                }
                return _t(self::class . '.DONE_COUNT', '{count} follow-ups done.', ['count' => $count]);
            }),
        ];
    }
}
