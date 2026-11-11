<?php

namespace Atwx\Flow\Actor;

use Atwx\Flow\Model\Workflow;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Someone acting on a case file: a logged-in member or, later, the holder of an
 * edit link. Rights are always derived from the roles an actor has for a case.
 */
interface FlowActor
{
    /**
     * Codes of the roles this actor has in the given workflow for the given case.
     *
     * @return array<int, string>
     */
    public function flowRoleCodes(Workflow $workflow, DataObject $case): array;

    /**
     * The member behind this actor, if any (recorded in the history).
     */
    public function flowMember(): ?Member;
}
