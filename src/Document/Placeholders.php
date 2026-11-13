<?php

namespace Atwx\Flow\Document;

use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Controller\FollowUpController;
use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\TextBlock;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Service\AssignmentService;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
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
 *   Options: sort=Field (sort=-Field descending, relation paths allowed),
 *   filter=Field (only records where it is not empty) or filter=Field:value
 *   (e.g. {{#Applications sort=Visit filter=Visit}}…{{/Applications}})
 * - {{#Selected}} … {{/Selected}}: the entries chosen in the document dialog
 * - {{#SelectedBlocks}}{{Title}} {{Body}}{{/SelectedBlocks}}: the text blocks
 *   chosen in the document dialog; {{Body}} is the block as HTML with its
 *   placeholders filled for the case file
 * - {{Block.<Code>}}: a text block (TextBlock), inserted as HTML; it may
 *   contain placeholders
 * - {{FreeText}}: the free text of the document dialog (line breaks kept)
 * - {{Signer.Name}}, {{Signer.Email}}: the member signing the document
 *
 * - {{Item.Field}}, {{Item.Relation.Field}}: the sub-list entry of a mail per
 *   entry (MailTemplate.ForEach)
 * - {{Link}}: absolute URL of the case file's view (through the routed case
 *   controller; empty without one)
 * - {{Field|-}}: a default after the bar, used when the value is empty
 *
 * Date-and-time fields are formatted dd.mm.yyyy hh:mm.
 * Unknown placeholders become empty. Values are HTML-escaped unless $html is false.
 *
 * @phpstan-type DocumentContext array{
 *     FreeText?: string,
 *     Selected?: iterable<DataObject>,
 *     Signer?: ?Member,
 *     SelectedBlocks?: iterable<TextBlock>
 * }
 */
class Placeholders
{
    use Injectable;

    /**
     * @param DocumentContext $context
     *        inputs of the document dialog
     */
    public function replace(
        string $text,
        DataObject $case,
        ?Member $me,
        bool $html = true,
        ?DataObject $item = null,
        array $context = []
    ): string {
        $text = $this->insertBlocks($text, $html);
        $text = (string) preg_replace_callback(
            '/\{\{#([A-Za-z0-9_]+)((?:\s+[a-z]+=[^\s}]+)*)\s*\}\}(.*?)\{\{\/\1\}\}/s',
            fn (array $match) => $this->repeat($match[1], $match[2], $match[3], $case, $me, $html, $context),
            $text
        );
        return $this->fill($text, $case, $me, $html, $item, $context);
    }

    /**
     * Inserts text blocks (up to three levels of blocks within blocks).
     */
    private function insertBlocks(string $text, bool $html): string
    {
        for ($level = 0; $level < 3 && str_contains($text, '{{Block.'); $level++) {
            $text = (string) preg_replace_callback(
                '/\{\{\s*Block\.([A-Za-z0-9_]+)\s*\}\}/',
                function (array $match) use ($html) {
                    $body = (string) TextBlock::byCode($match[1])?->Body;
                    return $html ? $body : trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5));
                },
                $text
            );
        }
        return $text;
    }

    /**
     * The block once per record of a to-many relation of the case file (or of
     * the chosen entries), sorted and filtered by the options.
     *
     * @param DocumentContext $context
     */
    private function repeat(
        string $relation,
        string $options,
        string $block,
        DataObject $case,
        ?Member $me,
        bool $html,
        array $context
    ): string {
        if ($relation === 'SelectedBlocks') {
            return $this->repeatBlocks($block, $case, $me, $html, $context);
        }
        if ($relation === 'Selected') {
            $list = $context['Selected'] ?? [];
        } else {
            $schema = DataObject::getSchema();
            $isToMany = $schema->hasManyComponent($case::class, $relation)
                || $schema->manyManyComponent($case::class, $relation);
            if (!$isToMany) {
                return '';
            }
            $list = $case->$relation();
        }
        preg_match_all('/([a-z]+)=([^\s}]+)/', $options, $pairs, PREG_SET_ORDER);
        $sort = null;
        $filter = null;
        foreach ($pairs as [, $key, $value]) {
            if ($key === 'sort') {
                $sort = $value;
            } elseif ($key === 'filter') {
                $filter = $value;
            }
        }
        if ($sort !== null && $list instanceof DataList) {
            $descending = str_starts_with($sort, '-');
            $list = $list->sort(ltrim($sort, '-'), $descending ? 'DESC' : 'ASC');
        }
        $result = '';
        foreach ($list as $record) {
            if ($filter !== null && !$this->matches($record, $filter)) {
                continue;
            }
            $result .= $this->fill($block, $record, $me, $html, null, $context);
        }
        return $result;
    }

    /**
     * The block once per chosen text block: {{Body}} is its text (HTML, its
     * placeholders filled for the case file), other placeholders refer to the
     * text block (e.g. {{Title}}).
     *
     * @param DocumentContext $context
     */
    private function repeatBlocks(string $block, DataObject $case, ?Member $me, bool $html, array $context): string
    {
        $marker = "\u{E000}body\u{E000}";
        $template = (string) preg_replace('/\{\{\s*Body\s*\}\}/', $marker, $block);
        $result = '';
        foreach ($context['SelectedBlocks'] ?? [] as $textBlock) {
            // Without the chosen blocks: a block cannot repeat them inside itself.
            $inner = $context;
            unset($inner['SelectedBlocks']);
            $body = $this->replace((string) $textBlock->Body, $case, $me, true, null, $inner);
            if (!$html) {
                $body = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5));
            }
            $result .= str_replace($marker, $body, $this->fill($template, $textBlock, $me, $html, null, $context));
        }
        return $result;
    }

    /**
     * filter=Field: the value is not empty; filter=Field:value: it equals the value.
     */
    private function matches(DataObject $record, string $filter): bool
    {
        [$path, $expected] = array_pad(explode(':', $filter, 2), 2, null);
        $value = $this->resolve($record, explode('.', (string) $path));
        return $expected === null ? trim($value) !== '' && $value !== '0' : $value === $expected;
    }

    /**
     * @param DocumentContext $context
     */
    private function fill(
        string $text,
        DataObject $case,
        ?Member $me,
        bool $html,
        ?DataObject $item = null,
        array $context = []
    ): string {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.]+)\s*(?:\|([^}|]*))?\}\}/',
            function (array $match) use ($case, $me, $html, $item, $context) {
                $raw = $html ? $this->htmlValue($match[1], $context) : null;
                if ($raw !== null && trim(strip_tags($raw)) !== '') {
                    return $raw;
                }
                $value = $raw !== null ? '' : $this->value($match[1], $case, $me, $item, $context);
                if (trim($value) === '' && isset($match[2])) {
                    $value = trim($match[2]);
                }
                return $html ? htmlspecialchars($value, ENT_QUOTES | ENT_HTML5) : $value;
            },
            $text
        );
    }

    /**
     * Values that go into HTML as HTML: the free text (escaped, line breaks
     * kept). Null for all others.
     *
     * @param DocumentContext $context
     */
    private function htmlValue(string $path, array $context): ?string
    {
        if ($path !== 'FreeText') {
            return null;
        }
        return nl2br(htmlspecialchars((string) ($context['FreeText'] ?? ''), ENT_QUOTES | ENT_HTML5), false);
    }

    /**
     * @param DocumentContext $context
     */
    public function value(
        string $path,
        DataObject $case,
        ?Member $me,
        ?DataObject $item = null,
        array $context = []
    ): string {
        $parts = explode('.', $path);
        $head = array_shift($parts);
        switch ($head) {
            case 'Item':
                return $item && $parts ? $this->resolve($item, $parts) : '';
            case 'Today':
                return date('d.m.Y');
            case 'Link':
                // A field of the domain class named Link wins.
                if (!$parts && !DataObject::getSchema()->fieldSpec($case::class, 'Link')) {
                    return $this->link($case);
                }
                break;
            case 'Status':
                $status = $case->hasExtension(CaseFileExtension::class) ? $case->getComponent('FlowStatus') : null;
                return $status && $status->exists() ? (string) $status->getField('Title') : '';
            case 'Me':
                return $me ? $this->resolve($me, $parts ?: ['Name']) : '';
            case 'Signer':
                $signer = $context['Signer'] ?? null;
                return $signer ? $this->resolve($signer, $parts ?: ['Name']) : '';
            case 'FreeText':
                return $parts ? '' : (string) ($context['FreeText'] ?? '');
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
            if ($object instanceof DBDatetime) {
                return $object->getValue() ? (string) $object->Format('dd.MM.yyyy HH:mm') : '';
            }
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

    private function link(DataObject $case): string
    {
        $link = FollowUpController::recordLink($case);
        return $link ? Director::absoluteURL($link) : '';
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
