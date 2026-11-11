<?php

namespace Atwx\Flow\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * A note in the timeline of a case file. Notes are not changed afterwards.
 *
 * @property string $Text
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $MemberID
 * @property int $AccessLinkID
 * @method DataObject Case()
 * @method Member Member()
 * @method AccessLink AccessLink()
 */
class Note extends DataObject
{
    private static string $table_name = 'Flow_Note';

    private static array $db = [
        'Text' => 'Text',
    ];

    private static array $has_one = [
        'Case' => DataObject::class,
        'Member' => Member::class,
        'AccessLink' => AccessLink::class,
    ];

    private static array $summary_fields = [
        'Created.Nice',
        'Member.Name',
        'Text',
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
     * Notes are added through CaseFileExtension::addNote().
     *
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
