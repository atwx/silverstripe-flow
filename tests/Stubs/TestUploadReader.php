<?php

namespace Atwx\Flow\Tests\Stubs;

use Atwx\Flow\Intake\UploadReader;
use SilverStripe\Dev\TestOnly;

/**
 * Reads uploads without is_uploaded_file() (tests cannot create real uploads).
 */
class TestUploadReader extends UploadReader implements TestOnly
{
    public function read(array $upload): ?string
    {
        $content = @file_get_contents((string) ($upload['tmp_name'] ?? ''));
        return $content === false ? null : $content;
    }
}
