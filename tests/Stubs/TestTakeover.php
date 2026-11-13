<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Intake\Submission;
use Atwx\Flow\Intake\TakeoverException;
use Atwx\Flow\Intake\TakeoverHandler;
use SilverStripe\Dev\TestOnly;
use SilverStripe\Security\Member;

/**
 * Takes a registration over as a participant (existing by e-mail or new) and
 * keeps the photo.
 */
class TestTakeover implements TakeoverHandler, TestOnly
{
    public function choices(Submission $submission): array
    {
        $choices = ['new' => 'New participant'];
        foreach (TestParticipant::get()->filter('Email', (string) $submission->value('email')) as $participant) {
            $choices[(string) $participant->ID] = 'Existing: ' . $participant->Name;
        }
        return $choices;
    }

    public function takeOver(Submission $submission, ?string $choice, Member $member): array
    {
        if ($submission->value('name') === 'Refused') {
            throw new TakeoverException('Not this one');
        }
        $participant = $choice !== 'new' ? TestParticipant::get()->byID((int) $choice) : null;
        $participant ??= TestParticipant::create();
        $participant->Name = (string) $submission->value('name');
        $participant->Email = (string) $submission->value('email');
        $photo = $submission->filesOf('photo')[0] ?? null;
        if ($photo) {
            $submission->claimFile($photo);
            $participant->PhotoID = $photo->ID;
        }
        $participant->write();
        return [$participant];
    }
}
