<?php

namespace Atwx\Flow\Tests\Stubs;

use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Keeps the comments of a test course on its first participant (added in tests only).
 *
 * @extends Extension<TestCourse>
 */
class TestCommentRedirect extends Extension implements TestOnly
{
    public function flowCommentRecord(): ?DataObject
    {
        return TestParticipant::get()->filter('CourseID', $this->getOwner()->ID)->sort('ID')->first();
    }
}
