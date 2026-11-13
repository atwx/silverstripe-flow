<?php

namespace Atwx\Flow\Api;

use Atwx\Flow\Security\FlowPermissions;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\Queries\SQLDelete;

/**
 * One API request: client, operation, status and duration. Deliberately
 * without payloads, query strings or record keys, because requests may carry
 * personal data (of minors, too).
 *
 * @property ?string $ClientName
 * @property ?string $Operation
 * @property ?string $Method
 * @property int $Status
 * @property int $Duration
 * @property ?string $IP
 * @property int $ClientID
 * @method ApiClient Client()
 */
class ApiLog extends DataObject
{
    private static string $table_name = 'Flow_ApiLog';

    private static array $db = [
        'ClientName' => 'Varchar(255)',
        'Operation' => 'Varchar(255)',
        'Method' => 'Varchar(10)',
        'Status' => 'Int',
        'Duration' => 'Int',
        'IP' => 'Varchar(45)',
    ];

    private static array $has_one = [
        'Client' => ApiClient::class,
    ];

    private static array $indexes = [
        'Created' => true,
    ];

    private static array $summary_fields = [
        'Created',
        'ClientName',
        'Method',
        'Operation',
        'Status',
        'Duration',
    ];

    private static string $default_sort = 'ID DESC';

    /**
     * Days a log entry is kept (sake flow:retention).
     */
    private static int $retention_days = 90;

    /**
     * Requests of the client (by name) in the last $seconds.
     */
    public static function recentCount(string $clientName, int $seconds): int
    {
        $since = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - $seconds);
        return self::get()->filter(['ClientName' => $clientName, 'Created:GreaterThanOrEqual' => $since])->count();
    }

    /**
     * Deletes entries older than retention_days; returns how many.
     */
    public static function prune(): int
    {
        $days = (int) static::config()->get('retention_days');
        if ($days <= 0) {
            return 0;
        }
        $before = date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() - $days * 86400);
        $count = self::get()->filter('Created:LessThan', $before)->count();
        SQLDelete::create('"Flow_ApiLog"', ['"Created" < ?' => $before])->execute();
        return $count;
    }

    public function canView($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
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
