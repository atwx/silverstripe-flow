<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Numbering\NumberingExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property int $Number
 * @mixin NumberingExtension
 */
class NumberedDraft extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_NumberedDraft';

    private static array $db = [
        'Number' => 'Int',
    ];

    private static array $extensions = [
        NumberingExtension::class,
    ];

    private static array $flow_numbering = [
        'assign' => 'manual',
        'format' => 'A{yy}/{number}',
    ];
}
