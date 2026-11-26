<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Extension\CommentsExtension;
use SilverStripe\Assets\File;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Sub-list entry of a test course: a participant with a mail address.
 *
 * @property string $Name
 * @property string $Email
 * @property int $CourseID
 * @method TestCourse Course()
 * @mixin CommentsExtension
 */
class TestParticipant extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_Participant';

    private static array $extensions = [
        CommentsExtension::class,
    ];

    private static array $db = [
        'Name' => 'Varchar(255)',
        'Email' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Course' => TestCourse::class,
        'Photo' => File::class,
    ];

    /**
     * Records comments may refer to (CommentSubController references); empty: none.
     *
     * @var array<int, DataObject>
     */
    public static array $referenceTargets = [];

    public function getTitle(): string
    {
        return (string) $this->Name;
    }

    /**
     * @return array<int, DataObject>
     */
    public function flowCommentReferences(): array
    {
        return self::$referenceTargets;
    }

    public function flowCommentReferencesTitle(): string
    {
        return 'Kurse';
    }
}
