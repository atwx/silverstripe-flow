<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Intake\Submission;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Extension;
use SilverStripe\Dev\TestOnly;

/**
 * Remembers the Reply-To of the last notification.
 *
 * @extends Extension<\Atwx\Flow\Intake\IntakeService>
 */
class TestIntakeHooks extends Extension implements TestOnly
{
    public static string $replyTo = '';

    protected function updateNotifyEmail(Email $email, Submission $submission): void
    {
        self::$replyTo = implode(',', array_map(fn ($address) => $address->getAddress(), $email->getReplyTo()));
    }
}
