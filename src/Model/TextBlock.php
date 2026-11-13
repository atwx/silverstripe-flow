<?php

namespace Atwx\Flow\Model;

use Atwx\Flow\Intake\IntakeService;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\LiteralField;
use SilverStripe\Forms\ReadonlyField;
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

    /**
     * Where the block is used: document and mail templates, other text blocks
     * ({{Block.<code>}}) and inbox form types (notify.block, receipt.block).
     * Hook updateUsages(array &$usages).
     *
     * @return array<int, string> Titles, e.g. "Schreiben: Einladung (Veranstaltung)"
     */
    public function usages(?string $code = null): array
    {
        $code ??= (string) $this->Code;
        if ($code === '') {
            return [];
        }
        $patterns = ['{{Block.' . $code . '}}', '{{Block.' . $code . '|'];
        $usages = [];
        $sources = [
            [DocumentTemplate::class, ['Body'], _t(self::class . '.USED_DOCUMENT', 'Document')],
            [MailTemplate::class, ['Subject', 'Body'], _t(self::class . '.USED_MAIL', 'Mail')],
        ];
        foreach ($sources as [$class, $fields, $kind]) {
            $any = [];
            foreach ($fields as $field) {
                $any[$field . ':PartialMatch:case'] = $patterns;
            }
            foreach (DataList::create($class)->filterAny($any) as $template) {
                $workflow = $template->getComponent('Workflow');
                $usages[] = $kind . ': ' . $template->Title
                    . ($workflow->exists() ? ' (' . $workflow->Title . ')' : '');
            }
        }
        $blocks = DataList::create(self::class)
            ->filterAny(['Body:PartialMatch:case' => $patterns])
            ->exclude('ID', (int) $this->ID);
        foreach ($blocks as $block) {
            $usages[] = _t(self::class . '.USED_BLOCK', 'Text block') . ': ' . $block->Title;
        }
        foreach (IntakeService::singleton()->types() as $type) {
            foreach (['notify', 'receipt'] as $option) {
                $config = $type->option($option);
                if (is_array($config) && (string) ($config['block'] ?? '') === $code) {
                    $usages[] = _t(self::class . '.USED_INTAKE', 'Form') . ': '
                        . $type->getTitle() . ' (' . $option . ')';
                }
            }
        }
        $this->extend('updateUsages', $usages);
        return $usages;
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
        $usages = $this->isInDB() ? $this->usages() : [];
        if ($usages !== []) {
            $fields->replaceField('Code', ReadonlyField::create('Code', $this->fieldLabel('Code'))->setDescription(
                _t(self::class . '.CODE_LOCKED', 'The code cannot be changed while the block is used.')
            ));
            $fields->addFieldToTab('Root.Main', LiteralField::create('Usages', sprintf(
                '<p><strong>%s</strong></p><ul><li>%s</li></ul>',
                htmlspecialchars(_t(self::class . '.USED_IN', 'Used in')),
                implode('</li><li>', array_map('htmlspecialchars', $usages))
            )));
        }
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
        $before = $this->isInDB() && $this->isChanged('Code')
            ? (string) ($this->getChangedFields(['Code'])['Code']['before'] ?? '')
            : '';
        if ($before !== '' && $before !== $code && $this->usages($before) !== []) {
            $result->addFieldError('Code', _t(
                self::class . '.CODE_IN_USE',
                'The code cannot be changed while the block is used: {usages}',
                ['usages' => implode(', ', $this->usages($before))]
            ));
        }
        if (!$this->Title) {
            $result->addFieldError('Title', _t(ConfigRecord::class . '.TITLE_REQUIRED', 'Please enter a title.'));
        }
        return $result;
    }

    /**
     * A block that is still used cannot be deleted.
     */
    public function canDelete($member = null): bool
    {
        return parent::canDelete($member) && $this->usages() === [];
    }
}
