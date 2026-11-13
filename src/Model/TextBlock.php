<?php

namespace Atwx\Flow\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

/**
 * A text block ("Textbaustein"): HTML maintained by the staff and inserted into
 * document and mail templates with {{Block.<Code>}}. The block may contain
 * placeholders itself; its HTML is inserted as it is (not escaped).
 *
 * @property ?string $Code
 * @property ?string $Title
 * @property ?string $Body
 * @property ?string $Category
 */
class TextBlock extends DataObject
{
    private static string $table_name = 'Flow_TextBlock';

    private static array $db = [
        'Code' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'Body' => 'HTMLText',
        'Category' => 'Varchar(100)',
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static array $summary_fields = [
        'Title',
        'Code',
        'Category',
    ];

    private static string $default_sort = 'Title ASC';

    /**
     * Blocks offered in the document dialog: all ('*') or those of a category.
     *
     * @return DataList<TextBlock>
     */
    public static function choosable(string $category): DataList
    {
        $list = DataList::create(TextBlock::class);
        return $category === '*' ? $list : $list->filter('Category', $category);
    }

    public static function byCode(string $code): ?self
    {
        return $code === '' ? null : self::get()->find('Code', $code);
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Code'] = _t(self::class . '.db_Code', 'Code');
        $labels['Title'] = _t(self::class . '.db_Title', 'Title');
        $labels['Body'] = _t(self::class . '.db_Body', 'Text');
        $labels['Category'] = _t(self::class . '.db_Category', 'Category');
        return $labels;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('Body', TextareaField::create('Body', $this->fieldLabel('Body'))
            ->setRows(12)
            ->setDescription(_t(
                self::class . '.BODY_DESCRIPTION',
                'HTML; placeholders like in templates. Inserted with {{Block.<code>}}.'
            )));
        $fields->dataFieldByName('Category')?->setDescription(_t(
            self::class . '.CATEGORY_DESCRIPTION',
            'Optional; templates offer the blocks of a category for choosing (e.g. Tests).'
        ));
        $fields->dataFieldByName('Code')?->setDescription(_t(
            self::class . '.CODE_DESCRIPTION',
            'Letters, digits and underscores, e.g. Travel_Intro.'
        ));
        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $code = (string) $this->Code;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $code)) {
            $result->addFieldError('Code', _t(
                self::class . '.CODE_INVALID',
                'Please use only letters, digits and underscores.'
            ));
        } elseif (self::get()->filter('Code', $code)->exclude('ID', (int) $this->ID)->exists()) {
            $result->addFieldError('Code', _t(self::class . '.CODE_TAKEN', 'This code is already in use.'));
        }
        if (!$this->Title) {
            $result->addFieldError('Title', _t(ConfigRecord::class . '.TITLE_REQUIRED', 'Please enter a title.'));
        }
        return $result;
    }
}
