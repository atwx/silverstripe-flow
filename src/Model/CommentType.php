<?php

namespace Atwx\Flow\Model;

use Atwx\SilverstripeFrontdeskKit\Catalog\CatalogEntry;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DB;

/**
 * Entry of the catalog of comment types (flow_comment_type) with an icon,
 * shown in front of the type label in the comment stream and on its filter
 * chips. The icon is a Lucide name from the kit's icon set (icons).
 *
 * @property ?string $Icon
 */
class CommentType extends CatalogEntry
{
    private static string $table_name = 'Flow_CommentType';

    private static ?string $catalog = Comment::TYPE_CATALOG;

    private static array $db = [
        'Icon' => 'Varchar(64)',
    ];

    /**
     * Icons offered in the form (Lucide names bundled by the kit).
     *
     * @var array<int, string>
     */
    private static array $icons = [
        'message-square', 'message-circle', 'phone', 'mail', 'school', 'graduation-cap', 'calendar',
        'calendar-days', 'bell', 'square-check', 'alarm-clock', 'pin', 'star', 'paperclip', 'file-text',
        'sticky-note', 'info', 'triangle-alert', 'circle-check', 'users', 'user', 'house', 'building-2',
        'plane', 'euro', 'handshake', 'briefcase', 'flag', 'tag', 'inbox', 'clock',
    ];

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Icon'] = _t(self::class . '.ICON', 'Icon');
        return $labels;
    }

    /**
     * @param array<string, mixed> $_params
     */
    public function scaffoldFormFields($_params = null): FieldList
    {
        $fields = parent::scaffoldFormFields($_params);
        $icons = (array) static::config()->get('icons');
        $current = (string) $this->Icon;
        if ($current !== '' && !in_array($current, $icons, true)) {
            $icons[] = $current;
        }
        $field = DropdownField::create('Icon', $this->fieldLabel('Icon'), array_combine($icons, $icons));
        $fields->replaceField('Icon', $field->setEmptyString('–'));
        return $fields;
    }

    /**
     * Entries of the catalog stored before this class existed become comment
     * types (base class rows get the subclass, the icon stays empty).
     */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();
        $base = DB::get_conn()->quoteString(CatalogEntry::class);
        $catalog = DB::get_conn()->quoteString(Comment::TYPE_CATALOG);
        DB::query(
            'UPDATE "FDK_CatalogEntry" SET "ClassName" = ' . DB::get_conn()->quoteString(self::class)
            . " WHERE \"Catalog\" = {$catalog} AND \"ClassName\" = {$base}"
        );
        if (DB::affected_rows() > 0) {
            DB::alteration_message('Comment types moved to ' . self::class, 'changed');
        }
    }
}
