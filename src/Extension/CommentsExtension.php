<?php

namespace Atwx\Flow\Extension;

use Atwx\Flow\Model\Comment;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * Comments on records that are no case files (case files have them already):
 *
 *   App\Models\School:
 *     extensions:
 *       - Atwx\Flow\Extension\CommentsExtension
 *
 * The comments are deleted with the record. The controller of the record gets
 * the sub-list with CommentsControllerExtension.
 *
 * @method HasManyList<Comment> FlowComments()
 * @extends Extension<DataObject&static>
 */
class CommentsExtension extends Extension
{
    private static array $has_many = [
        'FlowComments' => Comment::class . '.Record',
    ];

    private static array $cascade_deletes = [
        'FlowComments',
    ];
}
