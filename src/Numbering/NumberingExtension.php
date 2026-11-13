<?php

namespace Atwx\Flow\Numbering;

use InvalidArgumentException;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Gives a record a number from a number sequence, e.g. an invoice number
 * "RE-2026-0042". The number is drawn once and never changes, also when the
 * date of the record changes later.
 *
 *   App\Models\Invoice:
 *     extensions:
 *       - Atwx\Flow\Numbering\NumberingExtension
 *     flow_numbering:
 *       sequence: 'invoice'          # code of the sequence (default: table name)
 *       field: 'Number'              # Int field of the running number (default Number)
 *       year_field: 'NumberYear'     # optional Int field: the sequence restarts every year
 *       date_field: 'InvoiceDate'    # optional: the year is taken from this date (default: today)
 *       reference_field: 'Reference' # optional Varchar field for the formatted number
 *       format: 'RE-{year}-{number:4}'
 *       assign: 'write'              # 'write': on the first write; 'manual': assignNumber()
 *
 * The fields are defined by the project; add them to fdk_hidden_fields so
 * that nobody edits them in a form. Format placeholders: {year}, {yy},
 * {number} and {number:N} (zero-padded to N digits).
 *
 * @extends Extension<DataObject>
 */
class NumberingExtension extends Extension
{
    protected function onBeforeWrite(): void
    {
        if ($this->setting('assign') !== 'manual') {
            $this->assignNumber();
        }
    }

    /**
     * Draws the number unless the record already has one; true when it was
     * drawn now. The record is not written.
     */
    public function assignNumber(): bool
    {
        $owner = $this->getOwner();
        $field = $this->numberField();
        if ((int) $owner->getField($field) > 0) {
            return false;
        }
        $yearField = $this->setting('year_field');
        $year = $yearField ? $this->year() : 0;
        $period = $yearField ? (string) $year : '';
        $number = NumberSequences::singleton()->next(
            $this->sequenceCode(),
            $period,
            fn () => $this->highestNumber($yearField, $year)
        );
        $owner->setField($field, $number);
        if ($yearField) {
            $owner->setField($yearField, $year);
        }
        $reference = $this->setting('reference_field');
        if ($reference) {
            $owner->setField($reference, $this->formatNumber($number, $year ?: $this->year()));
        }
        return true;
    }

    public function hasNumber(): bool
    {
        return (int) $this->getOwner()->getField($this->numberField()) > 0;
    }

    /**
     * The number in the configured format, or "" without a number.
     */
    public function getFormattedNumber(): string
    {
        $owner = $this->getOwner();
        $number = (int) $owner->getField($this->numberField());
        if ($number <= 0) {
            return '';
        }
        $reference = $this->setting('reference_field');
        if ($reference && (string) $owner->getField($reference) !== '') {
            return (string) $owner->getField($reference);
        }
        $yearField = $this->setting('year_field');
        $year = $yearField ? (int) $owner->getField($yearField) : $this->year();
        return $this->formatNumber($number, $year);
    }

    public function formatNumber(int $number, int $year): string
    {
        $format = (string) ($this->setting('format') ?: '{number}');
        return (string) preg_replace_callback(
            '/\{(year|yy|number)(?::(\d+))?\}/',
            fn (array $m) => match ($m[1]) {
                'year' => (string) $year,
                'yy' => sprintf('%02d', $year % 100),
                default => str_pad((string) $number, (int) ($m[2] ?? 0), '0', STR_PAD_LEFT),
            },
            $format
        );
    }

    protected function updateCMSFields(FieldList $fields): void
    {
        foreach ([$this->numberField(), $this->setting('year_field'), $this->setting('reference_field')] as $name) {
            if ($name && $fields->dataFieldByName($name)) {
                $fields->makeFieldReadonly($name);
            }
        }
    }

    private function highestNumber(?string $yearField, int $year): int
    {
        $owner = $this->getOwner();
        $list = DataObject::get($owner->baseClass());
        if ($yearField) {
            $list = $list->filter($yearField, $year);
        }
        return (int) $list->max($this->numberField());
    }

    private function year(): int
    {
        $owner = $this->getOwner();
        $dateField = $this->setting('date_field');
        $date = $dateField ? (string) $owner->getField($dateField) : '';
        $time = $date !== '' ? strtotime($date) : false;
        return (int) date('Y', $time ?: DBDatetime::now()->getTimestamp());
    }

    private function sequenceCode(): string
    {
        $code = $this->setting('sequence');
        return $code ?? DataObject::getSchema()->tableName($this->getOwner()->baseClass());
    }

    private function numberField(): string
    {
        $field = (string) ($this->setting('field') ?: 'Number');
        if (!$this->getOwner()->hasDatabaseField($field)) {
            throw new InvalidArgumentException(sprintf(
                '%s: flow_numbering field "%s" is not a database field',
                get_class($this->getOwner()),
                $field
            ));
        }
        return $field;
    }

    private function setting(string $key): ?string
    {
        $settings = (array) $this->getOwner()->config()->get('flow_numbering');
        $value = $settings[$key] ?? null;
        return $value === null || $value === '' ? null : (string) $value;
    }
}
