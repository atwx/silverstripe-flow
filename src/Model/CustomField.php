<?php

namespace Atwx\Flow\Model;

use Atwx\SilverstripeFrontdeskKit\Catalog\Catalogs;
use Atwx\SilverstripeFrontdeskKit\Forms\SearchableDropdownField;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBField;

/**
 * An additional field of the case files of a workflow, maintained in the CMS.
 *
 * Values are stored as JSON on the case file (FlowData), keyed by the code,
 * which cannot change once the field exists. The field belongs to a field
 * group and has that group's permissions. In forms it is named "Flow_<code>".
 *
 * Custom fields are for information that is shown, filtered and exported; as
 * soon as logic depends on a value, it should become a real field.
 *
 * @property string $Code
 * @property string $Title
 * @property string $Type
 * @property string $Options JSON object value => label (select)
 * @property string $Catalog Catalog key of the frontdesk-kit catalogs (catalog)
 * @property bool $ShowInList
 * @property int $Sort
 * @property int $WorkflowID
 * @property int $FieldGroupID
 * @method Workflow Workflow()
 * @method FieldGroup FieldGroup()
 */
class CustomField extends DataObject
{
    use ConfigRecord;

    public const string PREFIX = 'Flow_';

    public const array TYPES = ['text', 'textarea', 'date', 'checkbox', 'select', 'catalog'];

    private static string $table_name = 'Flow_CustomField';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Type' => "Enum('text,textarea,date,checkbox,select,catalog', 'text')",
        'Options' => 'Text',
        'Catalog' => 'Varchar(64)',
        'ShowInList' => 'Boolean',
        'Sort' => 'Int',
    ];

    private static array $has_one = [
        'Workflow' => Workflow::class,
        'FieldGroup' => FieldGroup::class,
    ];

    private static array $indexes = [
        'WorkflowCode' => ['type' => 'unique', 'columns' => ['WorkflowID', 'Code']],
    ];

    private static array $summary_fields = [
        'Sort',
        'Title',
        'Code',
        'TypeTitle',
        'FieldGroup.Title',
        'ShowInList.Nice',
    ];

    private static string $default_sort = 'Sort ASC, ID ASC';

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            'text' => _t(self::class . '.TYPE_TEXT', 'Text'),
            'textarea' => _t(self::class . '.TYPE_TEXTAREA', 'Multi-line text'),
            'date' => _t(self::class . '.TYPE_DATE', 'Date'),
            'checkbox' => _t(self::class . '.TYPE_CHECKBOX', 'Yes/No'),
            'select' => _t(self::class . '.TYPE_SELECT', 'Choice'),
            'catalog' => _t(self::class . '.TYPE_CATALOG', 'Choice from a catalog'),
        ];
    }

    /**
     * Name of the form field.
     */
    public function formFieldName(): string
    {
        return self::PREFIX . $this->Code;
    }

    /**
     * @return array<string, string> value => label
     */
    public function optionMap(): array
    {
        $options = json_decode((string) $this->Options, true);
        return is_array($options) ? array_map('strval', $options) : [];
    }

    /**
     * @param array<string, string> $options
     */
    public function setOptionMap(array $options): static
    {
        $this->Options = $options === [] ? '' : (string) json_encode($options, JSON_UNESCAPED_UNICODE);
        return $this;
    }

    /**
     * Labels of all values (for display, filters and exports): the options of a
     * choice, all entries of a catalog (inactive ones too) by code.
     *
     * @return array<string, string> value => label
     */
    public function labels(): array
    {
        if ($this->Type !== 'catalog') {
            return $this->optionMap();
        }
        $labels = [];
        foreach (Catalogs::singleton()->all((string) $this->Catalog) as $entry) {
            $labels[(string) $entry->Code] = $entry->getLabel();
        }
        return $labels;
    }

    /**
     * Values that can be chosen: the options of a choice; the active entries of a
     * catalog plus the current value (an inactive entry stays on the record).
     *
     * @return array<string, string> value => label
     */
    public function choices(mixed $current = null): array
    {
        if ($this->Type !== 'catalog') {
            return $this->optionMap();
        }
        $choices = [];
        foreach (Catalogs::singleton()->all((string) $this->Catalog) as $entry) {
            if ($entry->Active || (string) $entry->Code === (string) $current) {
                $choices[(string) $entry->Code] = $entry->getLabel();
            }
        }
        return $choices;
    }

    public function getTypeTitle(): string
    {
        return self::typeOptions()[$this->Type] ?? (string) $this->Type;
    }

    /**
     * Form field for the given stored value.
     */
    public function formField(mixed $value): FormField
    {
        $name = $this->formFieldName();
        $title = (string) $this->Title;
        $field = match ($this->Type) {
            'textarea' => TextareaField::create($name, $title),
            'date' => DateField::create($name, $title),
            'checkbox' => CheckboxField::create($name, $title),
            'select' => DropdownField::create($name, $title, $this->optionMap())
                ->setEmptyString(_t(self::class . '.CHOOSE', '– choose –')),
            'catalog' => SearchableDropdownField::create($name, $title, $this->choices($value))
                ->setHasEmptyDefault(true)
                ->setEmptyString(_t(self::class . '.CHOOSE', '– choose –')),
            default => TextField::create($name, $title),
        };
        $field->setValue($value);
        return $field;
    }

    /**
     * Normalise a submitted value for storage.
     */
    public function normalise(mixed $value): string|bool|null
    {
        return match ($this->Type) {
            'checkbox' => (bool) $value,
            'select' => array_key_exists((string) $value, $this->optionMap()) ? (string) $value : null,
            'catalog' => array_key_exists((string) $value, $this->labels()) ? (string) $value : null,
            'date' => $value ? (string) $value : null,
            default => trim((string) $value) === '' ? null : (string) $value,
        };
    }

    /**
     * Value for display and export.
     */
    public function display(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        return match ($this->Type) {
            'checkbox' => $value ? _t(self::class . '.YES', 'Yes') : _t(self::class . '.NO', 'No'),
            'select', 'catalog' => $this->labels()[(string) $value] ?? (string) $value,
            'date' => self::niceDate((string) $value),
            default => (string) $value,
        };
    }

    private static function niceDate(string $value): string
    {
        $date = DBField::create_field(DBDate::class, $value);
        return $date instanceof DBDate ? (string) $date->Nice() : $value;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->removeByName(['WorkflowID', 'Options']);
        $this->lockCode($fields);
        $fields->replaceField('Type', DropdownField::create('Type', $this->fieldLabel('Type'), self::typeOptions()));
        $fields->replaceField('FieldGroupID', DropdownField::create(
            'FieldGroupID',
            $this->fieldLabel('FieldGroup'),
            FieldGroup::get()->filter('WorkflowID', (int) $this->WorkflowID)->map('ID', 'Title')
        )->setEmptyString(_t(Workflow::class . '.CHOOSE_STATUS', '– choose –')));
        $fields->replaceField('Catalog', DropdownField::create(
            'Catalog',
            $this->fieldLabel('Catalog'),
            Catalogs::singleton()->titles()
        )->setEmptyString(_t(Workflow::class . '.CHOOSE_STATUS', '– choose –'))
            ->setDescription(_t(
                self::class . '.CATALOG_DESCRIPTION',
                'For "Choice from a catalog". The code of the entry is stored.'
            )));
        $fields->addFieldToTab('Root.Main', TextareaField::create(
            'OptionLines',
            _t(self::class . '.OPTIONS', 'Options')
        )->setDescription(_t(
            self::class . '.OPTIONS_DESCRIPTION',
            'For "Choice": one option per line as "value: Label", e.g. "beginners: Beginners". The value is stored.'
        )));
        return $fields;
    }

    /**
     * Options as lines "value: Label" for the CMS form.
     */
    public function getOptionLines(): string
    {
        $lines = [];
        foreach ($this->optionMap() as $value => $label) {
            $lines[] = "{$value}: {$label}";
        }
        return implode("\n", $lines);
    }

    public function setOptionLines(?string $text): void
    {
        $options = [];
        foreach (preg_split('/\R/', (string) $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$value, $label] = array_pad(array_map('trim', explode(':', $line, 2)), 2, null);
            $options[(string) $value] = $label ?? (string) $value;
        }
        $this->setOptionMap($options);
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['TypeTitle'] = _t(self::class . '.db_Type', 'Type');
        $labels['FieldGroup.Title'] = _t(self::class . '.has_one_FieldGroup', 'Field group');
        $labels['ShowInList.Nice'] = _t(self::class . '.db_ShowInList', 'Column in list');
        return $labels;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->WorkflowID) {
            $result->addError(_t(ConfigRecord::class . '.WORKFLOW_REQUIRED', 'The record must belong to a workflow.'));
            return $result;
        }
        $this->validateCode($result, ['WorkflowID' => (int) $this->WorkflowID]);
        if (!$this->Title) {
            $result->addFieldError('Title', _t(ConfigRecord::class . '.TITLE_REQUIRED', 'Please enter a title.'));
        }
        if (!in_array($this->Type, self::TYPES, true)) {
            $result->addFieldError('Type', _t(self::class . '.TYPE_REQUIRED', 'Please choose a type.'));
        }
        $group = $this->FieldGroup();
        if (!$group->exists() || (int) $group->WorkflowID !== (int) $this->WorkflowID) {
            $result->addFieldError('FieldGroupID', _t(
                ConfigRecord::class . '.FOREIGN_RECORD',
                'Please choose a record of the same workflow.'
            ));
        }
        if ($this->Type === 'catalog' && !Catalogs::singleton()->exists((string) $this->Catalog)) {
            $result->addFieldError('Catalog', _t(self::class . '.CATALOG_REQUIRED', 'Please choose a catalog.'));
        }
        if ($this->Type === 'select' && $this->optionMap() === []) {
            $result->addFieldError('OptionLines', _t(self::class . '.OPTIONS_REQUIRED', 'Please enter the options.'));
        }
        return $result;
    }
}
