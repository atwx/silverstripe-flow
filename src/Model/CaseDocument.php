<?php

namespace Atwx\Flow\Model;

use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * A document of a case file, created from a template (PDF). The file is kept
 * in the protected asset store and only served through the case controller.
 *
 * @property string $Title
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $TemplateID
 * @property int $MemberID
 * @property DBFile $File
 * @method DataObject Case()
 * @method DocumentTemplate Template()
 * @method Member Member()
 */
class CaseDocument extends DataObject
{
    private static string $table_name = 'Flow_Document';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'File' => 'DBFile',
    ];

    private static array $has_one = [
        'Case' => DataObject::class,
        'Template' => DocumentTemplate::class,
        'Member' => Member::class,
    ];

    private static array $summary_fields = [
        'Title',
        'Created.Nice',
        'Member.Name',
    ];

    private static string $default_sort = 'Created DESC, ID DESC';

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Title'] = _t(self::class . '.db_Title', 'Title');
        $labels['Created.Nice'] = _t(self::class . '.CREATED', 'Created');
        $labels['Member.Name'] = _t(self::class . '.has_one_Member', 'By');
        return $labels;
    }

    /**
     * Visible to members who may view the case file, never without login: the
     * asset store publishes files of records anonymous visitors may view.
     */
    public function canView($member = null): bool
    {
        $member = $member ?? Security::getCurrentUser();
        if (!$member) {
            return false;
        }
        $case = $this->Case();
        return $case->exists() && $case->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    /**
     * Documents are created through DocumentService.
     *
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }

    /**
     * The file goes with the document.
     */
    protected function onAfterDelete(): void
    {
        parent::onAfterDelete();
        if ($this->File->exists()) {
            $this->File->deleteFile();
        }
    }
}
