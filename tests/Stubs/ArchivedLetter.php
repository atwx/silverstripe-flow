<?php

namespace Atwx\Flow\Tests\Stubs;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;
use SilverStripe\Versioned\Versioned;

/**
 * Versioned record for deletion runs.
 *
 * @property ?string $Title
 * @mixin Versioned
 */
class ArchivedLetter extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_ArchivedLetter';

    private static array $db = [
        'Title' => 'Varchar(255)',
    ];

    private static array $extensions = [
        Versioned::class,
    ];
}
