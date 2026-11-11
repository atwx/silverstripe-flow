<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Model\Assignment;
use Atwx\Flow\Model\AssignmentChange;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Assigns members to the responsibilities of a case file and records every
 * change for the timeline. Only candidates of a responsibility (members of
 * its role) can be assigned.
 */
class AssignmentService
{
    use Injectable;

    /**
     * Responsibilities of the case file's workflow, in order.
     *
     * @return array<int, Responsibility>
     */
    public function responsibilitiesFor(string $class): array
    {
        $workflow = Workflow::forClass($class);
        return $workflow ? Responsibility::get()->filter('WorkflowID', $workflow->ID)->toArray() : [];
    }

    public function assignee(DataObject $case, Responsibility $responsibility): ?Member
    {
        $assignment = $this->assignment($case, $responsibility);
        $member = $assignment?->Member();
        return $member && $member->exists() ? $member : null;
    }

    /**
     * Assign a member (null: nobody). Returns whether something changed.
     *
     * @throws TransitionException when the member is not a candidate
     */
    public function assign(DataObject $case, Responsibility $responsibility, ?Member $member, ?Member $by): bool
    {
        if (!$case->isInDB()) {
            throw new TransitionException('The case file must be saved first.');
        }
        $workflow = Workflow::forClass($case->ClassName);
        if (!$workflow || (int) $responsibility->WorkflowID !== (int) $workflow->ID) {
            throw new TransitionException('The responsibility does not belong to this case file.');
        }
        if ($member && !array_key_exists((int) $member->ID, $responsibility->candidateOptions())) {
            throw new TransitionException(_t(
                self::class . '.NOT_CANDIDATE',
                '{name} cannot be assigned for "{responsibility}".',
                ['name' => $member->getName(), 'responsibility' => $responsibility->Title]
            ));
        }
        $assignment = $this->assignment($case, $responsibility);
        $fromID = (int) $assignment?->MemberID;
        $toID = (int) $member?->ID;
        if ($fromID === $toID) {
            return false;
        }
        if ($toID === 0) {
            $assignment?->delete();
        } else {
            $assignment ??= Assignment::create([
                'CaseClass' => $case->ClassName,
                'CaseID' => $case->ID,
                'ResponsibilityID' => $responsibility->ID,
            ]);
            $assignment->MemberID = $toID;
            $assignment->write();
        }
        AssignmentChange::create([
            'CaseClass' => $case->ClassName,
            'CaseID' => $case->ID,
            'ResponsibilityID' => $responsibility->ID,
            'FromID' => $fromID,
            'ToID' => $toID,
            'MemberID' => (int) $by?->ID,
        ])->write();
        return true;
    }

    private function assignment(DataObject $case, Responsibility $responsibility): ?Assignment
    {
        return Assignment::get()->filter([
            'CaseClass' => $case->ClassName,
            'CaseID' => $case->ID,
            'ResponsibilityID' => $responsibility->ID,
        ])->first();
    }
}
