<?php

namespace Atwx\Flow\Contact;

use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * Phone numbers and e-mail addresses of a record:
 *
 *   App\Models\Family:
 *     extensions:
 *       - Atwx\Flow\Contact\ContactChannelsExtension
 *     flow_contact_sync:          # optional: keep the first address/number in
 *       email: 'Email'            # these fields, for templates and exports
 *       phone: 'Phone'            # that need one value
 *     flow_contact_free_types: true   # optional: type entered freely
 *
 * The channels are deleted with the record. The controller gets the sub-list
 * with ContactChannelsControllerExtension.
 *
 * @method HasManyList<ContactChannel> ContactChannels()
 * @extends Extension<DataObject>
 */
class ContactChannelsExtension extends Extension
{
    private static array $has_many = [
        'ContactChannels' => ContactChannel::class . '.Owner',
    ];

    private static array $cascade_deletes = [
        'ContactChannels',
    ];

    /**
     * @return DataList<ContactChannel>
     */
    public function emailChannels(bool $mailingsOnly = false): DataList
    {
        $list = $this->channels()->filter('Kind', ContactChannel::EMAIL);
        return $mailingsOnly ? $list->filter('Mailings', true) : $list;
    }

    /**
     * @param array<int, string>|null $types only these types, null for all
     * @return DataList<ContactChannel>
     */
    public function phoneChannels(?array $types = null, bool $withFax = true): DataList
    {
        $list = $this->channels()->filter('Kind', ContactChannel::PHONE);
        if ($types !== null) {
            $list = $list->filter('Type', $types ?: ['']);
        }
        if (!$withFax) {
            $fax = (array) ContactChannel::config()->get('fax_types');
            if ($fax) {
                $list = $list->exclude('Type', $fax);
            }
        }
        return $list;
    }

    /**
     * E-mail addresses for mailings, separated by "; " (exports, letters).
     */
    public function getMailingEmails(): string
    {
        return implode('; ', $this->emailChannels(true)->column('Value'));
    }

    /**
     * Phone numbers of the given types, separated by "; "; without types all
     * numbers except fax.
     *
     * @param array<int, string>|null $types
     */
    public function phonesOfType(?array $types = null): string
    {
        return implode('; ', $this->phoneChannels($types, $types !== null)->column('Value'));
    }

    public function firstEmail(): string
    {
        return (string) $this->emailChannels()->first()?->Value;
    }

    public function firstPhone(): string
    {
        return (string) $this->phoneChannels(null, false)->first()?->Value;
    }

    /**
     * Copies the first address and number into the fields of flow_contact_sync.
     */
    public function syncContactFields(): void
    {
        $owner = $this->getOwner();
        $sync = (array) $owner->config()->get('flow_contact_sync');
        $changed = false;
        foreach (['email' => $this->firstEmail(), 'phone' => $this->firstPhone()] as $kind => $value) {
            $field = (string) ($sync[$kind] ?? '');
            if ($field !== '' && $owner->hasDatabaseField($field) && (string) $owner->getField($field) !== $value) {
                $owner->setField($field, $value);
                $changed = true;
            }
        }
        if ($changed && $owner->isInDB()) {
            $owner->write();
        }
    }

    /**
     * @return DataList<ContactChannel>
     */
    private function channels(): DataList
    {
        $owner = $this->getOwner();
        /** @var DataList<ContactChannel> $list */
        $list = ContactChannel::get()->filter(['OwnerClass' => $owner->ClassName, 'OwnerID' => (int) $owner->ID]);
        return $list;
    }
}
