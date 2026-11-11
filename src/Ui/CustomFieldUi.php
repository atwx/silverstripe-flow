<?php

namespace Atwx\Flow\Ui;

use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\Workflow;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\ArrayData;
use SilverStripe\ORM\DataObject;

/**
 * Form fields and view rows of the custom fields of a case file, used by the
 * internal and the external (link) controller. Access levels are applied by
 * the FieldLayout like for any other field.
 */
class CustomFieldUi
{
    /**
     * Custom fields of the workflow of a class.
     *
     * @return array<int, CustomField>
     */
    public static function fieldsForClass(string $class): array
    {
        $workflow = Workflow::forClass($class);
        return $workflow ? CustomField::get()->filter('WorkflowID', $workflow->ID)->toArray() : [];
    }

    /**
     * Add the custom fields with their current values.
     *
     * @param DataObject $record The case file, or a new (unsaved) record
     */
    public static function addFormFields(FieldList $fields, DataObject $record): FieldList
    {
        $data = self::data($record);
        foreach (self::fieldsForClass($record->ClassName) as $custom) {
            $fields->push($custom->formField($data[$custom->Code] ?? null));
        }
        return $fields;
    }

    /**
     * View rows keyed by form field name.
     *
     * @return array<string, ArrayData>
     */
    public static function viewRows(DataObject $record): array
    {
        $data = self::data($record);
        $rows = [];
        foreach (self::fieldsForClass($record->ClassName) as $custom) {
            $rows[$custom->formFieldName()] = ArrayData::create([
                'Label' => $custom->Title,
                'Value' => $custom->display($data[$custom->Code] ?? null),
                'Type' => 'text',
            ]);
        }
        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private static function data(DataObject $record): array
    {
        /** @var DataObject&CaseFileExtension $record */
        return $record->hasMethod('flowData') ? $record->flowData() : [];
    }
}
