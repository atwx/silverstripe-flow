<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\TextBlock;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Filter\FilterCollection;
use Atwx\SilverstripeFrontdeskKit\Filter\TextFilter;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;

/**
 * Text blocks maintained in the frontdesk (route it in the project, e.g.
 * 'text-blocks//$Action/$ID': Atwx\Flow\Controller\TextBlockController, and
 * grant its permissions). Also in the CMS under "Flow".
 */
class TextBlockController extends FrontdeskController
{
    private static string $managed_model = TextBlock::class;

    private static string $url_segment = 'text-blocks';

    private static string $title = 'Textbausteine';

    protected function defineColumns(): ColumnCollection
    {
        return ColumnCollection::create()
            ->make('Title', _t(TextBlock::class . '.db_Title', 'Title'))->link('edit/{ID}')->sortable()->end()
            ->make('Code', _t(TextBlock::class . '.db_Code', 'Code'))->sortable()->end();
    }

    /**
     * @return ArrayList<ArrayData>
     */
    protected function defineViewFields(DataObject $record): ArrayList
    {
        $rows = parent::defineViewFields($record);
        if ($record instanceof TextBlock) {
            $usages = $record->usages();
            $rows->push(ArrayData::create([
                'Label' => _t(TextBlock::class . '.USED_IN', 'Used in'),
                'Value' => $usages ? implode(', ', $usages) : _t(self::class . '.UNUSED', 'nowhere'),
                'Type' => 'text',
            ]));
        }
        return $rows;
    }

    /**
     * The code of a block in use is shown read-only.
     */
    protected function formFields(FieldList $fields): FieldList
    {
        $id = $this->currentRecordID();
        $block = $id ? TextBlock::get()->byID($id) : null;
        if ($block && $block->usages() !== []) {
            $fields->replaceField('Code', ReadonlyField::create('Code', $block->fieldLabel('Code'))->setDescription(
                _t(TextBlock::class . '.CODE_LOCKED', 'The code cannot be changed while the block is used.')
            ));
        }
        return $fields;
    }

    /**
     * A block in use cannot be deleted.
     */
    protected function beforeDelete(DataObject $item): void
    {
        if ($item instanceof TextBlock && $item->usages() !== []) {
            $this->httpError(403, _t(TextBlock::class . '.IN_USE', 'The text block is still used.'));
        }
        parent::beforeDelete($item);
    }

    protected function defineFilters(): FilterCollection
    {
        return FilterCollection::create()->add(
            TextFilter::create('Query', _t(self::class . '.SEARCH', 'Search'))
                ->apply(fn ($list, $value) => $list->filterAny([
                    'Title:PartialMatch' => $value,
                    'Code:PartialMatch' => $value,
                    'Body:PartialMatch' => $value,
                ]))
        );
    }
}
