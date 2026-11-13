<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\TextBlock;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Filter\FilterCollection;
use Atwx\SilverstripeFrontdeskKit\Filter\TextFilter;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;

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
