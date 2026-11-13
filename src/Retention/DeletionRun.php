<?php

namespace Atwx\Flow\Retention;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

/**
 * Log of a deletion run: rule, cutoff, who started it and how many records
 * were deleted, kept or failed. Holds no personal data of the deleted records.
 *
 * @property ?string $Rule
 * @property ?string $RuleTitle
 * @property ?string $Cutoff
 * @property int $Deleted
 * @property int $Kept
 * @property int $Failed
 * @property bool $Automatic
 * @property ?string $Details
 * @property int $MemberID
 * @method Member Member()
 */
class DeletionRun extends DataObject
{
    private static string $table_name = 'Flow_DeletionRun';

    private static string $singular_name = 'Deletion run';

    private static string $plural_name = 'Deletion runs';

    private static array $db = [
        'Rule' => 'Varchar(100)',
        'RuleTitle' => 'Varchar(255)',
        'Cutoff' => 'Date',
        'Deleted' => 'Int',
        'Kept' => 'Int',
        'Failed' => 'Int',
        'Automatic' => 'Boolean',
        // Kept records per reason, as JSON
        'Details' => 'Text',
    ];

    private static array $has_one = [
        'Member' => Member::class,
    ];

    private static array $summary_fields = [
        'Created',
        'RuleTitle',
        'Cutoff',
        'Deleted',
        'Kept',
        'Failed',
        'StartedBy',
    ];

    private static string $default_sort = '"Created" DESC, "ID" DESC';

    public function getTitle(): string
    {
        return trim($this->RuleTitle . ' ' . $this->Created);
    }

    public function getStartedBy(): string
    {
        if ($this->Automatic) {
            return _t(self::class . '.AUTOMATIC', 'automatic');
        }
        $member = $this->Member();
        return $member->exists() ? (string) $member->getName() : '';
    }

    /**
     * Kept records per reason.
     *
     * @return array<string, int>
     */
    public function keptReasons(): array
    {
        $reasons = json_decode((string) $this->Details, true);
        return is_array($reasons) ? array_map('intval', $reasons) : [];
    }

    public function getKeptReasonsText(): string
    {
        $parts = [];
        foreach ($this->keptReasons() as $reason => $count) {
            $parts[] = sprintf('%s: %d', $reason, $count);
        }
        return implode(', ', $parts);
    }

    /**
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Created'] = _t(self::class . '.Created', 'Run at');
        $labels['RuleTitle'] = _t(self::class . '.RuleTitle', 'Rule');
        $labels['Cutoff'] = _t(self::class . '.Cutoff', 'Dated before');
        $labels['Deleted'] = _t(self::class . '.Deleted', 'Deleted');
        $labels['Kept'] = _t(self::class . '.Kept', 'Kept');
        $labels['Failed'] = _t(self::class . '.Failed', 'Failed');
        $labels['StartedBy'] = _t(self::class . '.StartedBy', 'Started by');
        $labels['KeptReasonsText'] = _t(self::class . '.KeptReasons', 'Reasons kept');
        return $labels;
    }

    public function canView($member = null): bool
    {
        return Permission::check(['FDK_DELETION_RUNS_VIEW', 'FDK_DELETION_RUNS_EDIT'], 'any', $member);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }
}
