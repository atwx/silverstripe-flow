<?php

namespace Atwx\Flow\Extension;

use Atwx\Flow\Model\CustomField;
use Atwx\SilverstripeFrontdeskKit\Catalog\CatalogEntry;
use Atwx\SilverstripeFrontdeskKit\Catalog\Catalogs;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;

/**
 * Custom fields of type "catalog" store the entry's code in the case files'
 * FlowData: such an entry is in use (and cannot be deleted) while a case file
 * holds its code.
 *
 * @extends Extension<Catalogs>
 */
class CatalogUsageExtension extends Extension
{
    protected function updateIsInUse(CatalogEntry $entry, bool &$used): void
    {
        if ($used) {
            return;
        }
        $fields = CustomField::get()->filter(['Type' => 'catalog', 'Catalog' => (string) $entry->Catalog]);
        foreach ($fields as $custom) {
            $workflow = $custom->Workflow();
            $class = $workflow->exists() ? (string) $workflow->CaseClass : '';
            if (!$class || !is_a($class, DataObject::class, true)) {
                continue;
            }
            $table = DataObject::getSchema()->tableForField($class, 'FlowData');
            $column = sprintf('JSON_VALUE("%s"."FlowData", ?)', $table);
            $inUse = $class::get()
                ->where([$column . ' = ?' => ['$.' . $custom->Code, (string) $entry->Code]])
                ->exists();
            if ($inUse) {
                $used = true;
                return;
            }
        }
    }
}
