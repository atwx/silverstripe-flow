<?php

namespace Atwx\Flow\Tests\Stubs;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * A record everyone may view (like project models that leave access to controllers).
 */
class OpenRecord extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_OpenRecord';

    private static array $db = ['Title' => 'Varchar'];

    public function canView($member = null): bool
    {
        return true;
    }
}
