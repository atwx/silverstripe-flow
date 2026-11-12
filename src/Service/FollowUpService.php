<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Model\Comment;
use Atwx\Flow\Model\FollowUpRule;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Applies the follow-up rules when the status of a case file changes: closes
 * the open follow-ups of rules of the old status (close on leave) and creates
 * those of the new status (comments of kind follow-up).
 */
class FollowUpService
{
    use Injectable;

    public function onStatusChange(DataObject $case, int $fromID, int $toID, ?Member $by): void
    {
        if ($fromID) {
            $leaving = FollowUpRule::get()->filter(['StatusID' => $fromID, 'CloseOnLeave' => true])->column('ID');
            if ($leaving) {
                $open = Comment::get()->filter([
                    'RecordClass' => $case->ClassName,
                    'RecordID' => $case->ID,
                    'RuleID' => $leaving,
                    'IsDone' => false,
                ]);
                foreach ($open as $followUp) {
                    $followUp->IsDone = true;
                    $followUp->write();
                }
            }
        }
        foreach (FollowUpRule::get()->filter('StatusID', $toID) as $rule) {
            $responsibility = $rule->Responsibility();
            $member = $responsibility->exists()
                ? AssignmentService::singleton()->assignee($case, $responsibility)
                : null;
            Comment::create([
                'Kind' => Comment::FOLLOW_UP,
                'RecordClass' => $case->ClassName,
                'RecordID' => $case->ID,
                'RuleID' => $rule->ID,
                'Text' => $rule->Title,
                'DueDate' => date('Y-m-d', (int) strtotime('+' . max(0, (int) $rule->Days) . ' days')),
                'MemberID' => (int) $member?->ID,
                'CreatedByID' => (int) $by?->ID,
            ])->write();
        }
    }
}
