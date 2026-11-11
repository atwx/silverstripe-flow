<?php

namespace Atwx\Flow\Tests\Stubs;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Domain class for tests: a course run.
 *
 * @property string $Title
 */
class TestCourse extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_Course';

    private static array $db = [
        'Title' => 'Varchar(255)',
    ];
}
