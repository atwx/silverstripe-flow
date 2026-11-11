<?php

namespace Atwx\Flow\Actor;

use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * A logged-in member. The member has every internal role of a workflow whose
 * group they belong to (directly or through a subgroup).
 */
class MemberActor implements FlowActor
{
    use Injectable;

    public function __construct(private readonly Member $member)
    {
    }

    /**
     * The currently logged-in member, or null if nobody is logged in.
     */
    public static function current(): ?MemberActor
    {
        $member = Security::getCurrentUser();
        return $member ? static::create($member) : null;
    }

    public function flowRoleCodes(Workflow $workflow, DataObject $case): array
    {
        $codes = [];
        $roles = FlowRole::get()->filter(['WorkflowID' => $workflow->ID, 'IsExternal' => false]);
        foreach ($roles as $role) {
            if ($role->GroupID && $this->member->inGroup($role->GroupID)) {
                $codes[] = $role->Code;
            }
        }
        return $codes;
    }

    public function flowMember(): ?Member
    {
        return $this->member;
    }
}
