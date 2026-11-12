<?php

namespace Atwx\Flow\Model;

use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * A mail of a case file: sent, failed (the error is kept, the case file is not
 * affected) or created as a mail file for the member's mail program. Keeps the
 * full text as sent. A mail per entry of a sub-list also refers to the entry
 * (Item).
 *
 * @property string $Mode sent, failed or eml
 * @property string $Recipients
 * @property string $Cc
 * @property string $ReplyTo
 * @property string $Subject
 * @property string $Body
 * @property string $AttachmentNames
 * @property string $Error
 * @property bool $Automatic
 * @property int $CaseID
 * @property string $CaseClass
 * @property int $TemplateID
 * @property int $MemberID
 * @property int $ItemID
 * @property string $ItemClass
 * @method DataObject Case()
 * @method DataObject Item()
 * @method MailTemplate Template()
 * @method Member Member()
 * @method \SilverStripe\ORM\HasManyList<MailDownload> Downloads()
 */
class CaseMail extends DataObject
{
    public const string SENT = 'sent';
    public const string FAILED = 'failed';
    public const string EML = 'eml';

    private static string $table_name = 'Flow_Mail';

    private static array $db = [
        'Mode' => "Enum('sent,failed,eml', 'sent')",
        'Recipients' => 'Text',
        'Cc' => 'Text',
        'ReplyTo' => 'Varchar(255)',
        'Subject' => 'Varchar(255)',
        'Body' => 'Text',
        'AttachmentNames' => 'Text',
        'Error' => 'Text',
        'Automatic' => 'Boolean',
    ];

    private static array $has_one = [
        'Case' => DataObject::class,
        'Template' => MailTemplate::class,
        'Member' => Member::class,
        'Item' => DataObject::class,
    ];

    private static array $has_many = [
        'Downloads' => MailDownload::class,
    ];

    private static array $cascade_deletes = [
        'Downloads',
    ];

    private static array $summary_fields = [
        'Created.Nice',
        'Subject',
        'Recipients',
        'ModeLabel',
    ];

    private static string $default_sort = 'Created DESC, ID DESC';

    /**
     * @return array<string, string>
     */
    public static function modeOptions(): array
    {
        return [
            self::SENT => _t(self::class . '.MODE_SENT', 'sent'),
            self::FAILED => _t(self::class . '.MODE_FAILED', 'not sent'),
            self::EML => _t(self::class . '.MODE_EML', 'mail file'),
        ];
    }

    /**
     * Mails sent for an entry of a sub-list (e.g. the mails to a school
     * application).
     *
     * @return DataList<CaseMail>
     */
    public static function forItem(DataObject $item): DataList
    {
        // PHPStan reports static<T> from DataList::filter() as incompatible with DataList<T>.
        // @phpstan-ignore return.type
        return self::get()->filter(['ItemClass' => $item->ClassName, 'ItemID' => (int) $item->ID]);
    }

    public function getItemTitle(): string
    {
        $item = $this->ItemID ? $this->Item() : null;
        return $item && $item->exists() ? (string) $item->getTitle() : '';
    }

    public function getModeLabel(): string
    {
        return self::modeOptions()[(string) $this->Mode] ?? (string) $this->Mode;
    }

    public function getSenderName(): string
    {
        $member = $this->Member();
        $name = $member->exists() ? (string) $member->getName() : '';
        if (!$this->Automatic) {
            return $name;
        }
        $automatic = _t(self::class . '.AUTOMATIC', 'automatically');
        return $name !== '' ? "{$name} ({$automatic})" : $automatic;
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Created.Nice'] = _t(self::class . '.CREATED', 'Date');
        $labels['Subject'] = _t(self::class . '.db_Subject', 'Subject');
        $labels['Recipients'] = _t(self::class . '.db_Recipients', 'To');
        $labels['Cc'] = _t(self::class . '.db_Cc', 'Cc');
        $labels['ReplyTo'] = _t(self::class . '.db_ReplyTo', 'Reply to');
        $labels['Body'] = _t(self::class . '.db_Body', 'Text');
        $labels['AttachmentNames'] = _t(self::class . '.db_AttachmentNames', 'Attachments');
        $labels['Mode'] = $labels['ModeLabel'] = _t(self::class . '.db_Mode', 'State');
        $labels['Error'] = _t(self::class . '.db_Error', 'Error');
        $labels['Automatic'] = _t(self::class . '.db_Automatic', 'Automatic');
        $labels['Item'] = $labels['ItemTitle'] = _t(self::class . '.has_one_Item', 'Entry');
        return $labels;
    }

    public function canView($member = null): bool
    {
        $member = $member ?? Security::getCurrentUser();
        if (!$member) {
            return false;
        }
        $case = $this->Case();
        return $case->exists() && $case->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return false;
    }

    public function canDelete($member = null): bool
    {
        return false;
    }

    /**
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return false;
    }
}
