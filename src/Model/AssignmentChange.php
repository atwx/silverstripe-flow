<?php

namespace Atwx\Flow\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * A change of responsibility in a case file (timeline).
 *
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $ResponsibilityID
 * @property int $FromID
 * @property int $ToID
 * @property int $MemberID
 * @method DataObject Case()
 * @method Responsibility Responsibility()
 * @method Member From()
 * @method Member To()
 * @method Member Member()
 */
class AssignmentChange extends DataObject
{
    private static string $table_name = 'Flow_AssignmentChange';

    private static array $has_one = [
        'Case' => DataObject::class,
        'Responsibility' => Responsibility::class,
        'From' => Member::class,
        'To' => Member::class,
        'Member' => Member::class,
    ];

    private static string $default_sort = 'Created DESC, ID DESC';

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
}
