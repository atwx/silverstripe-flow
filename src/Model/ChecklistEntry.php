<?php

namespace Atwx\Flow\Model;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * State of a checklist item in a case file.
 *
 * @property string $State
 * @property ?string $Date
 * @property ?string $Comment
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $ItemID
 * @property int $ChangedByID
 * @method DataObject Case()
 * @method ChecklistItem Item()
 * @method Member ChangedBy()
 */
class ChecklistEntry extends DataObject
{
    public const array COMPLETE = ['done', 'not_needed'];

    private static string $table_name = 'Flow_ChecklistEntry';

    private static array $db = [
        'State' => "Enum('open,requested,done,not_needed', 'open')",
        'Date' => 'Date',
        'Comment' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Case' => DataObject::class,
        'Item' => ChecklistItem::class,
        'ChangedBy' => Member::class,
    ];

    private static array $indexes = [
        'CaseItem' => ['type' => 'unique', 'columns' => ['CaseClass', 'CaseID', 'ItemID']],
    ];

    /**
     * @return array<string, string>
     */
    public static function stateOptions(): array
    {
        return [
            'open' => _t(self::class . '.STATE_OPEN', 'open'),
            'requested' => _t(self::class . '.STATE_REQUESTED', 'requested'),
            'done' => _t(self::class . '.STATE_DONE', 'done'),
            'not_needed' => _t(self::class . '.STATE_NOT_NEEDED', 'not needed'),
        ];
    }

    public function getStateLabel(): string
    {
        return self::stateOptions()[(string) $this->State] ?? (string) $this->State;
    }

    public function getItemTitle(): string
    {
        $item = $this->Item();
        return $item->exists() ? (string) $item->Title : '';
    }

    public function isComplete(): bool
    {
        return in_array((string) $this->State, self::COMPLETE, true);
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Item.Title'] = $labels['Item'] = _t(self::class . '.has_one_Item', 'Item');
        $labels['State'] = $labels['StateLabel'] = _t(self::class . '.db_State', 'State');
        $labels['Date'] = _t(self::class . '.db_Date', 'Date');
        $labels['Comment'] = _t(self::class . '.db_Comment', 'Comment');
        return $labels;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if ($this->isChanged('State', DataObject::CHANGE_VALUE) && $this->isInDB()) {
            $this->ChangedByID = (int) Security::getCurrentUser()?->ID;
            if (!$this->Date && $this->isComplete()) {
                $this->Date = date('Y-m-d');
            }
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
        return false;
    }

    /**
     * Entries are created by ChecklistService, one per item.
     *
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
