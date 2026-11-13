<?php

namespace Atwx\Flow\Contact;

use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;

/**
 * Adds the sub-list of contact persons to a frontdesk controller whose model
 * has ContactPersonsExtension:
 *
 *   App\Controllers\SchoolController:
 *     extensions:
 *       - Atwx\Flow\Contact\ContactPersonsControllerExtension
 *
 * @extends Extension<FrontdeskController>
 */
class ContactPersonsControllerExtension extends Extension
{
    private static array $sub_controllers = [
        ContactPersonSubController::class,
    ];

    private static array $allowed_actions = [
        'contactpersons',
    ];

    public function contactpersons(HTTPRequest $request): ContactPersonSubController
    {
        return ContactPersonSubController::create()->setParentContext($this->getOwner(), (int) $request->param('ID'));
    }
}
