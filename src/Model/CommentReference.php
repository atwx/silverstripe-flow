<?php

namespace Atwx\Flow\Model;

use SilverStripe\ORM\DataObject;

/**
 * A further record a comment refers to ("tag"), e.g. a school of the case's
 * school choice. The selectable records come from the comment record's
 * flowCommentReferences() (see CommentSubController).
 *
 * @property int $CommentID
 * @property int $TargetID
 * @property string $TargetClass
 * @method Comment Comment()
 * @method DataObject Target()
 */
class CommentReference extends DataObject
{
    private static string $table_name = 'Flow_CommentReference';

    private static array $has_one = [
        'Comment' => Comment::class,
        'Target' => DataObject::class,
    ];

    private static array $indexes = [
        'Target' => ['type' => 'index', 'columns' => ['TargetClass', 'TargetID']],
    ];

    /**
     * Key of a record as used in forms and filters: "<Class>-<ID>" with the
     * namespace separators as dots.
     */
    public static function keyFor(DataObject $record): string
    {
        return str_replace('\\', '.', $record->ClassName) . '-' . (int) $record->ID;
    }

    /**
     * Class and ID of a key, null for a malformed one.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function parseKey(string $key): ?array
    {
        if (!preg_match('/^([A-Za-z0-9_.]+)-(\d+)$/', $key, $match)) {
            return null;
        }
        return [str_replace('.', '\\', $match[1]), (int) $match[2]];
    }

    public function getKey(): string
    {
        return str_replace('\\', '.', (string) $this->TargetClass) . '-' . (int) $this->TargetID;
    }

    public function canView($member = null): bool
    {
        $comment = $this->Comment();
        return $comment->exists() && $comment->canView($member);
    }
}
