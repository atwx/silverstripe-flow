<?php

namespace Atwx\Flow\Tests\Stubs;

use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Sub-list entry of a test course: a participant with a mail address.
 *
 * @property string $Name
 * @property string $Email
 * @property int $CourseID
 * @method TestCourse Course()
 */
class TestParticipant extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_Participant';

    private static array $db = [
        'Name' => 'Varchar(255)',
        'Email' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Course' => TestCourse::class,
    ];

    public function getTitle(): string
    {
        return (string) $this->Name;
    }
}
