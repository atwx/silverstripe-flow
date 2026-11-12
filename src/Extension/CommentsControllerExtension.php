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
 * A controller may attach the comments to another record than its own, e.g.
 * the child of a student record, with a public method
 * commentRecordFor(DataObject $record): DataObject.
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
