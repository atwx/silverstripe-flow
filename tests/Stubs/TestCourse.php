<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Extension\CaseFileExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Domain class for tests: a course run.
 *
 * @mixin CaseFileExtension
 * @property string $Title
 * @property string $Venue
 * @property string $Description
 * @property string $Audience
 * @property string $InternalNote
 */
class TestCourse extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_Course';

    private static array $extensions = [
        CaseFileExtension::class,
    ];

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Venue' => 'Varchar(255)',
        'Description' => 'Text',
        'Audience' => 'Varchar(255)',
        'InternalNote' => 'Text',
    ];

    private static array $flow_field_groups = [
        'basics' => ['title' => 'Eckdaten', 'fields' => ['Title', 'Venue']],
        'announcement' => ['title' => 'Ausschreibung', 'fields' => ['Description', 'Audience']],
        'organisation' => ['InternalNote'],
    ];
}
