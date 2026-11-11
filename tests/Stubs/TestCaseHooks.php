<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Transition;
use Atwx\Flow\Service\TransitionException;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Records the transition hooks of a case file, and can veto transitions.
 *
 * @extends Extension<DataObject>
 */
class TestCaseHooks extends Extension implements TestOnly
{
    /**
     * @var array<int, string>
     */
    public static array $log = [];

    public static ?string $vetoTo = null;

    protected function onBeforeTransition(Transition $transition, FlowActor $actor, string $comment): void
    {
        $to = self::code($transition->To());
        self::$log[] = 'before:' . self::code($transition->From()) . ">{$to}:{$comment}";
        if (self::$vetoTo === $to) {
            throw new TransitionException('vetoed');
        }
    }

    protected function onAfterTransition(StatusChange $change, FlowActor $actor): void
    {
        self::$log[] = 'after:' . self::code($change->To()) . ':' . $this->getOwner()->getField('FlowStatusID');
    }

    public static function code(Status $status): string
    {
        return $status->exists() ? $status->Code : '';
    }
}
