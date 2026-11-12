<?php

namespace Atwx\Flow\Document;

use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Service\AssignmentService;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\Security\Member;

/**
 * Replaces placeholders like {{Child.FirstName}} in template texts.
 *
 * - {{Field}}, {{Relation.Field}}: database fields and has_one relations of the
 *   case file (dates as dd.mm.yyyy); the last segment may also be a getter
 *   (getTitle() for {{Child.Title}}); no other methods are called
 * - {{Flow.<code>}}: custom field (option label for selects)
 * - {{Assignee.<code>}}: name of the member responsible
 * - {{Status}}, {{Today}}, {{Me.Name}} / {{Me.Email}} (the member creating it)
 * - {{#Relation}} … {{/Relation}}: repeated for every record of a has_many or
 *   many_many relation of the case file; placeholders inside refer to that
 *   record (e.g. {{#Applications}}{{School.Name}}{{/Applications}})
 *
 * - {{Item.Field}}, {{Item.Relation.Field}}: the sub-list entry of a mail per
 *   entry (MailTemplate.ForEach)
 *
 * Unknown placeholders become empty. Values are HTML-escaped unless $html is false.
 */
class Placeholders
{
    use Injectable;

    public function replace(
        string $text,
        DataObject $case,
        ?Member $me,
        bool $html = true,
        ?DataObject $item = null
    ): string {
        $text = (string) preg_replace_callback(
            '/\{\{#([A-Za-z0-9_]+)\}\}(.*?)\{\{\/\1\}\}/s',
            fn (array $match) => $this->repeat($match[1], $match[2], $case, $me, $html),
            $text
        );
        return $this->fill($text, $case, $me, $html, $item);
    }

    /**
     * The block once per record of a to-many relation of the case file.
     */
    private function repeat(string $relation, string $block, DataObject $case, ?Member $me, bool $html): string
    {
        $schema = DataObject::getSchema();
        $isToMany = $schema->hasManyComponent($case::class, $relation)
            || $schema->manyManyComponent($case::class, $relation);
        if (!$isToMany) {
            return '';
        }
        $result = '';
        foreach ($case->$relation() as $item) {
            $result .= $this->fill($block, $item, $me, $html);
        }
        return $result;
    }

    private function fill(string $text, DataObject $case, ?Member $me, bool $html, ?DataObject $item = null): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/',
            function (array $match) use ($case, $me, $html, $item) {
                $value = $this->value($match[1], $case, $me, $item);
                return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5) : $value;
            },
            $text
        );
    }

    public function value(string $path, DataObject $case, ?Member $me, ?DataObject $item = null): string
    {
        $parts = explode('.', $path);
        $head = array_shift($parts);
        switch ($head) {
            case 'Item':
                return $item && $parts ? $this->resolve($item, $parts) : '';
            case 'Today':
                return date('d.m.Y');
            case 'Status':
                $status = $case->hasExtension(CaseFileExtension::class) ? $case->getComponent('FlowStatus') : null;
                return $status && $status->exists() ? (string) $status->getField('Title') : '';
            case 'Me':
                return $me ? $this->resolve($me, $parts ?: ['Name']) : '';
            case 'Flow':
                return $this->customField($case, (string) ($parts[0] ?? ''));
            case 'Assignee':
                return $this->assignee($case, (string) ($parts[0] ?? ''));
        }
        return $this->resolve($case, array_merge([$head], $parts));
    }

    /**
     * @param array<int, string> $parts
     */
    private function resolve(DataObject $record, array $parts): string
    {
        $schema = DataObject::getSchema();
        while (count($parts) > 1) {
            $relation = array_shift($parts);
            if (!$schema->hasOneComponent($record::class, $relation)) {
                return '';
            }
            $record = $record->getComponent($relation);
            if (!$record->exists()) {
                return '';
            }
        }
        $field = (string) $parts[0];
        if ($field === 'Name' && $record instanceof Member) {
            return (string) $record->getName();
        }
        if ($schema->fieldSpec($record::class, $field)) {
            $object = $record->dbObject($field);
            if ($object instanceof DBDate) {
                return $object->getValue() ? (string) $object->Format('dd.MM.yyyy') : '';
            }
            return $object instanceof DBField ? (string) $object->getValue() : '';
        }
        if ($schema->hasOneComponent($record::class, $field)) {
            $component = $record->getComponent($field);
            return $component->exists() ? (string) $component->getTitle() : '';
        }
        $getter = 'get' . $field;
        if (method_exists($record, $getter)) {
            $value = $record->$getter();
            return is_scalar($value) ? (string) $value : '';
        }
        return '';
    }

    private function customField(DataObject $case, string $code): string
    {
        if (!$case->hasExtension(CaseFileExtension::class)) {
            return '';
        }
        $workflow = Workflow::forClass($case::class);
        $custom = $workflow
            ? CustomField::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $code])->first()
            : null;
        if (!$custom) {
            return '';
        }
        $value = $case->flowValue($code);
        $options = $custom->labels();
        if ($custom->Type === 'checkbox') {
            return $value ? _t(self::class . '.YES', 'Yes') : _t(self::class . '.NO', 'No');
        }
        return is_scalar($value) ? (string) ($options[(string) $value] ?? $value) : '';
    }

    private function assignee(DataObject $case, string $code): string
    {
        $workflow = Workflow::forClass($case::class);
        $responsibility = $workflow
            ? Responsibility::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $code])->first()
            : null;
        if (!$responsibility) {
            return '';
        }
        return (string) AssignmentService::singleton()->assignee($case, $responsibility)?->getName();
    }
}
