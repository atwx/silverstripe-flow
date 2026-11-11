<?php

namespace Atwx\Flow\Ui;

use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Service\FieldAccess;
use Atwx\SilverstripeFrontdeskKit\Forms\FieldLayout;
use SilverStripe\ORM\DataObject;

/**
 * Builds the kit's FieldLayout of a case file for an actor: field groups (title
 * and order from the workflow, fields from the class declaration) and the
 * access level of every field from FieldAccess. Fields outside the groups are
 * hidden. Used by the internal and the external (link) controller.
 */
class FlowFieldLayout
{
    public static function build(DataObject $record, ?FlowActor $actor): ?FieldLayout
    {
        $workflow = Workflow::forClass($record->ClassName);
        if (!$workflow) {
            return null;
        }
        $layout = FieldLayout::create()->setDefaultLevel(FieldLayout::HIDDEN);
        $fields = FieldGroup::fieldsFor($workflow);
        foreach (FieldGroup::get()->filter('WorkflowID', $workflow->ID) as $group) {
            if (isset($fields[$group->Code])) {
                $layout->group((string) $group->Title, $fields[$group->Code]);
            }
        }
        if ($actor) {
            $layout->setLevels(FieldAccess::singleton()->fieldLevels($record, $actor));
        }
        return $layout;
    }
}
