<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Actor with fixed roles and no member, standing in for an edit link.
 */
class TestActor implements FlowActor, TestOnly
{
    /**
     * @param array<int, string> $roles
     */
    public function __construct(private readonly array $roles)
    {
    }

    public function flowRoleCodes(Workflow $workflow, DataObject $case): array
    {
        return $this->roles;
    }

    public function flowMember(): ?Member
    {
        return null;
    }
}
