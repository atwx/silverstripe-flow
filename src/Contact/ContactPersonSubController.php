<?php

namespace Atwx\Flow\Contact;

use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\TextareaField;

/**
 * Contact persons of a record (<segment>/contactpersons/<ID>).
 */
class ContactPersonSubController extends OwnedRecordsSubController
{
    private static string $managed_model = ContactPerson::class;

    private static string $url_segment = 'contactpersons';

    private static string $title = 'Ansprechpersonen';

    protected function formFields(FieldList $fields): FieldList
    {
        $fields = parent::formFields($fields);
        $labels = ContactPerson::singleton()->fieldLabels();
        $roles = ContactPerson::roleOptions();
        if ($roles) {
            $fields->replaceField('Roles', CheckboxSetField::create('Roles', $labels['Roles'], $roles));
        } else {
            $fields->removeByName('Roles');
        }
        $fields->replaceField('Notes', TextareaField::create('Notes', $labels['Notes'])->setRows(3));
        return $fields;
    }
}
