<?php

namespace Atwx\Flow\Intake;

use Atwx\Flow\Api\ApiClient;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * A form submission in the inbox ("Eingang"): data of a form type (JSON), its
 * files and its status: new, taken over (into the project's records) or
 * discarded.
 *
 * @property ?string $Type
 * @property ?string $Reference
 * @property int $Year
 * @property int $Number
 * @property ?string $Status
 * @property ?string $StatusChanged
 * @property ?string $Data
 * @property ?string $Summary
 * @property ?string $IdempotencyKey
 * @property ?string $TakeoverLinks
 * @property ?string $NotifiedAt
 * @property ?string $NotifyError
 * @property ?string $ReceiptTokenHash
 * @property ?string $ReceiptExpires
 * @property int $ClientID
 * @property int $TakenOverByID
 * @method ApiClient Client()
 * @method Member TakenOverBy()
 * @method HasManyList<SubmissionFile> Files()
 */
class Submission extends DataObject
{
    public const string NEW = 'new';
    public const string TAKEN_OVER = 'taken_over';
    public const string DISCARDED = 'discarded';

    private static string $table_name = 'Flow_Submission';

    private static array $db = [
        'Type' => 'Varchar(64)',
        'Reference' => 'Varchar(32)',
        'Year' => 'Int',
        'Number' => 'Int',
        'Status' => "Enum('new,taken_over,discarded','new')",
        'StatusChanged' => 'Datetime',
        'Data' => 'Text',
        'Summary' => 'Varchar(255)',
        'IdempotencyKey' => 'Varchar(128)',
        'TakeoverLinks' => 'Text',
        'NotifiedAt' => 'Datetime',
        'NotifyError' => 'Varchar(255)',
        'ReceiptTokenHash' => 'Varchar(64)',
        'ReceiptExpires' => 'Datetime',
    ];

    private static array $has_one = [
        'Client' => ApiClient::class,
        'TakenOverBy' => Member::class,
    ];

    private static array $has_many = [
        'Files' => SubmissionFile::class,
    ];

    private static array $indexes = [
        'Reference' => ['type' => 'unique', 'columns' => ['Reference']],
        'TypeYearNumber' => ['type' => 'unique', 'columns' => ['Type', 'Year', 'Number']],
        'Idempotency' => ['type' => 'index', 'columns' => ['ClientID', 'IdempotencyKey']],
        'ReceiptTokenHash' => true,
        'Status' => true,
    ];

    private static array $summary_fields = [
        'Reference',
        'Created',
        'Summary',
        'StatusTitle',
    ];

    private static string $default_sort = 'Created DESC, ID DESC';

    /**
     * Names of fields hidden from the generic form and record view (data,
     * tokens); the inbox shows the data by its form type.
     */
    private static array $fdk_hidden_fields = [
        'Type', 'Reference', 'Year', 'Number', 'Status', 'StatusChanged', 'Data', 'Summary', 'IdempotencyKey',
        'TakeoverLinks', 'NotifiedAt', 'NotifyError', 'ReceiptTokenHash', 'ReceiptExpires', 'Client', 'TakenOverBy',
    ];

    public function intakeType(): ?IntakeType
    {
        return IntakeService::singleton()->type((string) $this->Type);
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = json_decode((string) $this->Data, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Value by path, e.g. "parents.0.email".
     */
    public function value(string $path): mixed
    {
        $value = $this->values();
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function storeValues(array $data): static
    {
        $this->Data = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this;
    }

    public function getTitle(): string
    {
        $type = $this->intakeType();
        $title = ($type ? $type->getTitle() : (string) $this->Type) . ' ' . $this->Reference;
        return $this->Summary ? $title . ' – ' . $this->Summary : $title;
    }

    public function getStatusTitle(): string
    {
        return match ((string) $this->Status) {
            self::TAKEN_OVER => _t(self::class . '.STATUS_TAKEN_OVER', 'Taken over'),
            self::DISCARDED => _t(self::class . '.STATUS_DISCARDED', 'Discarded'),
            default => _t(self::class . '.STATUS_NEW', 'New'),
        };
    }

    public function setStatus(string $status): static
    {
        if ($status !== $this->Status) {
            $this->setField('Status', $status);
            $this->StatusChanged = DBDatetime::now()->getValue();
        }
        return $this;
    }

    /**
     * Files of a file field (or all).
     *
     * @return array<int, File>
     */
    public function filesOf(?string $field = null): array
    {
        $links = $this->Files();
        if ($field !== null) {
            $links = $links->filter('FieldName', $field);
        }
        $files = [];
        foreach ($links as $link) {
            $file = Versioned::withVersionedMode(function () use ($link) {
                Versioned::set_stage(Versioned::DRAFT);
                return File::get()->byID($link->FileID);
            });
            if ($file instanceof File) {
                $files[] = $file;
            }
        }
        return $files;
    }

    /**
     * Detaches a file from the submission: the takeover keeps it (e.g. a school
     * report moved to the child), so deleting the submission leaves it alone.
     */
    public function claimFile(File $file): void
    {
        foreach ($this->Files()->filter('FileID', $file->ID) as $link) {
            $link->delete();
        }
    }

    /**
     * Records the takeover created or changed: class, ID and title.
     *
     * @return array<int, array{class: string, id: int, title: string}>
     */
    public function takeoverLinks(): array
    {
        $links = json_decode((string) $this->TakeoverLinks, true);
        return is_array($links) ? array_values(array_filter($links, 'is_array')) : [];
    }

    /**
     * @param array<int, DataObject> $records
     */
    public function setTakeoverRecords(array $records): static
    {
        $links = [];
        foreach ($records as $record) {
            if ($record->isInDB()) {
                $links[] = [
                    'class' => $record::class,
                    'id' => $record->ID,
                    'title' => (string) $record->getTitle(),
                ];
            }
        }
        $this->TakeoverLinks = (string) json_encode($links, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $this;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $type = $this->intakeType();
        if ($type && $this->isChanged('Data')) {
            [, $errors] = $type->validate($this->values());
            foreach ($errors as $path => $message) {
                $result->addFieldError(self::formFieldName($path), $path . ': ' . $message);
            }
        }
        return $result;
    }

    /**
     * Form field name of a data path: IntakeData[siblings][0][first_name].
     */
    public static function formFieldName(string $path): string
    {
        $parts = explode('.', $path);
        return 'IntakeData[' . implode('][', $parts) . ']';
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (!$this->StatusChanged) {
            $this->StatusChanged = DBDatetime::now()->getValue();
        }
        $type = $this->intakeType();
        if ($type) {
            $this->Summary = mb_substr(IntakeService::singleton()->summary($type, $this->values()), 0, 255);
        }
    }

    protected function onBeforeDelete(): void
    {
        parent::onBeforeDelete();
        $folders = [];
        foreach ($this->filesOf() as $file) {
            $folders[$file->ParentID] = true;
            $file->deleteFromStage(Versioned::LIVE);
            $file->deleteFromStage(Versioned::DRAFT);
        }
        foreach ($this->Files() as $link) {
            $link->delete();
        }
        // The folder of the submission (named after its reference), when empty
        foreach (array_keys($folders) as $folderID) {
            $folder = Versioned::get_by_stage(Folder::class, Versioned::DRAFT)->byID((int) $folderID);
            if (
                $folder instanceof Folder && !Versioned::get_by_stage(File::class, Versioned::DRAFT)
                ->filter('ParentID', $folder->ID)->exists()
            ) {
                $folder->deleteFromStage(Versioned::LIVE);
                $folder->deleteFromStage(Versioned::DRAFT);
            }
        }
    }

    /**
     * Inbox permission: the frontdesk codes of the inbox (FDK_INTAKE_VIEW /
     * FDK_INTAKE_EDIT) plus the type's own permission, if it sets one.
     */
    private function allowed(string $level, mixed $member): bool
    {
        $member = $member instanceof Member ? $member : Security::getCurrentUser();
        if (!$member) {
            return false;
        }
        if (Permission::checkMember($member, 'ADMIN')) {
            return true;
        }
        $codes = $level === 'view' ? ['FDK_INTAKE_VIEW', 'FDK_INTAKE_EDIT'] : ['FDK_INTAKE_EDIT'];
        if (!Permission::checkMember($member, $codes)) {
            return false;
        }
        $typePermission = $this->intakeType()?->option('permission');
        return !$typePermission || Permission::checkMember($member, (string) $typePermission);
    }

    public function canView($member = null): bool
    {
        return $this->allowed('view', $member);
    }

    public function canEdit($member = null): bool
    {
        return $this->allowed('edit', $member);
    }

    public function canDelete($member = null): bool
    {
        return $this->allowed('edit', $member);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
