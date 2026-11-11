<?php

namespace Atwx\Flow\Model;

use Atwx\Flow\Security\FlowPermissions;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;

/**
 * Shared behaviour of the workflow configuration records.
 *
 * Every configuration record carries a stable `Code` (used by YAML import/export
 * and by hooks in project code). The code is a lower-case identifier, unique
 * within its scope and read-only in the CMS once the record exists. Access is
 * granted by a single permission, {@see FlowPermissions::CONFIGURE}.
 *
 * @mixin DataObject
 */
trait ConfigRecord
{
    public const string CODE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function canView($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    public function canEdit($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    public function canDelete($member = null): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    /**
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return FlowPermissions::canConfigure($member);
    }

    /**
     * Validate format and uniqueness of the code.
     *
     * @param array<string, mixed> $scope Additional filter for the uniqueness check, e.g. ['WorkflowID' => 3]
     */
    protected function validateCode(ValidationResult $result, array $scope = []): void
    {
        $code = (string) $this->Code;
        if ($code === '') {
            $result->addFieldError('Code', _t(ConfigRecord::class . '.CODE_REQUIRED', 'Please enter a code.'));
            return;
        }
        if (!preg_match(self::CODE_PATTERN, $code)) {
            $result->addFieldError('Code', _t(
                ConfigRecord::class . '.CODE_FORMAT',
                'The code may only contain lower-case letters, digits and underscores and must start with a letter.'
            ));
            return;
        }
        $duplicates = static::get()->filter(['Code' => $code] + $scope)->exclude('ID', (int) $this->ID);
        if ($duplicates->exists()) {
            $result->addFieldError('Code', _t(
                ConfigRecord::class . '.CODE_TAKEN',
                'The code "{code}" is already in use.',
                ['code' => $code]
            ));
        }
    }

    /**
     * Show the code as read-only once the record exists.
     */
    protected function lockCode(FieldList $fields): void
    {
        if ($this->isInDB() && ($field = $fields->dataFieldByName('Code'))) {
            $fields->replaceField('Code', $field->performReadonlyTransformation());
        }
        $fields->dataFieldByName('Code')?->setDescription(_t(
            ConfigRecord::class . '.CODE_DESCRIPTION',
            'Stable identifier for configuration files and program code (e.g. "in_review"). Cannot be changed later.'
        ));
    }
}
