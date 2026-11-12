<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Extension\CommentsControllerExtension;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Frontdesk controller of participants (no case files) with comments; the
 * comments of a participant named "Kurs" go to the course (commentRecordFor).
 */
class TestParticipantController extends FrontdeskController implements TestOnly
{
    private static string $managed_model = TestParticipant::class;

    private static string $url_segment = 'test-participants';

    private static array $extensions = [
        CommentsControllerExtension::class,
    ];

    public function commentRecordFor(DataObject $record): DataObject
    {
        return $record instanceof TestParticipant && $record->Name === 'Kurs' ? $record->Course() : $record;
    }
}
