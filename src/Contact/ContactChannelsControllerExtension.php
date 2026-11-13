<?php

namespace Atwx\Flow\Contact;

use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Extension;

/**
 * Adds the sub-list of contact channels to a frontdesk controller whose model
 * has ContactChannelsExtension:
 *
 *   App\Controllers\FamilyController:
 *     extensions:
 *       - Atwx\Flow\Contact\ContactChannelsControllerExtension
 *
 * @extends Extension<FrontdeskController>
 */
class ContactChannelsControllerExtension extends Extension
{
    private static array $sub_controllers = [
        ContactChannelSubController::class,
    ];

    private static array $allowed_actions = [
        'contactchannels',
    ];

    public function contactchannels(HTTPRequest $request): ContactChannelSubController
    {
        return ContactChannelSubController::create()->setParentContext($this->getOwner(), (int) $request->param('ID'));
    }
}
