<?php

namespace Atwx\Flow\Api;

use Atwx\Flow\Security\FlowPermissions;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * A system that calls the API (a website form, friendlydesk, a portal), with
 * its own key and scopes. Only a hash of the key is stored; the key itself is
 * shown once, after creating or renewing it.
 *
 * Scopes, one per line: an exact scope ("intake:application"), a prefix with
 * "*" ("intake:*") or "*" for everything.
 *
 * @property ?string $Title
 * @property ?string $Scopes
 * @property bool $Active
 * @property int $RateLimit
 * @property ?string $KeyPrefix
 * @property ?string $KeyHash
 * @property ?string $LastUsed
 * @property ?string $Notes
 * @method HasManyList<ApiLog> Logs()
 */
class ApiClient extends DataObject
{
    private static string $table_name = 'Flow_ApiClient';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Scopes' => 'Text',
        'Active' => 'Boolean',
        'RateLimit' => 'Int',
        'KeyPrefix' => 'Varchar(16)',
        'KeyHash' => 'Varchar(64)',
        'LastUsed' => 'Datetime',
        'Notes' => 'Text',
    ];

    private static array $has_many = [
        'Logs' => ApiLog::class,
    ];

    private static array $indexes = [
        'KeyPrefix' => ['type' => 'unique', 'columns' => ['KeyPrefix']],
    ];

    private static array $defaults = [
        'Active' => true,
    ];

    private static array $summary_fields = [
        'Title',
        'Scopes',
        'Active.Nice',
        'LastUsed',
    ];

    private static string $default_sort = 'Title ASC';

    /**
     * Requests per minute when the client sets no own limit (0 = unlimited).
     */
    private static int $default_rate_limit = 60;

    private const string SESSION_KEY = 'FlowApiClientKey';

    /**
     * Set by write(): the new plain key, readable once via newKey().
     */
    private ?string $plainKey = null;

    private bool $renewKey = false;

    /**
     * Creates and writes a client; returns it with the plain key.
     *
     * @param array<int, string> $scopes
     * @return array{0: ApiClient, 1: string}
     */
    public static function createWithKey(string $title, array $scopes, int $rateLimit = 0): array
    {
        $client = self::create(['Title' => $title, 'Scopes' => implode("\n", $scopes), 'RateLimit' => $rateLimit]);
        $client->write();
        return [$client, (string) $client->newKey()];
    }

    /**
     * The active client with this key, or null.
     */
    public static function byKey(string $key): ?self
    {
        if (!preg_match('/^fk_([a-f0-9]{12})_([a-f0-9]{40})$/', $key, $match)) {
            return null;
        }
        $client = self::get()->filter(['KeyPrefix' => $match[1], 'Active' => true])->first();
        if (!$client instanceof self || !hash_equals((string) $client->KeyHash, hash('sha256', $key))) {
            return null;
        }
        return $client;
    }

    /**
     * Gives the client a new key (written with the next write()).
     */
    public function renewKey(): static
    {
        $this->renewKey = true;
        return $this;
    }

    /**
     * The plain key after creating or renewing it, once.
     */
    public function newKey(): ?string
    {
        $key = $this->plainKey;
        $this->plainKey = null;
        return $key;
    }

    /**
     * @return array<int, string>
     */
    public function scopeList(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', (string) $this->Scopes) ?: [])));
    }

    public function hasScope(string $scope): bool
    {
        return self::scopesAllow($this->scopeList(), $scope);
    }

    /**
     * @param array<int, string> $granted
     */
    public static function scopesAllow(array $granted, string $scope): bool
    {
        foreach ($granted as $pattern) {
            if ($pattern === '*' || $pattern === $scope) {
                return true;
            }
            if (str_ends_with($pattern, '*') && str_starts_with($scope, substr($pattern, 0, -1))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Requests per minute (0 = unlimited). RateLimit: 0 = default_rate_limit,
     * -1 = unlimited.
     */
    public function rateLimit(): int
    {
        if ($this->RateLimit < 0) {
            return 0;
        }
        return $this->RateLimit > 0 ? $this->RateLimit : (int) static::config()->get('default_rate_limit');
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['KeyHash', 'KeyPrefix', 'LastUsed', 'Logs']);
        $fields->replaceField('Scopes', TextareaField::create('Scopes', $this->fieldLabel('Scopes'))
            ->setDescription(_t(
                self::class . '.SCOPES_HELP',
                'One per line, e.g. intake:application, intake:* or *'
            )));
        $fields->dataFieldByName('RateLimit')?->setDescription(_t(
            self::class . '.RATE_HELP',
            'Requests per minute; 0 = default ({limit}), -1 = unlimited',
            ['limit' => static::config()->get('default_rate_limit')]
        ));
        $shown = $this->sessionKey();
        if ($shown !== null) {
            $fields->addFieldToTab('Root.Main', LiteralField::create('NewKey', sprintf(
                '<div class="alert alert-warning"><p>%s</p><p><code>%s</code></p></div>',
                htmlspecialchars(_t(self::class . '.KEY_ONCE', 'The key is shown only now. Copy it:')),
                htmlspecialchars($shown)
            )), 'Title');
        }
        if ($this->isInDB()) {
            $fields->addFieldsToTab('Root.Main', [
                ReadonlyField::create(
                    'KeyPrefixShown',
                    $this->fieldLabel('KeyPrefix'),
                    'fk_' . $this->KeyPrefix . '_…'
                ),
                ReadonlyField::create('LastUsedShown', $this->fieldLabel('LastUsed'), $this->LastUsed),
                CheckboxField::create(
                    'RenewKey',
                    _t(self::class . '.RENEW', 'Create a new key (the old one stops working)')
                ),
            ]);
        }
        return $fields;
    }

    /**
     * Form field of getCMSFields().
     */
    public function setRenewKey(mixed $value): void
    {
        if ($value) {
            $this->renewKey = true;
        }
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (!$this->KeyHash || $this->renewKey) {
            $prefix = bin2hex(random_bytes(6));
            $key = 'fk_' . $prefix . '_' . bin2hex(random_bytes(20));
            $this->KeyPrefix = $prefix;
            $this->KeyHash = hash('sha256', $key);
            $this->plainKey = $key;
            $this->renewKey = false;
        }
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        $request = Controller::curr()?->getRequest();
        if ($this->plainKey !== null && $request) {
            if ($request->hasSession()) {
                $request->getSession()->set(self::SESSION_KEY . '.' . $this->ID, $this->plainKey);
            }
        }
    }

    /**
     * The key stored for showing it once in the CMS, removed when read.
     */
    private function sessionKey(): ?string
    {
        $request = Controller::curr()?->getRequest();
        if (!$this->isInDB() || !$request || !$request->hasSession()) {
            return null;
        }
        $session = $request->getSession();
        $key = $session->get(self::SESSION_KEY . '.' . $this->ID);
        $session->clear(self::SESSION_KEY . '.' . $this->ID);
        return is_string($key) ? $key : null;
    }

    public function canView($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    public function canEdit($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    public function canDelete($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
    }
}
