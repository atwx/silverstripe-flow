<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Contact\ContactChannelsExtension;
use Atwx\Flow\Contact\ContactPersonsExtension;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Record with contact channels and contact persons.
 *
 * @property ?string $Title
 * @property ?string $Email
 * @property ?string $Phone
 * @mixin ContactChannelsExtension
 * @mixin ContactPersonsExtension
 */
class TestSchool extends DataObject implements TestOnly
{
    private static string $table_name = 'FlowTest_School';

    private static array $db = [
        'Title' => 'Varchar(255)',
        'Email' => 'Varchar(255)',
        'Phone' => 'Varchar(100)',
    ];

    private static array $extensions = [
        ContactChannelsExtension::class,
        ContactPersonsExtension::class,
    ];

    private static array $flow_contact_sync = [
        'email' => 'Email',
        'phone' => 'Phone',
    ];

    public function canView($member = null): bool
    {
        return true;
    }

    public function canEdit($member = null): bool
    {
        return true;
    }
}
