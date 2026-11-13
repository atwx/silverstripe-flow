<?php

namespace Atwx\Flow\Contact;

use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

/**
 * Sub-list of records that belong to the parent record through a polymorphic
 * has_one "Owner" (contact channels, contact persons).
 */
abstract class OwnedRecordsSubController extends FrontdeskSubController
{
    /**
     * The parent record, or null when it does not exist.
     */
    public function ownerRecord(): ?DataObject
    {
        $parent = $this->parentController;
        $class = (string) $parent?->getManagedModel();
        if ($class === '' || !class_exists($class)) {
            return null;
        }
        $record = DataObject::get_by_id($class, $this->getParentID());
        return $record && $record->exists() ? $record : null;
    }

    /**
     * @return DataList<DataObject>
     */
    protected function getBaseQuery(): DataList
    {
        $record = $this->ownerRecord();
        $list = DataObject::get((string) $this->getManagedModel());
        return $record
            ? $list->filter(['OwnerClass' => $record->ClassName, 'OwnerID' => (int) $record->ID])
            : $list->filter('ID', 0);
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function beforeWrite(DataObject $item, array $data): void
    {
        parent::beforeWrite($item, $data);
        $record = $this->ownerRecord();
        if (!$item->isInDB() && $record) {
            $item->setField('OwnerClass', $record->ClassName);
            $item->setField('OwnerID', $record->ID);
        }
    }
}
