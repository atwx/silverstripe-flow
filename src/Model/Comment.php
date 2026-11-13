<?php

namespace Atwx\Flow\Model;

use Atwx\Flow\Controller\FollowUpController;
use Atwx\SilverstripeFrontdeskKit\Catalog\CatalogEntry;
use Atwx\SilverstripeFrontdeskKit\Catalog\Catalogs;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\ORM\DB;
use SilverStripe\Security\Member;
use SilverStripe\Security\Security;

/**
 * A comment on any record (case file, person, school, ...): memo, follow-up
 * ("Wiedervorlage"), to-do or appointment, with a maintainable type (catalog
 * flow_comment_type), text, optional date and time, the member responsible and
 * the mark "important". Follow-ups and to-dos are marked as done instead of
 * deleted. The kind, not the type name, decides in which lists an entry
 * appears.
 *
 * The author (CreatedBy) stays when someone else edits the comment
 * (LastEditedBy). Follow-up rules create follow-ups (Rule).
 *
 * @property string $Kind memo, followup, todo or appointment
 * @property ?string $Text
 * @property ?string $DueDate
 * @property ?string $DueTime
 * @property bool $Important
 * @property bool $IsDone
 * @property ?string $DoneAt
 * @property int $RecordID
 * @property string $RecordClass
 * @property int $TypeID
 * @property int $MemberID
 * @property int $RuleID
 * @property int $CreatedByID
 * @property int $LastEditedByID
 * @property int $DoneByID
 * @method DataObject Record()
 * @method CatalogEntry Type()
 * @method Member Member()
 * @method FollowUpRule Rule()
 * @method Member CreatedBy()
 * @method Member LastEditedBy()
 * @method Member DoneBy()
 */
class Comment extends DataObject
{
    public const string MEMO = 'memo';
    public const string FOLLOW_UP = 'followup';
    public const string TODO = 'todo';
    public const string APPOINTMENT = 'appointment';

    /**
     * Kinds that are marked as done and appear in the lists of open entries.
     */
    public const array TASK_KINDS = [self::FOLLOW_UP, self::TODO];

    public const string TYPE_CATALOG = 'flow_comment_type';

    /**
     * Longest text.
     */
    private static int $max_length = 2000;

    private static string $table_name = 'Flow_Comment';

    private static array $db = [
        'Kind' => "Enum('memo,followup,todo,appointment', 'memo')",
        'Text' => 'Text',
        'DueDate' => 'Date',
        'DueTime' => 'Time',
        'Important' => 'Boolean',
        'IsDone' => 'Boolean',
        'DoneAt' => 'Datetime',
    ];

    private static array $has_one = [
        'Record' => DataObject::class,
        'Type' => CatalogEntry::class,
        'Member' => Member::class,
        'Rule' => FollowUpRule::class,
        'CreatedBy' => Member::class,
        'LastEditedBy' => Member::class,
        'DoneBy' => Member::class,
    ];

    private static array $catalog_fields = [
        'Type' => self::TYPE_CATALOG,
    ];

    private static array $indexes = [
        'Open' => ['type' => 'index', 'columns' => ['IsDone', 'Kind', 'DueDate']],
    ];

    private static array $summary_fields = [
        'DueDate.Nice',
        'Text',
        'Member.Name',
    ];

    private static string $default_sort = 'IsDone ASC, DueDate ASC, ID ASC';

    /**
     * @return array<string, string>
     */
    public static function kindOptions(): array
    {
        return [
            self::MEMO => _t(self::class . '.KIND_MEMO', 'Memo'),
            self::FOLLOW_UP => _t(self::class . '.KIND_FOLLOW_UP', 'Follow-up'),
            self::TODO => _t(self::class . '.KIND_TODO', 'To-do'),
            self::APPOINTMENT => _t(self::class . '.KIND_APPOINTMENT', 'Appointment'),
        ];
    }

    /**
     * The record that holds the comments of a record: itself, or what its
     * method flowCommentRecord() returns (e.g. the child of a student record).
     * Used by the sub-list, follow-up rules and the timeline alike.
     */
    public static function recordFor(DataObject $record): DataObject
    {
        if ($record->hasMethod('flowCommentRecord')) {
            $target = $record->flowCommentRecord();
            if ($target instanceof DataObject && $target->exists()) {
                return $target;
            }
        }
        return $record;
    }

    /**
     * Creates the comment type Memo as default when the catalog has no entries
     * (also for seeders that empty the catalogs). Returns the default entry.
     */
    public static function ensureDefaultType(): ?CatalogEntry
    {
        $catalogs = Catalogs::singleton();
        if (!$catalogs->exists(self::TYPE_CATALOG)) {
            return null;
        }
        if (!$catalogs->all(self::TYPE_CATALOG)->exists()) {
            $catalogs->sync(self::TYPE_CATALOG, ['memo' => ['Title' => 'Memo', 'IsDefault' => true]]);
        }
        return $catalogs->defaultEntry(self::TYPE_CATALOG);
    }

    /**
     * Comments of a record (stored on the record itself; see recordFor()).
     *
     * @return DataList<Comment>
     */
    public static function forRecord(DataObject $record): DataList
    {
        // PHPStan reports static<T> from DataList::filter() as incompatible with DataList<T>.
        // @phpstan-ignore return.type
        return self::get()->filter(['RecordClass' => $record->ClassName, 'RecordID' => (int) $record->ID]);
    }

    /**
     * Order of the comment list of a record: entries with a date from today on
     * (by date), then important ones, then the rest (latest change first).
     *
     * @param DataList<Comment> $list
     * @return DataList<Comment>
     */
    public static function inListOrder(DataList $list, ?string $today = null): DataList
    {
        $today = (string) DB::get_conn()->quoteString($today ?? date('Y-m-d'));
        $upcoming = "\"Flow_Comment\".\"DueDate\" >= {$today} AND \"Flow_Comment\".\"IsDone\" = 0";
        return $list->orderBy(
            "CASE WHEN {$upcoming} THEN 0 WHEN \"Flow_Comment\".\"Important\" = 1 THEN 1 ELSE 2 END ASC, "
            . "CASE WHEN {$upcoming} THEN \"Flow_Comment\".\"DueDate\" END ASC, "
            . "CASE WHEN {$upcoming} THEN \"Flow_Comment\".\"DueTime\" END ASC, "
            . '"Flow_Comment"."LastEdited" DESC, "Flow_Comment"."ID" DESC'
        );
    }

    /**
     * Order of the comment stream: open appointments (by date), then the
     * important comments, then the rest; within those newest first.
     *
     * @template T of DataList
     * @param T $list
     * @return T
     */
    public static function streamOrder(DataList $list): DataList
    {
        $appointment = "\"Flow_Comment\".\"Kind\" = 'appointment' AND \"Flow_Comment\".\"IsDone\" = 0";
        return $list->orderBy(
            "CASE WHEN {$appointment} THEN 0 WHEN \"Flow_Comment\".\"Important\" = 1 THEN 1 ELSE 2 END ASC, "
            . "CASE WHEN {$appointment} THEN \"Flow_Comment\".\"DueDate\" END ASC, "
            . "CASE WHEN {$appointment} THEN \"Flow_Comment\".\"DueTime\" END ASC, "
            . '"Flow_Comment"."Created" DESC, "Flow_Comment"."ID" DESC'
        );
    }

    /**
     * Highlighted in the stream: open appointments and important comments.
     */
    public function getStreamHighlight(): bool
    {
        return ($this->Kind === self::APPOINTMENT && !$this->IsDone) || $this->Important;
    }

    /**
     * Meta line of the stream: "13.11.2026 // Olga Organisation // Telefonat".
     */
    public function getStreamMeta(): string
    {
        $parts = [date('d.m.Y', (int) strtotime((string) $this->Created))];
        $editor = $this->LastEditedByID ? $this->LastEditedBy() : $this->CreatedBy();
        if ($editor->exists()) {
            $parts[] = $editor->getName();
        }
        $type = $this->Type();
        if ($type->exists()) {
            $parts[] = $type->getLabel();
        }
        return implode(' // ', $parts);
    }

    /**
     * Kind and responsible member in front of the text, except for memos:
     * "Follow-up (Olga Organisation):".
     */
    public function getStreamLead(): string
    {
        if (in_array((string) $this->Kind, ['', self::MEMO], true)) {
            return '';
        }
        $member = $this->Member();
        return $this->getKindLabel() . ($member->exists() ? ' (' . $member->getName() . ')' : '') . ':';
    }

    /**
     * Date line: "Termin: 14.11.2026 10:00" or "Fällig: 14.11.2026", "" without a date.
     */
    public function getStreamDue(): string
    {
        $due = $this->getDueLabel();
        if ($due === '') {
            return '';
        }
        $label = $this->Kind === self::APPOINTMENT
            ? _t(self::class . '.STREAM_APPOINTMENT', 'Appointment')
            : _t(self::class . '.STREAM_DUE', 'Due');
        $done = $this->IsDone ? ' (' . _t(self::class . '.STREAM_DONE', 'done') . ')' : '';
        return $label . ': ' . $due . $done;
    }

    /**
     * The text with line breaks, escaped.
     */
    public function getTextHtml(): DBHTMLText
    {
        return DBHTMLText::create()->setValue(nl2br(htmlspecialchars((string) $this->Text), false));
    }

    public function isTask(): bool
    {
        return in_array((string) $this->Kind, self::TASK_KINDS, true);
    }

    public function isOverdue(): bool
    {
        return $this->isTask() && !$this->IsDone && $this->DueDate && $this->DueDate < date('Y-m-d');
    }

    public function isUpcoming(): bool
    {
        return !$this->IsDone && $this->DueDate && $this->DueDate >= date('Y-m-d');
    }

    public function getKindLabel(): string
    {
        return self::kindOptions()[(string) $this->Kind] ?? (string) $this->Kind;
    }

    /**
     * Text with the kind and the member responsible in front, except for memos:
     * "Follow-up (Anna): call the school".
     */
    public function getSummary(): string
    {
        $text = (string) $this->Text;
        if ($this->Kind === self::MEMO) {
            return $text;
        }
        $member = $this->Member();
        $prefix = $this->getKindLabel() . ($member->exists() ? ' (' . $member->getName() . ')' : '');
        return $prefix . ': ' . $text;
    }

    /**
     * Link to the record of the comment, "" when no list shows it.
     */
    public function getRecordLink(): string
    {
        $record = $this->Record();
        return $record->exists() ? (string) FollowUpController::recordLink($record) : '';
    }

    public function getRecordTitle(): string
    {
        $record = $this->Record();
        return $record->exists() ? (string) $record->getTitle() : '';
    }

    /**
     * Date (and time) as dd.mm.yyyy hh:mm.
     */
    public function getDueLabel(): string
    {
        if (!$this->DueDate) {
            return '';
        }
        $date = date('d.m.Y', (int) strtotime((string) $this->DueDate));
        return $this->DueTime ? $date . ' ' . substr((string) $this->DueTime, 0, 5) : $date;
    }

    /**
     * @param bool $includerelations
     * @return array<string, string>
     */
    public function fieldLabels($includerelations = true): array
    {
        $labels = parent::fieldLabels($includerelations);
        $labels['Kind'] = _t(self::class . '.db_Kind', 'Kind');
        $labels['Type'] = $labels['TypeID'] = _t(self::class . '.has_one_Type', 'Type');
        $labels['Text'] = _t(self::class . '.db_Text', 'Text');
        $labels['DueDate'] = $labels['DueDate.Nice'] = _t(self::class . '.db_DueDate', 'Date');
        $labels['DueTime'] = _t(self::class . '.db_DueTime', 'Time');
        $labels['Important'] = _t(self::class . '.db_Important', 'Important');
        $labels['Member'] = $labels['MemberID'] = $labels['Member.Name'] = _t(
            self::class . '.has_one_Member',
            'Responsible'
        );
        $labels['IsDone'] = $labels['IsDone.Nice'] = _t(self::class . '.db_IsDone', 'Done');
        $labels['CreatedBy'] = $labels['CreatedBy.Name'] = _t(self::class . '.has_one_CreatedBy', 'Author');
        $labels['RecordTitle'] = _t(self::class . '.RECORD', 'Record');
        return $labels;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        $max = (int) static::config()->get('max_length');
        if ($max > 0 && mb_strlen((string) $this->Text) > $max) {
            $result->addFieldError('Text', _t(
                self::class . '.TEXT_TOO_LONG',
                'At most {max} characters, please.',
                ['max' => $max]
            ));
        }
        if (trim((string) $this->Text) === '') {
            $result->addFieldError('Text', _t(self::class . '.TEXT_REQUIRED', 'Please enter a text.'));
        }
        return $result;
    }

    /**
     * Author, last editor, default type (the catalog's default entry) and who
     * closed it when.
     */
    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $me = (int) Security::getCurrentUser()?->ID;
        if (!$this->isInDB()) {
            if (!$this->CreatedByID) {
                $this->CreatedByID = $me;
            }
            if (!$this->TypeID && Catalogs::singleton()->exists(self::TYPE_CATALOG)) {
                $this->TypeID = (int) Catalogs::singleton()->defaultEntry(self::TYPE_CATALOG)?->ID;
            }
        } elseif ($me && $this->isChanged()) {
            $this->LastEditedByID = $me;
        }
        if ($this->isChanged('IsDone', DataObject::CHANGE_VALUE)) {
            $this->DoneAt = $this->IsDone ? date('Y-m-d H:i:s') : null;
            $this->DoneByID = $this->IsDone ? $me : 0;
        }
    }

    /**
     * The comment type Memo (default) when there are no types yet. Follow-ups of
     * earlier versions (Flow_FollowUp) become comments of kind follow-up; the old
     * table is then renamed to _obsolete_Flow_FollowUp.
     */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();
        $catalogs = Catalogs::singleton();
        if ($catalogs->exists(self::TYPE_CATALOG) && !$catalogs->all(self::TYPE_CATALOG)->exists()) {
            self::ensureDefaultType();
            DB::alteration_message('Created the comment type Memo', 'created');
        }
        $tables = DB::table_list();
        if (!isset($tables[strtolower('Flow_FollowUp')])) {
            return;
        }
        DB::query(
            'INSERT INTO "Flow_Comment" ("ClassName", "Created", "LastEdited", "Kind", "Text", "DueDate",'
            . ' "IsDone", "DoneAt", "RecordClass", "RecordID", "MemberID", "RuleID", "CreatedByID", "DoneByID")'
            . ' SELECT ' . DB::get_conn()->quoteString(self::class) . ', "Created", "LastEdited", '
            . DB::get_conn()->quoteString(self::FOLLOW_UP) . ', "Text", "DueDate", "IsDone", "DoneAt",'
            . ' "CaseClass", "CaseID", "MemberID", "RuleID", "CreatedByID", "DoneByID" FROM "Flow_FollowUp"'
        );
        $count = DB::affected_rows();
        $renameTo = '_obsolete_Flow_FollowUp';
        for ($suffix = 2; isset($tables[strtolower($renameTo)]); $suffix++) {
            $renameTo = '_obsolete_Flow_FollowUp' . $suffix;
        }
        DB::get_schema()->renameTable('Flow_FollowUp', $renameTo);
        DB::alteration_message("Moved {$count} follow-ups to Flow_Comment", 'changed');
    }

    /**
     * Visible to members who may view the record: through the controller that
     * manages its class (or the record's own permission), never without login.
     */
    public function canView($member = null): bool
    {
        $member = $member ?? Security::getCurrentUser();
        if (!$member) {
            return false;
        }
        $record = $this->Record();
        if (!$record->exists()) {
            return false;
        }
        $controller = FollowUpController::recordControllerFor($record::class);
        return $controller ? $controller->canView($member) : $record->canView($member);
    }

    public function canEdit($member = null): bool
    {
        return $this->canView($member);
    }

    public function canDelete($member = null): bool
    {
        return $this->canView($member);
    }

    /**
     * @param mixed $member
     * @param array<string, mixed> $context
     */
    public function canCreate($member = null, $context = []): bool
    {
        return (bool) ($member ?? Security::getCurrentUser());
    }
}
