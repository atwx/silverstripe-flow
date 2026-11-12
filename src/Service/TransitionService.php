<?php

namespace Atwx\Flow\Service;

use Atwx\Flow\Actor\AccessLinkActor;
use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Transition;
use Atwx\Flow\Model\Workflow;
use LogicException;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * The only place where the status of a case file changes.
 *
 * A transition is allowed if the workflow defines it from the current status to
 * the target status and the actor has one of its roles. Status, history entry
 * and hooks run in one database transaction.
 *
 * Hooks (on the case file, i.e. the domain class and its extensions, and on this
 * service for cross-cutting listeners):
 *
 * - onBeforeTransition(Transition $transition, FlowActor $actor, string $comment)
 *   Inside the transaction, before the status is written. Throw a
 *   TransitionException to refuse the change.
 * - onAfterTransition(StatusChange $change, FlowActor $actor)
 *   After the transaction is committed, e.g. to send notifications.
 *
 * The service hooks receive the case file as first argument.
 */
class TransitionService
{
    use Injectable;
    use Extensible;

    /**
     * Transitions the actor may perform from the current status of the case.
     *
     * @return ArrayList<Transition>
     */
    public function availableTransitions(DataObject $case, FlowActor $actor): ArrayList
    {
        $result = ArrayList::create();
        $workflow = $this->workflowOf($case);
        $statusID = (int) $case->getField('FlowStatusID');
        if (!$workflow || !$statusID) {
            return $result;
        }
        $roles = $actor->flowRoleCodes($workflow, $case);
        $transitions = Transition::get()->filter(['WorkflowID' => $workflow->ID, 'FromID' => $statusID]);
        foreach ($transitions as $transition) {
            if ($transition->allowsAnyRole($roles)) {
                $result->push($transition);
            }
        }
        return $result;
    }

    /**
     * Change the status of the case.
     *
     * @param Status|string $target Target status or its code
     * @throws TransitionException if the change is not allowed
     */
    public function transition(
        DataObject $case,
        Status|string $target,
        FlowActor $actor,
        string $comment = ''
    ): StatusChange {
        $workflow = $this->workflowOf($case);
        if (!$workflow) {
            throw new TransitionException(_t(self::class . '.NO_WORKFLOW', 'There is no workflow for this record.'));
        }
        if (!$case->isInDB()) {
            throw new TransitionException(_t(self::class . '.NOT_SAVED', 'Please save the record first.'));
        }

        $to = $target instanceof Status
            ? $target
            : Status::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $target])->first();
        if (!$to || (int) $to->WorkflowID !== (int) $workflow->ID) {
            throw new TransitionException(_t(self::class . '.UNKNOWN_STATUS', 'Unknown status.'));
        }

        $fromID = (int) $case->getField('FlowStatusID');
        $transition = Transition::get()
            ->filter(['WorkflowID' => $workflow->ID, 'FromID' => $fromID, 'ToID' => $to->ID])
            ->first();
        if (!$transition) {
            throw new TransitionException(_t(
                self::class . '.NOT_DEFINED',
                'The status cannot be changed to "{status}" from here.',
                ['status' => $to->Title]
            ));
        }
        if (!$transition->allowsAnyRole($actor->flowRoleCodes($workflow, $case))) {
            throw new TransitionException(_t(
                self::class . '.NOT_ALLOWED',
                'You may not change the status to "{status}".',
                ['status' => $to->Title]
            ));
        }

        $missing = ChecklistService::singleton()->missingBefore($case, $to);
        if ($missing) {
            throw new TransitionException(_t(
                self::class . '.CHECKLIST_MISSING',
                'Before "{status}" the checklist needs: {items}.',
                ['status' => $to->Title, 'items' => implode(', ', $missing)]
            ));
        }

        $change = null;
        DB::get_conn()->withTransaction(function () use ($case, $transition, $actor, $comment, $fromID, &$change) {
            // Refuse if someone else changed the status in the meantime.
            $stored = DataObject::get_by_id($case->ClassName, $case->ID, false);
            if (!$stored || (int) $stored->getField('FlowStatusID') !== $fromID) {
                throw new TransitionException(_t(
                    self::class . '.STALE',
                    'The status has been changed in the meantime. Please reload the page.'
                ));
            }

            $case->invokeWithExtensions('onBeforeTransition', $transition, $actor, $comment);
            $this->extend('onBeforeTransition', $case, $transition, $actor, $comment);

            CaseFileExtension::writeStatus($case, (int) $transition->ToID);
            $change = $this->record($case, $fromID, (int) $transition->ToID, $actor, $comment);
        });

        if (!$change instanceof StatusChange) {
            throw new LogicException('The status change was not recorded.');
        }
        // Hooks take their arguments by reference; keep the result in a separate variable.
        $result = $change;
        $case->invokeWithExtensions('onAfterTransition', $change, $actor);
        $this->extend('onAfterTransition', $case, $change, $actor);

        return $result;
    }

    /**
     * Write a history entry.
     */
    public function record(
        DataObject $case,
        int $fromID,
        int $toID,
        ?FlowActor $actor,
        string $comment = ''
    ): StatusChange {
        $change = StatusChange::create([
            'FromID' => $fromID,
            'ToID' => $toID,
            'MemberID' => (int) $actor?->flowMember()?->ID,
            'AccessLinkID' => $actor instanceof AccessLinkActor ? $actor->getLink()->ID : 0,
            'Comment' => $comment,
        ]);
        $change->setField('CaseID', $case->ID);
        $change->setField('CaseClass', $case->ClassName);
        $change->write();
        FollowUpService::singleton()->onStatusChange($case, $fromID, $toID, $actor?->flowMember());
        return $change;
    }

    public function workflowOf(DataObject $case): ?Workflow
    {
        return $case->hasExtension(CaseFileExtension::class)
            ? Workflow::forClass($case->ClassName)
            : null;
    }
}
