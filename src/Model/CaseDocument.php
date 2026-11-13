<?php

namespace Atwx\Flow\Model;

use Atwx\Flow\Controller\FollowUpController;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * A document of a case file, created from a template (PDF). The file is kept
 * in the protected asset store and only served through the case controller.
 * The inputs of the dialog (free text, chosen entries, signer) are kept, so
 * the document can be edited and created again from the current data.
 *
 * @property string $Title
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $TemplateID
 * @property int $MemberID
 * @property ?string $FreeText
 * @property ?string $SelectedIDs
 * @property ?string $BlockIDs
 * @property int $SignerID
 * @property DBFile $File
 * @method DataObject Case()
 * @method DocumentTemplate Template()
 * @method Member Member()
 * @method Member Signer()
 */
class CaseDocument extends DataObject
{
    private static string $table_name = 'Flow_Document';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'File' => 'DBFile',
        'FreeText' => 'Text',
        'SelectedIDs' => 'Text',
        'BlockIDs' => 'Text',
    ];

    private static array $has_one = [
        'Case' => DataObject::class,
        'Template' => DocumentTemplate::class,
        'Member' => Member::class,
        'Signer' => Member::class,
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

    /**
     * Editable (inputs changed, PDF created again) and deletable by members who
     * may create documents in the case file (FlowCaseController checks the role).
     */
    public function canEdit($member = null): bool
    {
        $current = Security::getCurrentUser();
        $member = $member ?? $current;
        $case = $this->Case();
        if (!$member || !$case->exists()) {
            return false;
        }
        // The case controller decides (edit permission and a role in the workflow), for the current member.
        $controller = FollowUpController::caseControllerFor($case::class);
        if ($controller && $current && (int) $member->ID === (int) $current->ID) {
            return $controller->mayWriteDocuments($case);
        }
        return $case->canEdit($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canEdit($member);
    }

    /**
     * @return array<int, int>
     */
    public function selectedIDs(): array
    {
        $ids = json_decode((string) $this->SelectedIDs, true);
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * IDs of the chosen text blocks.
     *
     * @return array<int, int>
     */
    public function blockIDs(): array
    {
        $ids = json_decode((string) $this->BlockIDs, true);
        return is_array($ids) ? array_values(array_map('intval', $ids)) : [];
    }

    /**
     * @param array<int, int|string> $ids
     */
    public function setSelectedIDList(array $ids): static
    {
        $this->SelectedIDs = (string) json_encode(array_values(array_map('intval', $ids)));
        return $this;
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
