<?php

namespace Atwx\Flow\Extension;

use Atwx\Flow\Controller\CommentSubController;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;

/**
 * Adds the sub-list of comments (<segment>/comments/<ID>) to a frontdesk
 * controller whose records are no case files (FlowCaseController has it):
 *
 *   App\Controllers\SchoolController:
 *     extensions:
 *       - Atwx\Flow\Extension\CommentsControllerExtension
 *
 * To keep the comments of a record on another one (e.g. those of a student
 * record on its child), give the model a method flowCommentRecord(): DataObject
 * (see Comment::recordFor()); then the sub-list, follow-up rules and the
 * timeline agree. A controller may still map the record with a public method
 * commentRecordFor(DataObject $record): DataObject, for its sub-list only.
 *
 * @extends Extension<FrontdeskController>
 */
class CommentsControllerExtension extends Extension
{
    private static array $sub_controllers = [
        CommentSubController::class,
    ];

    private static array $allowed_actions = [
        'comments',
    ];

    public function comments(HTTPRequest $request): CommentSubController
    {
        return CommentSubController::create()->setParentContext($this->getOwner(), (int) $request->param('ID'));
    }
}
