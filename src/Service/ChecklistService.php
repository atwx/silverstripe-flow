<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Model\ChecklistEntry;
use Atwx\Flow\Model\ChecklistItem;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;

/**
 * The checklist of a case file: one entry per item of its workflow (created
 * when missing) and the check before a status that requires items.
 */
class ChecklistService
{
    use Injectable;

    /**
     * Make sure there is an entry for every item of the workflow.
     */
    public function ensureEntries(DataObject $case): void
    {
        $workflow = Workflow::forClass($case::class);
        if (!$workflow || !$case->isInDB()) {
            return;
        }
        $existing = ChecklistEntry::get()
            ->filter(['CaseClass' => $case->ClassName, 'CaseID' => $case->ID])
            ->column('ItemID');
        foreach (ChecklistItem::get()->filter('WorkflowID', $workflow->ID)->exclude('ID', $existing ?: [0]) as $item) {
            ChecklistEntry::create([
                'CaseClass' => $case->ClassName,
                'CaseID' => $case->ID,
                'ItemID' => $item->ID,
            ])->write();
        }
    }

    /**
     * Titles of the items required before the status that are not complete yet.
     *
     * @return array<int, string>
     */
    public function missingBefore(DataObject $case, Status $status): array
    {
        $items = ChecklistItem::get()->filter('RequiredBeforeID', $status->ID);
        if (!$items->exists()) {
            return [];
        }
        $complete = ChecklistEntry::get()->filter([
            'CaseClass' => $case->ClassName,
            'CaseID' => $case->ID,
            'State' => ChecklistEntry::COMPLETE,
        ])->column('ItemID');
        $missing = [];
        foreach ($items as $item) {
            if (!in_array($item->ID, $complete)) {
                $missing[] = (string) $item->Title;
            }
        }
        return $missing;
    }
}
