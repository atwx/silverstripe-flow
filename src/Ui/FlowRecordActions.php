<?php

namespace Atwx\Flow\Ui;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Service\TransitionService;
use Atwx\SilverstripeFrontdeskKit\Table\RecordAction;
use SilverStripe\ORM\DataObject;

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
     */
    public static function statusAction(DataObject $record, FlowActor $actor): ?RecordAction
    {
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
        return RecordAction::create('flowstatus', _t(self::class . '.CHANGE_STATUS', 'Change status'))
            ->options($options)
            ->withComment(_t(self::class . '.COMMENT', 'Comment (optional)'))
            ->submitLabel(_t(self::class . '.CHANGE_STATUS', 'Change status'))
            ->handle(function (DataObject $record, ?string $option, string $comment) use ($actor) {
                $change = TransitionService::singleton()->transition($record, (string) $option, $actor, $comment);
                $to = $change->To();
                return _t(
                    self::class . '.STATUS_CHANGED',
                    'The status is now "{status}".',
                    ['status' => $to->exists() ? $to->Title : '']
                );
            });
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
