<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\FollowUp;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Workflow;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Follow-ups of a case file: list, add, edit, mark as done.
 */
class FollowUpSubController extends FrontdeskSubController
{
    private static string $managed_model = FollowUp::class;

    private static string $url_segment = 'followups';

    private static string $title = 'Wiedervorlagen';

    private static string $parent_field = 'CaseID';

    /**
     * @return DataList<FollowUp>
     */
    protected function getBaseQuery(): DataList
    {
        // PHPStan reports static<T> from DataList::filter() as incompatible with DataList<T>.
        // @phpstan-ignore return.type
        return FollowUp::get()->filter([
            'CaseClass' => $this->parentController?->getManagedModel(),
            'CaseID' => $this->getParentID(),
        ]);
    }

    protected function defineColumns(): ColumnCollection
    {
        return FollowUpColumns::forCaseFile();
    }

    protected function formFields(FieldList $fields): FieldList
    {
        $fields = parent::formFields($fields);
        $fields->removeByName(['CaseID', 'CaseClass', 'Case', 'RuleID', 'CreatedByID', 'DoneByID', 'DoneAt']);
        $fields->replaceField('MemberID', DropdownField::create(
            'MemberID',
            singleton(FollowUp::class)->fieldLabel('MemberID'),
            $this->memberOptions()
        )->setEmptyString('–'));
        return $fields;
    }

    /**
     * The polymorphic relation needs the class of the case file, too.
     *
     * @param array<string, mixed> $data
     */
    protected function beforeWrite(DataObject $item, array $data): void
    {
        parent::beforeWrite($item, $data);
        if (!$item->isInDB()) {
            $item->setField('CaseClass', $this->parentController?->getManagedModel());
            $item->setField('CaseID', $this->getParentID());
        }
    }

    /**
     * Members of the internal roles of the workflow.
     *
     * @return array<int, string>
     */
    private function memberOptions(): array
    {
        $workflow = Workflow::forClass((string) $this->parentController?->getManagedModel());
        if (!$workflow) {
            return [];
        }
        $groupIDs = FlowRole::get()
            ->filter(['WorkflowID' => $workflow->ID, 'IsExternal' => false])
            ->column('GroupID');
        if (!$groupIDs) {
            return [];
        }
        $options = [];
        foreach (Member::get()->filter('Groups.ID', $groupIDs)->sort('FirstName') as $member) {
            $options[(int) $member->ID] = (string) $member->getName();
        }
        return $options;
    }
}
