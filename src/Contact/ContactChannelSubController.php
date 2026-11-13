<?php

namespace Atwx\Flow\Contact;

use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextField;

/**
 * Phone numbers and e-mail addresses of a record (<segment>/contactchannels/<ID>).
 */
class ContactChannelSubController extends OwnedRecordsSubController
{
    private static string $managed_model = ContactChannel::class;

    private static string $url_segment = 'contactchannels';

    private static string $title = 'Kontaktwege';

    protected function defineColumns(): ColumnCollection
    {
        $columns = parent::defineColumns();
        // mailto/tel link
        $columns->byName('ValueLink')?->type('html');
        return $columns;
    }

    protected function formFields(FieldList $fields): FieldList
    {
        $fields = parent::formFields($fields);
        $labels = ContactChannel::singleton()->fieldLabels();
        $fields->replaceField('Kind', DropdownField::create('Kind', $labels['Kind'], ContactChannel::kindOptions()));
        $owner = $this->ownerRecord();
        if (!$owner || !$owner->config()->get('flow_contact_free_types')) {
            $types = array_values(array_unique(array_merge(
                ContactChannel::typesOf(ContactChannel::PHONE),
                ContactChannel::typesOf(ContactChannel::EMAIL)
            )));
            $fields->replaceField('Type', DropdownField::create('Type', $labels['Type'], array_combine($types, $types))
                ->setEmptyString('–'));
        } else {
            $fields->replaceField('Type', TextField::create('Type', $labels['Type']));
        }
        $fields->replaceField('Mailings', CheckboxField::create('Mailings', $labels['Mailings']));
        foreach (['Kind', 'Type', 'Value', 'Mailings'] as $name) {
            $field = $fields->dataFieldByName($name);
            if ($field) {
                $fields->removeByName($name);
                $fields->push($field);
            }
        }
        return $fields;
    }
}
