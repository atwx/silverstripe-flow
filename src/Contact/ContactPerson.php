<?php

namespace Atwx\Flow\Contact;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;

/**
 * A contact person of any record (polymorphic Owner), e.g. the secretary of a
 * school, with function, e-mail, phone and roles ("Rechnungen", "Besuche").
 * Owners get them with ContactPersonsExtension; contactFor($role) finds the
 * person responsible for something.
 *
 * Roles come from the config roles (code => label); without roles the field
 * is left out.
 *
 * @property ?string $Salutation
 * @property ?string $FirstName
 * @property ?string $Surname
 * @property ?string $Function
 * @property ?string $Email
 * @property ?string $Phone
 * @property ?string $Mobile
 * @property ?string $Roles
 * @property ?string $Notes
 * @property int $Sort
 * @property int $OwnerID
 * @property ?string $OwnerClass
 * @method DataObject Owner()
 */
class ContactPerson extends DataObject
{
    private static string $table_name = 'Flow_ContactPerson';

    private static string $singular_name = 'Contact person';

    private static string $plural_name = 'Contact persons';

    /**
     * Roles a contact person can have, code => label.
     *
     * @var array<string, string>
     */
    private static array $roles = [];

    private static array $db = [
        'Salutation' => 'Varchar(50)',
        'FirstName' => 'Varchar(100)',
        'Surname' => 'Varchar(100)',
        'Function' => 'Varchar(255)',
        'Email' => 'Varchar(255)',
        'Phone' => 'Varchar(100)',
        'Mobile' => 'Varchar(100)',
        // Role codes as a JSON list (the format of CheckboxSetField)
        'Roles' => 'Varchar(255)',
        'Notes' => 'Text',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Owner' => DataObject::class,
    ];

    private static array $summary_fields = [
        'Name',
        'Function',
        'EmailLink',
        'Phone',
        'RolesLabel',
    ];

    private static array $fdk_hidden_fields = ['Owner', 'Sort'];

    private static string $default_sort = '"Sort" ASC, "Surname" ASC, "ID" ASC';

    /**
     * @return array<string, string>
     */
    public static function roleOptions(): array
    {
        return array_map('strval', (array) static::config()->get('roles'));
    }

    public function getName(): string
    {
        return trim($this->FirstName . ' ' . $this->Surname);
    }

    public function getTitle(): string
    {
        $name = trim($this->Salutation . ' ' . $this->getName());
        return $this->Function ? $name . ' (' . $this->Function . ')' : $name;
    }

    /**
     * @return array<int, string>
     */
    public function roleCodes(): array
    {
        return self::decodeRoles($this->getField('Roles'));
    }

    /**
     * Role codes from a list, a JSON list or a comma separated string.
     *
     * @return array<int, string>
     */
    private static function decodeRoles(mixed $value): array
    {
        if (is_string($value) && str_starts_with(trim($value), '[')) {
            $value = json_decode($value, true);
        }
        $codes = is_array($value) ? $value : explode(',', (string) $value);
        return array_values(array_unique(array_filter(
            array_map('trim', array_map('strval', $codes)),
            fn ($code) => $code !== ''
        )));
    }

    public function hasRole(string $code): bool
    {
        return in_array($code, $this->roleCodes(), true);
    }

    public function getRolesLabel(): string
    {
        $options = self::roleOptions();
        return implode(', ', array_map(fn ($code) => $options[$code] ?? $code, $this->roleCodes()));
    }

    public function getEmailLink(): DBHTMLText
    {
        $email = (string) $this->Email;
        return DBHTMLText::create()->setValue($email === '' ? '' : sprintf(
            '<a href="mailto:%1$s">%1$s</a>',
            htmlspecialchars($email)
        ));
    }

    /**
     * Form value of the roles (CheckboxSetField sends an array).
     */
    public function setRoles(mixed $value): static
    {
        $known = array_keys(self::roleOptions());
        $codes = array_values(array_filter(
            self::decodeRoles($value),
            fn ($code) => !$known || in_array($code, $known, true)
        ));
        $this->setField('Roles', $codes ? (string) json_encode($codes) : null);
        return $this;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        // Roles given as data (import, create()) bypass setRoles()
        $this->setRoles($this->getField('Roles'));
    }

    /**
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Salutation'] = _t(self::class . '.Salutation', 'Salutation');
        $labels['FirstName'] = _t(self::class . '.FirstName', 'First name');
        $labels['Surname'] = _t(self::class . '.Surname', 'Surname');
        $labels['Name'] = _t(self::class . '.Name', 'Name');
        $labels['Function'] = _t(self::class . '.Function', 'Function');
        $labels['Email'] = $labels['EmailLink'] = _t(self::class . '.Email', 'E-mail');
        $labels['Phone'] = _t(self::class . '.Phone', 'Phone');
        $labels['Mobile'] = _t(self::class . '.Mobile', 'Mobile');
        $labels['Roles'] = $labels['RolesLabel'] = _t(self::class . '.Roles', 'Responsible for');
        $labels['Notes'] = _t(self::class . '.Notes', 'Notes');
        return $labels;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if ($this->getName() === '' && trim((string) $this->Function) === '') {
            $result->addFieldError('Surname', _t(self::class . '.NAME_REQUIRED', 'Please enter a name or function.'));
        }
        if ($this->Email && !filter_var($this->Email, FILTER_VALIDATE_EMAIL)) {
            $result->addFieldError(
                'Email',
                _t(ContactChannel::class . '.EMAIL_INVALID', 'This is no valid e-mail address.')
            );
        }
        return $result;
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
