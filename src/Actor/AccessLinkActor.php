<?php

namespace Atwx\Flow\Actor;

use Atwx\Flow\Model\AccessLink;
use Atwx\Flow\Model\Workflow;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * The holder of an edit link. Has exactly the link's external role, and only
 * for the linked case file while the link is usable.
 */
class AccessLinkActor implements FlowActor
{
    public function __construct(private readonly AccessLink $link)
    {
    }

    public function getLink(): AccessLink
    {
        return $this->link;
    }

    public function flowRoleCodes(Workflow $workflow, DataObject $case): array
    {
        $role = $this->link->Role();
        $isLinkedCase = (int) $this->link->CaseID === (int) $case->ID
            && $this->link->CaseClass === $case->ClassName;
        if (!$isLinkedCase || !$this->link->isUsable() || (int) $role->WorkflowID !== (int) $workflow->ID) {
            return [];
        }
        return [$role->Code];
    }

    public function flowMember(): ?Member
    {
        return null;
    }
}
