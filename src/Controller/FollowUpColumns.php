<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\FollowUp;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use SilverStripe\ORM\DataObject;

/**
 * Columns of follow-up lists (in a case file and in the overview).
 */
class FollowUpColumns
{
    public static function forCaseFile(): ColumnCollection
    {
        $labels = FollowUp::singleton()->fieldLabels();
        return ColumnCollection::create()
            ->make('DueDate', $labels['DueDate'])->format(self::dueFormatter())->type('html')->end()
            ->make('Text', $labels['Text'])->end()
            ->make('Member.Name', $labels['Member.Name'])->end()
            ->make('IsDone', $labels['IsDone'])
                ->format(fn ($value) => $value ? _t(self::class . '.YES', 'yes') : '')
                ->end();
    }

    /**
     * Due date as dd.mm.yyyy, overdue ones highlighted.
     */
    public static function dueFormatter(): callable
    {
        return function ($value, DataObject $record) {
            if (!$value) {
                return '';
            }
            $date = htmlspecialchars(date('d.m.Y', (int) strtotime((string) $value)));
            return $record instanceof FollowUp && $record->isOverdue()
                ? '<span class="text-error font-medium">' . $date . '</span>'
                : $date;
        };
    }
}
