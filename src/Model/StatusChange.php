<?php

namespace Atwx\Flow\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * One entry of the status history of a case file. Written only by the
 * transition service and never changed afterwards.
 *
 * The first entry of a case has no source status (the case was created).
 *
 * @property string $Comment
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $FromID
 * @property int $ToID
 * @property int $MemberID
 * @property int $AccessLinkID
 * @method DataObject Case()
 * @method Status From()
 * @method Status To()
 * @method Member Member()
 * @method AccessLink AccessLink()
 */
class StatusChange extends DataObject
{
    private static string $table_name = 'Flow_StatusChange';

    private static array $db = [
        'Comment' => 'Text',
    ];

    private static array $has_one = [
        'Case' => DataObject::class,
        'From' => Status::class,
        'To' => Status::class,
        'Member' => Member::class,
        'AccessLink' => AccessLink::class,
    ];

    private static array $summary_fields = [
        'Created.Nice',
        'From.Title',
        'To.Title',
        'Member.Name',
        'Comment',
    ];

    private static string $default_sort = 'Created DESC, ID DESC';

    /**
     * Who did it: the member, or the person of the edit link.
     */
    public function getActorName(): string
    {
        $member = $this->Member();
        if ($member->exists()) {
            return (string) $member->getName();
        }
        $link = $this->AccessLink();
        return $link->exists() ? $link->getLabel() : '';
    }

    /**
     * Visible to whoever may view the case file.
     */
    public function canView($member = null): bool
    {
        $case = $this->Case();
        return $case->exists() && $case->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    /**
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Created.Nice'] = _t(self::class . '.Created', 'Date');
        $labels['From.Title'] = _t(self::class . '.has_one_From', 'From');
        $labels['To.Title'] = _t(self::class . '.has_one_To', 'To');
        $labels['Member.Name'] = _t(self::class . '.has_one_Member', 'By');
        return $labels;
    }
}
