<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\Comment;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use SilverStripe\ORM\DataObject;

/**
 * Columns of comment lists (in a record and in the overview of follow-ups and to-dos).
 */
class FollowUpColumns
{
    /**
     * Comments of one record: date, text with kind and member responsible,
     * author and date, done.
     */
    public static function forRecord(): ColumnCollection
    {
        $labels = Comment::singleton()->fieldLabels();
        return ColumnCollection::create()
            ->make('DueDate', $labels['DueDate'])->format(self::dueFormatter())->type('html')->end()
            ->make('Summary', $labels['Text'])->format(self::summaryFormatter())->type('html')->end()
            ->make('CreatedBy.Name', $labels['CreatedBy'])
                ->format(fn ($value, DataObject $record) => self::authorLine($record))
                ->end()
            ->make('IsDone', $labels['IsDone'])
                ->format(fn ($value) => $value ? _t(self::class . '.YES', 'yes') : '')
                ->end();
    }

    /**
     * Date (and time) as dd.mm.yyyy hh:mm; overdue tasks in red, upcoming dates bold.
     */
    public static function dueFormatter(): callable
    {
        return function ($value, DataObject $record) {
            if (!$value || !$record instanceof Comment) {
                return '';
            }
            $label = htmlspecialchars($record->getDueLabel());
            if ($record->isOverdue()) {
                return '<span class="text-error font-medium">' . $label . '</span>';
            }
            return $record->isUpcoming() ? '<strong>' . $label . '</strong>' : $label;
        };
    }

    /**
     * Text with kind and member responsible in front; important ones bold.
     */
    public static function summaryFormatter(): callable
    {
        return function ($value, DataObject $record) {
            if (!$record instanceof Comment) {
                return '';
            }
            $text = nl2br(htmlspecialchars($record->getSummary()), false);
            return $record->Important
                ? '<strong>' . $text . '</strong> <span class="badge badge-warning badge-sm">'
                    . htmlspecialchars(_t(self::class . '.IMPORTANT', 'important')) . '</span>'
                : $text;
        };
    }

    private static function authorLine(DataObject $record): string
    {
        if (!$record instanceof Comment) {
            return '';
        }
        $author = $record->CreatedBy();
        $date = date('d.m.Y', (int) strtotime((string) $record->Created));
        return $author->exists() ? $author->getName() . ', ' . $date : $date;
    }
}
