<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Service\TransitionService;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Cross-cutting listener on the transition service.
 *
 * @extends Extension<TransitionService>
 */
class TestServiceHooks extends Extension implements TestOnly
{
    /**
     * @var array<int, string>
     */
    public static array $log = [];

    protected function onAfterTransition(DataObject $case, StatusChange $change, FlowActor $actor): void
    {
        self::$log[] = $case->ClassName . '#' . $case->ID . ':' . TestCaseHooks::code($change->To());
    }
}
