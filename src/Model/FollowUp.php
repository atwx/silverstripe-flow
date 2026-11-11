<?php

namespace Atwx\Flow\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * A follow-up of a case file ("Wiedervorlage"): due date, text and the member
 * who takes care of it; created by hand or by a FollowUpRule.
 *
 * @property ?string $DueDate
 * @property ?string $Text
 * @property bool $IsDone
 * @property ?string $DoneAt
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $MemberID
 * @property int $RuleID
 * @property int $CreatedByID
 * @property int $DoneByID
 * @method DataObject Case()
 * @method Member Member()
 * @method FollowUpRule Rule()
 * @method Member CreatedBy()
 * @method Member DoneBy()
 */
class FollowUp extends DataObject
{
    private static string $table_name = 'Flow_FollowUp';

    private static array $db = [
        'DueDate' => 'Date',
        'Text' => 'Varchar(255)',
        'IsDone' => 'Boolean',
        'DoneAt' => 'Datetime',
    ];

    private static array $has_one = [
        'Case' => DataObject::class,
        'Member' => Member::class,
        'Rule' => FollowUpRule::class,
        'CreatedBy' => Member::class,
        'DoneBy' => Member::class,
    ];

    private static array $summary_fields = [
        'DueDate.Nice',
        'Text',
        'Member.Name',
        'IsDone.Nice',
    ];

    private static string $default_sort = 'IsDone ASC, DueDate ASC, ID ASC';

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['DueDate'] = $labels['DueDate.Nice'] = _t(self::class . '.db_DueDate', 'Due');
        $labels['Text'] = _t(self::class . '.db_Text', 'Text');
        $labels['Member'] = $labels['MemberID'] = $labels['Member.Name'] = _t(self::class . '.has_one_Member', 'For');
        $labels['IsDone'] = $labels['IsDone.Nice'] = _t(self::class . '.db_IsDone', 'Done');
        return $labels;
    }

    public function isOverdue(): bool
    {
        return !$this->IsDone && $this->DueDate && $this->DueDate < date('Y-m-d');
    }

    /**
     * Records who closed it and when.
     */
    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if ($this->isChanged('IsDone', DataObject::CHANGE_VALUE)) {
            $this->DoneAt = $this->IsDone ? date('Y-m-d H:i:s') : null;
            $this->DoneByID = $this->IsDone ? (int) Security::getCurrentUser()?->ID : 0;
        }
        if (!$this->isInDB() && !$this->CreatedByID) {
            $this->CreatedByID = (int) Security::getCurrentUser()?->ID;
        }
    }

    /**
     * Visible to members who may view the case file, never without login.
     */
    public function canView($member = null): bool
    {
        $member = $member ?? Security::getCurrentUser();
        if (!$member) {
            return false;
        }
        $case = $this->Case();
        return $case->exists() && $case->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return $this->canView($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canView($member);
    }

    /**
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return (bool) ($member ?? Security::getCurrentUser());
    }
}
