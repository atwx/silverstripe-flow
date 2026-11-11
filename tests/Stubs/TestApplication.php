<?php

namespace Atwx\Flow\Tests\Stubs;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Second, unrelated domain class for tests: a funding application.
 *
 * @property string $Applicant
 */
class TestApplication extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_Application';

    private static array $db = [
        'Applicant' => 'Varchar(255)',
    ];
}
