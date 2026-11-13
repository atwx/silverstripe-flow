<?php

namespace Atwx\Flow\Contact;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * Contact persons of a record:
 *
 *   App\Models\School:
 *     extensions:
 *       - Atwx\Flow\Contact\ContactPersonsExtension
 *   Atwx\Flow\Contact\ContactPerson:
 *     roles:
 *       invoices: 'Rechnungen'
 *       visits: 'Besuche'
 *
 * The persons are deleted with the record. The controller gets the sub-list
 * with ContactPersonsControllerExtension.
 *
 * @method HasManyList<ContactPerson> ContactPersons()
 * @extends Extension<DataObject>
 */
class ContactPersonsExtension extends Extension
{
    private static array $has_many = [
        'ContactPersons' => ContactPerson::class . '.Owner',
    ];

    private static array $cascade_deletes = [
        'ContactPersons',
    ];

    /**
     * The persons with a role.
     *
     * @return DataList<ContactPerson>
     */
    public function contactsFor(string $role): DataList
    {
        return $this->persons()->filter('Roles:PartialMatch', json_encode($role));
    }

    /**
     * The first person with a role, e.g. contactFor('invoices').
     */
    public function contactFor(string $role): ?ContactPerson
    {
        return $this->contactsFor($role)->first();
    }

    /**
     * @return DataList<ContactPerson>
     */
    private function persons(): DataList
    {
        $owner = $this->getOwner();
        /** @var DataList<ContactPerson> $list */
        $list = ContactPerson::get()->filter(['OwnerClass' => $owner->ClassName, 'OwnerID' => (int) $owner->ID]);
        return $list;
    }
}
