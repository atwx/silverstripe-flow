<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Contact\ContactChannelsControllerExtension;
use Atwx\Flow\Contact\ContactPersonsControllerExtension;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use SilverStripe\Dev\TestOnly;

class TestSchoolController extends FrontdeskController implements TestOnly
{
    private static string $managed_model = TestSchool::class;

    private static string $url_segment = 'test-schools';

    private static array $extensions = [
        ContactChannelsControllerExtension::class,
        ContactPersonsControllerExtension::class,
    ];

    public function canView(mixed $member = null): bool
    {
        return true;
    }

    public function canEdit(mixed $member = null): bool
    {
        return true;
    }
}
