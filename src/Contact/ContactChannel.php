<?php

namespace Atwx\Flow\Contact;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * A phone number or e-mail address of any record (polymorphic Owner), with a
 * type ("Mobil", "Dienstlich") and, for e-mail addresses, whether it is used
 * for mailings. Owners get them with ContactChannelsExtension.
 *
 * Types come from phone_types / email_types; owners with the config
 * flow_contact_free_types: true enter the type freely (e.g. schools).
 *
 * @property string $Kind
 * @property ?string $Type
 * @property ?string $Value
 * @property bool $Mailings
 * @property int $Sort
 * @property int $OwnerID
 * @property ?string $OwnerClass
 * @method DataObject Owner()
 */
class ContactChannel extends DataObject
{
    public const string PHONE = 'phone';

    public const string EMAIL = 'email';

    private static string $table_name = 'Flow_ContactChannel';

    private static string $singular_name = 'Contact channel';

    private static string $plural_name = 'Contact channels';

    /**
     * Phone types in display order.
     *
     * @var array<int, string>
     */
    private static array $phone_types = ['Privat', 'Dienstlich', 'Mobil', 'Fax privat', 'Fax dienstlich'];

    /**
     * Phone types that are fax numbers (left out of letters by phonesOfType()).
     *
     * @var array<int, string>
     */
    private static array $fax_types = ['Fax privat', 'Fax dienstlich'];

    /**
     * @var array<int, string>
     */
    private static array $email_types = ['Privat', 'Dienstlich'];

    private static array $db = [
        'Kind' => "Enum('phone,email', 'phone')",
        'Type' => 'Varchar(100)',
        'Value' => 'Varchar(255)',
        'Mailings' => 'Boolean(1)',
        // Position of the type in the type list, set on write
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Owner' => DataObject::class,
    ];

    private static array $defaults = [
        'Mailings' => true,
    ];

    private static array $summary_fields = [
        'KindLabel',
        'Type',
        'ValueLink',
        'MailingsLabel',
    ];

    private static array $fdk_hidden_fields = ['Sort', 'Owner'];

    private static string $default_sort = '"Kind" DESC, "Sort" ASC, "ID" ASC';

    /**
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [
            self::PHONE => _t(self::class . '.PHONE', 'Phone'),
            self::EMAIL => _t(self::class . '.EMAIL', 'E-mail'),
        ];
    }

    /**
     * The fixed types of a kind.
     *
     * @return array<int, string>
     */
    public static function typesOf(string $kind): array
    {
        return array_values((array) static::config()->get($kind === self::EMAIL ? 'email_types' : 'phone_types'));
    }

    public function getTitle(): string
    {
        return trim($this->Type . ': ' . $this->Value, ': ');
    }

    public function getKindLabel(): string
    {
        return self::kindOptions()[$this->Kind] ?? (string) $this->Kind;
    }

    public function getMailingsLabel(): string
    {
        if ($this->Kind !== self::EMAIL) {
            return '';
        }
        return $this->Mailings ? _t(self::class . '.YES', 'Yes') : _t(self::class . '.NO', 'No');
    }

    public function isFax(): bool
    {
        return $this->Kind === self::PHONE && in_array($this->Type, (array) static::config()->get('fax_types'), true);
    }

    /**
     * E-mail as mailto link, phone as tel link.
     */
    public function getValueLink(): DBHTMLText
    {
        $value = (string) $this->Value;
        if ($value === '') {
            return DBHTMLText::create()->setValue('');
        }
        $href = $this->Kind === self::EMAIL
            ? 'mailto:' . $value
            : 'tel:' . preg_replace('/[^0-9+]/', '', $value);
        return DBHTMLText::create()->setValue(sprintf(
            '<a href="%s">%s</a>',
            htmlspecialchars($href),
            htmlspecialchars($value)
        ));
    }

    /**
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Kind'] = $labels['KindLabel'] = _t(self::class . '.Kind', 'Kind');
        $labels['Type'] = _t(self::class . '.Type', 'Type');
        $labels['Value'] = $labels['ValueLink'] = _t(self::class . '.Value', 'Number / address');
        $labels['Mailings'] = $labels['MailingsLabel'] = _t(self::class . '.Mailings', 'For mailings');
        return $labels;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $value = trim((string) $this->Value);
        if ($value === '') {
            $result->addFieldError('Value', _t(self::class . '.VALUE_REQUIRED', 'Please enter a number or address.'));
        } elseif ($this->Kind === self::EMAIL && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $result->addFieldError('Value', _t(self::class . '.EMAIL_INVALID', 'This is no valid e-mail address.'));
        } elseif ($this->Kind === self::PHONE && !preg_match('#^[0-9+()/.\s-]{3,}$#', $value)) {
            $result->addFieldError('Value', _t(
                self::class . '.PHONE_INVALID',
                'A phone number may contain digits, spaces and + ( ) / - . only.'
            ));
        }
        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $this->Value = trim((string) $this->Value);
        if ($this->Kind !== self::EMAIL) {
            $this->Mailings = false;
        }
        $position = array_search($this->Type, self::typesOf((string) $this->Kind), true);
        $this->Sort = $position === false ? 99 : (int) $position;
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        $this->syncOwner();
    }

    protected function onAfterDelete(): void
    {
        parent::onAfterDelete();
        $this->syncOwner();
    }

    private function syncOwner(): void
    {
        $owner = $this->OwnerID ? $this->Owner() : null;
        if ($owner && $owner->exists() && $owner->hasMethod('syncContactFields')) {
            $owner->syncContactFields();
        }
    }

    public function canView($member = null): bool
    {
        $owner = $this->Owner();
        return $owner->exists() ? $owner->canView($member) : parent::canView($member);
    }

    public function canEdit($member = null): bool
    {
        $owner = $this->Owner();
        return $owner->exists() ? $owner->canEdit($member) : parent::canEdit($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canEdit($member);
    }
}
