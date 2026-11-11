<?php

namespace Atwx\Flow\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * The member responsible for a case file in one responsibility.
 * Changed only through AssignmentService (which records the change).
 *
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $ResponsibilityID
 * @property int $MemberID
 * @method DataObject Case()
 * @method Responsibility Responsibility()
 * @method Member Member()
 */
class Assignment extends DataObject
{
    private static string $table_name = 'Flow_Assignment';

    private static array $has_one = [
        'Case' => DataObject::class,
        'Responsibility' => Responsibility::class,
        'Member' => Member::class,
    ];

    private static array $indexes = [
        'CaseResponsibility' => [
            'type' => 'unique',
            'columns' => ['CaseClass', 'CaseID', 'ResponsibilityID'],
        ],
    ];

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
