<?php

namespace Atwx\Flow\Intake;

use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Project code that maps a submission into the project's records (form type
 * option "takeover"). Runs inside a transaction; throw TakeoverException to
 * refuse with a message.
 */
interface TakeoverHandler
{
    /**
     * Choices offered in the takeover dialog, e.g. matching families and
     * "new family": value => label. Empty = the dialog only confirms.
     *
     * @return array<string, string>
     */
    public function choices(Submission $submission): array;

    /**
     * Creates or changes the records. Files to keep are claimed with
     * $submission->claimFile($file); data corrections are already saved.
     *
     * @return array<int, DataObject> Records created or changed (linked from the submission)
     */
    public function takeOver(Submission $submission, ?string $choice, Member $member): array;
}
