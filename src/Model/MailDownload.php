<?php

namespace Atwx\Flow\Model;

use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Versioned\Versioned;

/**
 * Download link of a file sent with a mail instead of an attachment:
 * flow/download/<token>, without login, valid for a number of days
 * (MailDownload.valid_days). Points to a document of the case file or to a
 * file of the mail template; the file is served as it is when downloaded, so
 * a replaced file in the CMS reaches the recipient in its current version.
 * Only the SHA-256 hash of the token is stored.
 *
 * @property string $TokenHash
 * @property string $Title
 * @property ?string $ExpiresAt
 * @property int $Downloads
 * @property ?string $LastDownloadAt
 * @property int $MailID
 * @property int $FileID
 * @property int $DocumentID
 * @method CaseMail Mail()
 * @method File File()
 * @method CaseDocument Document()
 */
class MailDownload extends DataObject
{
    use Configurable;

    public const string URL_SEGMENT = 'flow/download';

    private static string $table_name = 'Flow_MailDownload';

    /**
     * Days a link stays valid (0: no expiry).
     */
    private static int $valid_days = 30;

    private static array $db = [
        'TokenHash' => 'Varchar(64)',
        'Title' => 'Varchar(255)',
        'ExpiresAt' => 'Datetime',
        'Downloads' => 'Int',
        'LastDownloadAt' => 'Datetime',
    ];

    private static array $has_one = [
        'Mail' => CaseMail::class,
        'File' => File::class,
        'Document' => CaseDocument::class,
    ];

    private static array $indexes = [
        'TokenHash' => ['type' => 'unique', 'columns' => ['TokenHash']],
    ];

    private ?string $plainToken = null;

    /**
     * New link to a document or a file; written, with the plain token kept for url().
     */
    public static function createFor(CaseMail $mail, ?CaseDocument $document, ?File $file): self
    {
        $token = bin2hex(random_bytes(32));
        $days = (int) static::config()->get('valid_days');
        $download = self::create([
            'MailID' => $mail->ID,
            'DocumentID' => (int) $document?->ID,
            'FileID' => (int) $file?->ID,
            'Title' => $document ? (string) $document->Title : (string) $file?->getTitle(),
            'TokenHash' => self::hashToken($token),
            'ExpiresAt' => $days > 0
                ? date('Y-m-d H:i:s', DBDatetime::now()->getTimestamp() + $days * 86400)
                : null,
        ]);
        $download->write();
        $download->plainToken = $token;
        return $download;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function findByToken(string $token): ?self
    {
        if (!preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }
        return self::get()->find('TokenHash', self::hashToken($token));
    }

    /**
     * Absolute URL; only right after createFor() (the token is not stored).
     */
    public function url(): ?string
    {
        return $this->plainToken !== null
            ? Director::absoluteURL(self::URL_SEGMENT . '/' . $this->plainToken)
            : null;
    }

    public function isExpired(): bool
    {
        return $this->ExpiresAt !== null && $this->ExpiresAt !== ''
            && strtotime((string) $this->ExpiresAt) < DBDatetime::now()->getTimestamp();
    }

    /**
     * The stored file and its download name: the document's PDF or the template
     * file (null when it is gone).
     *
     * @return array{0: DBFile, 1: string}|null
     */
    public function servedFile(): ?array
    {
        if ($this->DocumentID) {
            $document = $this->Document();
            if (!$document->exists() || !$document->File->exists()) {
                return null;
            }
            $name = (preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $document->Title) ?: 'document') . '.pdf';
            return [$document->File, $name];
        }
        // The latest version: template files are not published.
        $file = Versioned::withVersionedMode(function () {
            Versioned::set_stage(Versioned::DRAFT);
            return File::get()->byID((int) $this->FileID);
        });
        return $file && $file->File->exists() ? [$file->File, (string) $file->Name] : null;
    }
}
