<?php

namespace Atwx\Flow\Ui;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Service\TransitionException;
use Atwx\Flow\Service\TransitionService;
use Atwx\SilverstripeFrontdeskKit\Table\RecordAction;
use Closure;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;

/**
 * Record actions of a case file for an actor ("Change status", "Add note"),
 * shared by the internal and the external (link) controller.
 */
class FlowRecordActions
{
    /**
     * @return RecordAction[]
     */
    public static function forActor(DataObject $record, FlowActor $actor): array
    {
        $actions = [];
        $status = self::statusAction($record, $actor);
        if ($status) {
            $actions[] = $status;
        }
        $note = self::noteAction($record, $actor);
        if ($note) {
            $actions[] = $note;
        }
        return $actions;
    }

    /**
     * "Change status" with the transitions the actor may perform, or null if none.
     * $configure adds fields to the dialog; $apply saves their values before
     * the status changes (in the same transaction).
     *
     * @param (Closure(RecordAction): void)|null $configure
     * @param (Closure(DataObject, array<string, mixed>): void)|null $apply
     */
    public static function statusAction(
        DataObject $record,
        FlowActor $actor,
        ?Closure $configure = null,
        ?Closure $apply = null
    ): ?RecordAction {
        $options = [];
        foreach (TransitionService::singleton()->availableTransitions($record, $actor) as $transition) {
            $to = $transition->To();
            if ($to->exists()) {
                $options[$to->Code] = $transition->getLabel();
            }
        }
        if ($options === []) {
            return null;
        }
        $action = RecordAction::create('flowstatus', _t(self::class . '.CHANGE_STATUS', 'Change status'))
            ->options($options);
        if ($configure) {
            $configure($action);
        }
        return $action
            ->withComment(_t(self::class . '.COMMENT', 'Comment (optional)'))
            ->submitLabel(_t(self::class . '.CHANGE_STATUS', 'Change status'))
            ->handle(function (
                DataObject $record,
                ?string $option,
                string $comment,
                array $values = []
            ) use (
                $actor,
                $apply
            ) {
                $change = self::changeStatus($record, (string) $option, $actor, $comment, $values, $apply);
                $to = $change->To();
                return _t(
                    self::class . '.STATUS_CHANGED',
                    'The status is now "{status}".',
                    ['status' => $to->exists() ? $to->Title : '']
                );
            });
    }

    /**
     * Saves the dialog's field values (if any) and changes the status; with
     * fields both happen in one transaction, so a refused change keeps nothing.
     *
     * @param array<string, mixed> $values
     * @param (Closure(DataObject, array<string, mixed>): void)|null $apply
     */
    private static function changeStatus(
        DataObject $record,
        string $target,
        FlowActor $actor,
        string $comment,
        array $values,
        ?Closure $apply
    ): StatusChange {
        $service = TransitionService::singleton();
        if (!$apply) {
            return $service->transition($record, $target, $actor, $comment);
        }
        $change = null;
        $run = function () use ($record, $target, $actor, $comment, $values, $apply, $service, &$change) {
            $apply($record, $values);
            $change = $service->transition($record, $target, $actor, $comment);
        };
        DB::get_conn()->withTransaction($run);
        if (!$change instanceof StatusChange) {
            throw new TransitionException(_t(self::class . '.FAILED', 'The status could not be changed.'));
        }
        return $change;
    }

    /**
     * "Add note" if the actor may add notes, or null.
     */
    public static function noteAction(DataObject $record, FlowActor $actor): ?RecordAction
    {
        /** @var DataObject&CaseFileExtension $record */
        if (!$record->hasMethod('canAddFlowNote') || !$record->canAddFlowNote($actor)) {
            return null;
        }
        return RecordAction::create('flownote', _t(self::class . '.ADD_NOTE', 'Add note'))
            ->withComment(_t(self::class . '.NOTE', 'Note'), true)
            ->submitLabel(_t(self::class . '.SAVE_NOTE', 'Save note'))
            ->handle(function (DataObject $record, ?string $option, string $text) use ($actor) {
                /** @var DataObject&CaseFileExtension $record */
                $record->addFlowNote($text, $actor);
                return _t(self::class . '.NOTE_ADDED', 'The note has been added.');
            });
    }
}
