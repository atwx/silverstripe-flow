<?php

namespace Atwx\Flow\Retention;

use InvalidArgumentException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * A rule of a deletion run: which records are deleted after which period.
 * Built from the settings in Retention.rules; a rule with own logic extends
 * this class (setting "rule") and overrides candidates() or keepReason().
 */
class RetentionRule
{
    use Injectable;

    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(protected string $name, protected array $settings)
    {
        $class = (string) ($settings['class'] ?? '');
        if (!class_exists($class) || !is_subclass_of($class, DataObject::class)) {
            throw new InvalidArgumentException(sprintf('Retention rule %s: unknown class "%s"', $name, $class));
        }
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return (string) ($this->settings['title'] ?? $this->name);
    }

    public function getDescription(): string
    {
        return (string) ($this->settings['description'] ?? '');
    }

    /**
     * Run by the daily flow:retention command, not only by hand.
     */
    public function isAutomatic(): bool
    {
        return !empty($this->settings['automatic']);
    }

    /**
     * @return class-string<DataObject>
     */
    public function modelClass(): string
    {
        return (string) ($this->settings['class'] ?? '');
    }

    public function dateField(): string
    {
        return (string) ($this->settings['date_field'] ?? 'Created');
    }

    /**
     * Records dated before this day are due (Y-m-d).
     */
    public function cutoff(): string
    {
        $now = DBDatetime::now()->getTimestamp();
        $months = (int) ($this->settings['months'] ?? 0);
        $days = (int) ($this->settings['days'] ?? 0);
        if ($months <= 0 && $days <= 0) {
            throw new InvalidArgumentException(sprintf('Retention rule %s: months or days required', $this->name));
        }
        return date('Y-m-d', (int) strtotime(sprintf('-%d months -%d days', $months, $days), $now));
    }

    /**
     * The records that are due: dated before the cutoff, with the rule's
     * filter and exclude.
     *
     * @return DataList<DataObject>
     */
    public function candidates(): DataList
    {
        $list = DataObject::get($this->modelClass())
            ->filter($this->dateField() . ':LessThan', $this->cutoff())
            ->exclude($this->dateField(), null);
        if (!empty($this->settings['filter'])) {
            $list = $list->filter((array) $this->settings['filter']);
        }
        if (!empty($this->settings['exclude'])) {
            $list = $list->exclude((array) $this->settings['exclude']);
        }
        return $list;
    }

    /**
     * Why a due record is kept this time (shown as a count), or null to delete it.
     */
    public function keepReason(DataObject $record): ?string
    {
        return null;
    }

    /**
     * A label of a record for the preview (no other personal data).
     */
    public function label(DataObject $record): string
    {
        return sprintf('%s (#%d)', $record->getTitle(), $record->ID);
    }
}
