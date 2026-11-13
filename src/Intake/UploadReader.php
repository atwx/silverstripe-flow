<?php

namespace Atwx\Flow\Intake;

use SilverStripe\Core\Injector\Injectable;

/**
 * Reads a file of a multipart request. Only real uploads are read
 * (is_uploaded_file), so a posted field cannot point to a server file.
 * Replaceable in the Injector (tests).
 */
class UploadReader
{
    use Injectable;

    /**
     * @param array<string, mixed> $upload An entry of $_FILES
     */
    public function read(array $upload): ?string
    {
        $path = (string) ($upload['tmp_name'] ?? '');
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        return $content === false ? null : $content;
    }
}
