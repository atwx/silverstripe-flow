<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Extension\CaseFileExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Third domain class for tests, without a workflow.
 *
 * @mixin CaseFileExtension
 * @property string $Subject
 */
class TestTicket extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_Ticket';

    private static array $extensions = [
        CaseFileExtension::class,
    ];

    private static array $db = [
        'Subject' => 'Varchar(255)',
    ];
}
