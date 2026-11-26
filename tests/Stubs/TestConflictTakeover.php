<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Intake\Submission;
use Atwx\Flow\Intake\TakeoverConflicts;
use SilverStripe\Security\Member;

/**
 * Like TestTakeover, but asks before changing an existing participant: name
 * and e-mail are only overwritten when the staff member decides so; empty
 * fields are filled without asking.
 */
class TestConflictTakeover extends TestTakeover implements TakeoverConflicts
{
    public function choices(Submission $submission): array
    {
        return ['new' => 'New participant'] + TestParticipant::get()->map('ID', 'Name')->toArray();
    }

    public function conflicts(Submission $submission, ?string $choice): array
    {
        $participant = $choice !== 'new' ? TestParticipant::get()->byID((int) $choice) : null;
        if (!$participant) {
            return [];
        }
        $conflicts = [];
        foreach (['Name' => 'name', 'Email' => 'email'] as $field => $path) {
            $current = (string) $participant->$field;
            $new = (string) $submission->value($path);
            if ($current !== '' && $current !== $new) {
                $conflicts[] = [
                    'key' => 'participant.' . $participant->ID . '.' . $field,
                    'group' => 'Teilnehmer ' . $current,
                    'label' => $field,
                    'current' => $current,
                    'new' => $new,
                ];
            }
        }
        return $conflicts;
    }

    public function takeOver(Submission $submission, ?string $choice, Member $member): array
    {
        $participant = $choice !== 'new' ? TestParticipant::get()->byID((int) $choice) : null;
        if (!$participant) {
            return parent::takeOver($submission, $choice, $member);
        }
        foreach (['Name' => 'name', 'Email' => 'email'] as $field => $path) {
            $key = 'participant.' . $participant->ID . '.' . $field;
            if ((string) $participant->$field === '' || $submission->takesNew($key)) {
                $participant->$field = (string) $submission->value($path);
            }
        }
        $participant->write();
        return [$participant];
    }
}
