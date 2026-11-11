<?php

namespace Atwx\Flow\Model;

use SilverStripe\ORM\DataObject;

/**
 * Use of an edit link (shown in the timeline): the page was opened or changes
 * were saved.
 *
 * @property string $Kind
 * @property string $Fields
 * @property int $LinkID
 * @method AccessLink Link()
 */
class LinkAccess extends DataObject
{
    public const string VIEW = 'view';
    public const string SAVE = 'save';

    private static string $table_name = 'Flow_LinkAccess';

    private static array $db = [
        'Kind' => "Enum('view,save', 'view')",
        'Fields' => 'Text',
    ];

    private static array $has_one = [
        'Link' => AccessLink::class,
    ];

    private static string $default_sort = 'Created DESC, ID DESC';

    public function canView($member = null): bool
    {
        $link = $this->Link();
        return $link->exists() && $link->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}
