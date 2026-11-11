<?php

namespace Atwx\Flow\Model;

use Atwx\Flow\Extension\CaseFileExtension;
use SilverStripe\Control\Director;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\HasManyList;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * Edit link for a person without an account: bound to one case file and one
 * external role. The token is shown once when the link is created; only its
 * SHA-256 hash is stored.
 *
 * A link is valid until it is revoked, its optional expiry date has passed, or
 * the case file reaches a final status. What the person may see and change is
 * decided by the field permissions of the role, not by the link.
 *
 * @property string $Name
 * @property string $Email
 * @property string $TokenHash
 * @property string $ExpiresAt
 * @property string $RevokedAt
 * @property string $LastUsedAt
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $RoleID
 * @property int $CreatedByID
 * @method DataObject Case()
 * @method FlowRole Role()
 * @method Member CreatedBy()
 * @method HasManyList<LinkAccess> Accesses()
 */
class AccessLink extends DataObject
{
    public const string URL_SEGMENT = 'flow/link';

    private static string $table_name = 'Flow_AccessLink';

    private static array $db = [
        'Name' => 'Varchar(255)',
        'Email' => 'Varchar(255)',
        'TokenHash' => 'Varchar(64)',
        'ExpiresAt' => 'Date',
        'RevokedAt' => 'Datetime',
        'LastUsedAt' => 'Datetime',
    ];

    private static array $has_one = [
        'Case' => DataObject::class,
        'Role' => FlowRole::class,
        'CreatedBy' => Member::class,
    ];

    private static array $has_many = [
        'Accesses' => LinkAccess::class,
    ];

    private static array $cascade_deletes = [
        'Accesses',
    ];

    private static array $indexes = [
        'TokenHash' => ['type' => 'unique', 'columns' => ['TokenHash']],
    ];

    private static array $summary_fields = [
        'Name',
        'Email',
        'Role.Title',
        'ExpiresAt.Nice',
        'StateLabel',
        'LastUsedAt.Nice',
    ];

    private static string $default_sort = 'Created DESC, ID DESC';

    /**
     * Plain token of a link created in this request (shown once).
     */
    private ?string $plainToken = null;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The link for a token, if it exists (valid or not).
     */
    public static function findByToken(string $token): ?AccessLink
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        return self::get()->find('TokenHash', self::hashToken($token));
    }

    public static function urlForToken(string $token): string
    {
        return Director::absoluteURL(self::URL_SEGMENT . '/' . $token);
    }

    /**
     * Generate a new token (32 random bytes); returns it in plain text.
     */
    public function assignToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->TokenHash = self::hashToken($token);
        $this->plainToken = $token;
        return $token;
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function isRevoked(): bool
    {
        return (bool) $this->RevokedAt;
    }

    public function isExpired(): bool
    {
        return $this->ExpiresAt && $this->ExpiresAt < date('Y-m-d', DBDatetime::now()->getTimestamp());
    }

    /**
     * Whether the case file has reached a final status (the link has lapsed).
     */
    public function isLapsed(): bool
    {
        $case = $this->Case();
        if (!$case->exists()) {
            return true;
        }
        $status = Status::get()->byID((int) $case->getField('FlowStatusID'));
        return $status !== null && (bool) $status->IsFinal;
    }

    public function isUsable(): bool
    {
        $role = $this->Role();
        return !$this->isRevoked()
            && !$this->isExpired()
            && !$this->isLapsed()
            && $role->exists()
            && $role->IsExternal;
    }

    public function revoke(): void
    {
        if (!$this->RevokedAt) {
            $this->RevokedAt = DBDatetime::now()->Rfc2822();
            $this->write();
        }
    }

    public function getStateLabel(): string
    {
        return match (true) {
            $this->isRevoked() => _t(self::class . '.STATE_REVOKED', 'revoked'),
            $this->isExpired() => _t(self::class . '.STATE_EXPIRED', 'expired'),
            $this->isLapsed() => _t(self::class . '.STATE_LAPSED', 'lapsed (final status)'),
            default => _t(self::class . '.STATE_ACTIVE', 'active'),
        };
    }

    /**
     * "Name (Role)" for timelines and choices.
     */
    public function getLabel(): string
    {
        $role = $this->Role();
        return $role->exists() ? "{$this->Name} ({$role->Title})" : (string) $this->Name;
    }

    /**
     * The workflow of the linked case file.
     */
    public function caseWorkflow(): ?Workflow
    {
        $case = $this->Case();
        return $case->exists() && $case->hasExtension(CaseFileExtension::class)
            ? Workflow::forClass($case->ClassName)
            : null;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (!$this->isInDB()) {
            if (!$this->TokenHash) {
                $this->assignToken();
            }
            if (!$this->CreatedByID) {
                $this->CreatedByID = (int) Security::getCurrentUser()?->ID;
            }
        }
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Name) === '') {
            $result->addFieldError('Name', _t(self::class . '.NAME_REQUIRED', 'Please enter a name.'));
        }
        if ($this->Email && !filter_var($this->Email, FILTER_VALIDATE_EMAIL)) {
            $result->addFieldError('Email', _t(self::class . '.EMAIL_INVALID', 'Please enter a valid email address.'));
        }
        $role = $this->Role();
        $workflow = $this->caseWorkflow();
        if (!$role->exists() || !$role->IsExternal || !$workflow || (int) $role->WorkflowID !== (int) $workflow->ID) {
            $result->addFieldError('RoleID', _t(
                self::class . '.ROLE_INVALID',
                'Please choose an external role of this workflow.'
            ));
        }
        return $result;
    }

    /**
     * Visible to whoever may view the case file.
     */
    public function canView($member = null): bool
    {
        $case = $this->Case();
        return $case->exists() && $case->canView($member);
    }

    /**
     * Links are not edited; they are created and revoked.
     */
    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    /**
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return (bool) ($member ?? Security::getCurrentUser());
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Role.Title'] = _t(self::class . '.has_one_Role', 'Role');
        $labels['ExpiresAt.Nice'] = _t(self::class . '.db_ExpiresAt', 'Valid until');
        $labels['StateLabel'] = _t(self::class . '.STATE', 'State');
        $labels['LastUsedAt.Nice'] = _t(self::class . '.db_LastUsedAt', 'Last used');
        return $labels;
    }
}
