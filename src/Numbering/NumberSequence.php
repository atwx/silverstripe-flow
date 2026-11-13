<?php

namespace Atwx\Flow\Numbering;

use Atwx\Flow\Security\FlowPermissions;
use SilverStripe\ORM\DataObject;

/**
 * The last number drawn of a number sequence (Code) in a period (a year or
 * "" for a sequence that never restarts). Admins can set LastNumber in the
 * CMS, e.g. to continue the numbers of an old system.
 *
 * @property ?string $Code
 * @property ?string $Period
 * @property int $LastNumber
 */
class NumberSequence extends DataObject
{
    private static string $table_name = 'Flow_NumberSequence';

    private static string $singular_name = 'Number sequence';

    private static string $plural_name = 'Number sequences';

    private static array $db = [
        'Code' => 'Varchar(100)',
        'Period' => 'Varchar(20)',
        'LastNumber' => 'Int',
    ];

    private static array $indexes = [
        'CodePeriod' => ['type' => 'unique', 'columns' => ['Code', 'Period']],
    ];

    private static array $summary_fields = [
        'Code',
        'Period',
        'LastNumber',
    ];

    private static string $default_sort = '"Code" ASC, "Period" DESC';

    public function getTitle(): string
    {
        return trim($this->Code . ' ' . $this->Period);
    }

    /**
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Code'] = _t(self::class . '.Code', 'Sequence');
        $labels['Period'] = _t(self::class . '.Period', 'Year');
        $labels['LastNumber'] = _t(self::class . '.LastNumber', 'Last number');
        return $labels;
    }

    public function canView($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    public function canEdit($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    public function canDelete($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
    }
}
