<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Retention\RetentionRule;
use SilverStripe\Dev\TestOnly;
use SilverStripe\ORM\DataObject;

/**
 * Keeps courses in Marburg.
 */
class KeepVenueRule extends RetentionRule implements TestOnly
{
    public function keepReason(DataObject $record): ?string
    {
        return $record->getField('Venue') === 'Marburg' ? 'Marburg' : null;
    }
}
