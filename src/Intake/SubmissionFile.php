<?php

namespace Atwx\Flow\Intake;

use SilverStripe\Assets\File;
use SilverStripe\ORM\DataObject;

/**
 * A file of a submission, by the file field of its form type. The stored file
 * is named after the field (no personal data in file names or file versions);
 * the name given by the submitter is kept here. The file stays
 * unpublished (protected) and is deleted with the submission, unless a
 * takeover claimed it (Submission::claimFile()).
 *
 * @property ?string $FieldName
 * @property ?string $OriginalName
 * @property int $SubmissionID
 * @property int $FileID
 * @method Submission Submission()
 * @method File File()
 */
class SubmissionFile extends DataObject
{
    private static string $table_name = 'Flow_SubmissionFile';

    private static array $db = [
        'FieldName' => 'Varchar(64)',
        'OriginalName' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Submission' => Submission::class,
        'File' => File::class,
    ];

    private static string $default_sort = 'ID ASC';

    public function canView($member = null): bool
    {
        $submission = $this->Submission();
        return $submission->exists() && $submission->canView($member);
    }

    public function canEdit($member = null): bool
    {
        $submission = $this->Submission();
        return $submission->exists() && $submission->canEdit($member);
    }

    public function canDelete($member = null): bool
    {
        $submission = $this->Submission();
        return $submission->exists() && $submission->canDelete($member);
    }
}
