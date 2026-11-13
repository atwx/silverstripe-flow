<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Numbering\NumberingExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * @property int $Number
 * @property int $NumberYear
 * @property ?string $Reference
 * @property ?string $InvoiceDate
 * @mixin NumberingExtension
 */
class NumberedInvoice extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_NumberedInvoice';

    private static array $db = [
        'Number' => 'Int',
        'NumberYear' => 'Int',
        'Reference' => 'Varchar(50)',
        'InvoiceDate' => 'Date',
    ];

    private static array $extensions = [
        NumberingExtension::class,
    ];

    private static array $flow_numbering = [
        'sequence' => 'test-invoice',
        'year_field' => 'NumberYear',
        'date_field' => 'InvoiceDate',
        'reference_field' => 'Reference',
        'format' => 'RE-{year}-{number:4}',
    ];
}
