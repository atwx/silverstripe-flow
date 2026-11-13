<?php

namespace Atwx\Flow\Intake;

use Atwx\Flow\Api\ApiClient;
use Atwx\Flow\Api\ApiException;
use Atwx\Flow\Document\PdfRenderer;
use Atwx\Flow\Model\TextBlock;
use Psr\Log\LoggerInterface;
use SilverStripe\Assets\File;
use SilverStripe\Control\Director;
use SilverStripe\Control\Email\Email;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\SSViewer;
use Throwable;

/**
 * The inbox: form types (config "types"), accepting submissions with files,
 * notification, receipt PDF, takeover, discarding and retention.
 *
 *   Atwx\Flow\Intake\IntakeService:
 *     types:
 *       application:
 *         title: Kurzbewerbung
 *         reference_prefix: KB
 *         fields: { … }            # see IntakeType
 *         files: { report: { title: Zeugnis, types: [pdf, jpg, png], max_size: 10MB, max_count: 3 } }
 *         summary: '{{child.first_name}} {{child.last_name}}'
 *         reply_to: parent_email
 *         notify: { to: 'office@example.org', subject: 'Neue Kurzbewerbung {{Reference}}', block: Code, fields: [] }
 *         receipt: { hours: 24 }
 *         retention: { new: 0, taken_over: 30, discarded: 30 }
 *         takeover: App\Intake\ApplicationTakeover
 */
class IntakeService
{
    use Injectable;
    use Configurable;
    use Extensible;

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private static array $types = [];

    /**
     * Accepted file extensions and their MIME types (checked against the
     * content, not the name).
     *
     * @var array<string, array<int, string>>
     */
    private static array $mime_types = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'heic' => ['image/heic', 'image/heif'],
        'txt' => ['text/plain'],
        'doc' => ['application/msword', 'application/x-ole-storage', 'application/CDFV2'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
    ];

    /**
     * Folder of the uploaded files.
     */
    private static string $folder = 'intake';

    /**
     * Hours a receipt link is valid unless the type sets receipt.hours.
     */
    private static int $receipt_hours = 24;

    /**
     * @return array<string, IntakeType>
     */
    public function types(): array
    {
        $types = [];
        foreach ((array) static::config()->get('types') as $name => $config) {
            if (is_array($config) && preg_match('/^[a-z0-9_-]+$/', (string) $name)) {
                $types[(string) $name] = new IntakeType((string) $name, $config);
            }
        }
        return $types;
    }

    public function type(string $name): ?IntakeType
    {
        return $this->types()[$name] ?? null;
    }

    /**
     * Accepts a submission. $files: field => list of [name, content (bytes)].
     * Returns the submission and whether it is a repetition (same
     * idempotency key of the same client).
     *
     * @param array<mixed> $data
     * @param array<string, array<int, array{name: string, content: string}>> $files
     * @return array{0: Submission, 1: bool}
     */
    public function submit(
        IntakeType $type,
        array $data,
        array $files,
        ?ApiClient $client,
        ?string $idempotencyKey = null
    ): array {
        $idempotencyKey = $idempotencyKey !== null ? trim($idempotencyKey) : null;
        if ($idempotencyKey !== null && ($idempotencyKey === '' || strlen($idempotencyKey) > 128)) {
            throw ApiException::invalid(['Idempotency-Key' => 'At most 128 characters'], 'Invalid Idempotency-Key');
        }
        $clientID = $client ? $client->ID : 0;
        $lock = $idempotencyKey !== null ? 'flow-intake-' . md5($clientID . '|' . $idempotencyKey) : null;
        if ($lock !== null) {
            DB::get_conn()->getLock($lock, 10);
        }
        try {
            if ($lock !== null) {
                $existing = Submission::get()->filter([
                    'ClientID' => $client ? $client->ID : 0,
                    'IdempotencyKey' => $idempotencyKey,
                ])->first();
                if ($existing instanceof Submission) {
                    if ($existing->Type !== $type->getName()) {
                        throw new ApiException(409, 'Idempotency-Key already used for another form type');
                    }
                    return [$existing, true];
                }
            }
            [$clean, $errors] = $type->validate($data);
            $checkedFiles = $this->checkFiles($type, $files, $errors);
            if ($errors !== []) {
                throw ApiException::invalid($errors);
            }
            $submission = Submission::create([
                'Type' => $type->getName(),
                'ClientID' => $client ? $client->ID : 0,
                'IdempotencyKey' => $idempotencyKey,
            ]);
            $submission->setStatus(Submission::NEW);
            $submission->storeValues($clean);
            DB::get_conn()->withTransaction(function () use ($type, $checkedFiles, $submission) {
                $this->assignReference($submission, $type);
                foreach ($checkedFiles as $field => $list) {
                    foreach ($list as $i => $file) {
                        $this->storeFile($submission, $field, $i, $file['name'], $file['content'], $file['extension']);
                    }
                }
            });
        } finally {
            if ($lock !== null) {
                DB::get_conn()->releaseLock($lock);
            }
        }
        $this->notify($submission);
        $this->extend('onAfterSubmit', $submission);
        return [$submission, false];
    }

    /**
     * @param array<string, array<int, array{name: string, content: string}>> $files
     * @param array<string, string> $errors
     * @return array<string, array<int, array{name: string, content: string, extension: string}>>
     */
    private function checkFiles(IntakeType $type, array $files, array &$errors): array
    {
        $definitions = $type->files();
        $checked = [];
        foreach (array_keys($files) as $field) {
            if (!isset($definitions[$field])) {
                $errors['files.' . $field] = 'Unknown file field';
            }
        }
        $mimeTypes = (array) static::config()->get('mime_types');
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        foreach ($definitions as $field => $definition) {
            $list = array_values($files[$field] ?? []);
            $path = 'files.' . $field;
            if ($list === [] && !empty($definition['required'])) {
                $errors[$path] = 'Required';
            }
            $max = max(1, (int) ($definition['max_count'] ?? 1));
            if (count($list) > $max) {
                $errors[$path] = 'At most ' . $max . ' files';
                continue;
            }
            $allowed = array_map('strtolower', (array) ($definition['types'] ?? ['pdf']));
            foreach ($list as $i => $file) {
                $name = trim((string) $file['name']);
                $content = (string) $file['content'];
                $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $mime = (string) $finfo->buffer($content);
                if ($content === '') {
                    $errors[$path . '.' . $i] = 'Empty file';
                } elseif (strlen($content) > IntakeType::maxSize($definition)) {
                    $errors[$path . '.' . $i] = 'Larger than ' . IntakeType::maxSize($definition) . ' bytes';
                } elseif (!in_array($extension, $allowed, true) || !isset($mimeTypes[$extension])) {
                    $errors[$path . '.' . $i] = 'File type not allowed (' . implode(', ', $allowed) . ')';
                } elseif (!in_array($mime, (array) $mimeTypes[$extension], true)) {
                    $errors[$path . '.' . $i] = 'Content does not match the file type';
                } else {
                    $checked[$field][] = [
                        'name' => mb_substr($name, 0, 255),
                        'content' => $content,
                        'extension' => $extension,
                    ];
                }
            }
        }
        return $checked;
    }

    /**
     * Next number of the type in the year: <prefix>-<year>-<number, 4 digits>.
     */
    private function assignReference(Submission $submission, IntakeType $type): void
    {
        $year = (int) DBDatetime::now()->Format('y');
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $last = (int) Submission::get()->filter(['Type' => $type->getName(), 'Year' => $year])->max('Number');
            $submission->Year = $year;
            $submission->Number = $last + 1 + $attempt;
            $submission->Reference = sprintf('%s-%d-%04d', $type->referencePrefix(), $year, $submission->Number);
            if (!Submission::get()->filter('Reference', $submission->Reference)->exists()) {
                $submission->write();
                return;
            }
        }
        throw new ApiException(500, 'No reference number available');
    }

    private function storeFile(
        Submission $submission,
        string $field,
        int $index,
        string $name,
        string $content,
        string $extension
    ): void {
        $folder = trim((string) static::config()->get('folder'), '/');
        $filename = sprintf(
            '%s/%s/%s/%s-%d.%s',
            $folder,
            $submission->Type,
            $submission->Reference,
            $field,
            $index + 1,
            $extension
        );
        // Draft only: API requests read the live stage, where a write would publish the file
        $file = Versioned::withVersionedMode(function () use ($content, $filename) {
            Versioned::set_stage(Versioned::DRAFT);
            $file = File::create();
            $file->setFromString($content, $filename);
            $file->write();
            return $file;
        });
        SubmissionFile::create([
            'SubmissionID' => $submission->ID,
            'FieldName' => $field,
            'OriginalName' => $name,
            'FileID' => $file->ID,
        ])->write();
    }

    /**
     * Summary of a submission (type option "summary", placeholders
     * {{path.to.field}}), e.g. the child's name in lists.
     *
     * @param array<string, mixed> $data
     */
    public function summary(IntakeType $type, array $data): string
    {
        $template = (string) $type->option('summary', '');
        $summary = $this->fill($template, fn (string $path) => $this->pathValue($data, $path));
        return trim((string) preg_replace('/\s+/', ' ', $summary));
    }

    /**
     * Notification mail to the office (type option "notify"); a failure is
     * logged and stored, the submission stays.
     */
    public function notify(Submission $submission): void
    {
        $type = $submission->intakeType();
        $notify = $type ? (array) $type->option('notify', []) : [];
        $to = array_values(array_filter(array_map('trim', is_array($notify['to'] ?? null)
            ? $notify['to']
            : explode(',', (string) ($notify['to'] ?? '')))));
        if (!$type || $to === []) {
            return;
        }
        try {
            $values = $this->notifyValues($submission, $type, (array) ($notify['fields'] ?? []));
            $subject = $this->fill(
                (string) ($notify['subject']
                    ?? _t(self::class . '.NOTIFY_SUBJECT', 'New submission {{Type}} {{Reference}}')),
                fn (string $key) => $values[$key] ?? ''
            );
            $block = isset($notify['block']) ? TextBlock::byCode((string) $notify['block']) : null;
            $body = $block ? (string) $block->Body : _t(
                self::class . '.NOTIFY_BODY',
                '<p>A new submission has arrived: {{Type}} {{Reference}}.</p>'
                . '<p><a href="{{Link}}">Open it in the inbox</a></p>'
            );
            $html = $this->fill($body, fn (string $key) => htmlspecialchars($values[$key] ?? '', ENT_QUOTES));
            $email = Email::create()->setTo($to)->setSubject($subject)->setBody($html);
            if (!empty($notify['from'])) {
                $email->setFrom((string) $notify['from']);
            }
            $replyTo = $type->replyToField() ? $submission->value((string) $type->replyToField()) : null;
            if (is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
                $email->setReplyTo($replyTo);
            }
            $this->extend('updateNotifyEmail', $email, $submission);
            $email->send();
            $submission->NotifiedAt = DBDatetime::now()->getValue();
            $submission->NotifyError = null;
        } catch (Throwable $e) {
            Injector::inst()->get(LoggerInterface::class)->error(
                'Flow intake: notification for ' . $submission->Reference . ' failed: ' . $e->getMessage()
            );
            $submission->NotifyError = mb_substr($e->getMessage(), 0, 255);
        }
        $submission->write();
    }

    /**
     * Placeholder values of the notification: Reference, Type, Summary, Link,
     * Date and Field.<path> for the fields the type lists in notify.fields.
     *
     * @param array<int, string> $fields
     * @return array<string, string>
     */
    private function notifyValues(Submission $submission, IntakeType $type, array $fields): array
    {
        $values = [
            'Reference' => (string) $submission->Reference,
            'Type' => $type->getTitle(),
            'Summary' => (string) $submission->Summary,
            'Link' => IntakeController::singleton()->absoluteRecordLink($submission),
            'Date' => date('d.m.Y H:i', (int) strtotime((string) $submission->Created)),
        ];
        foreach ($fields as $path) {
            $value = $submission->value((string) $path);
            $values['Field.' . $path] = is_scalar($value) ? (string) $value : '';
        }
        return $values;
    }

    /**
     * Creates a receipt link for the submitter (type option "receipt"), valid
     * receipt.hours hours; null when the type has no receipt.
     *
     * @return array{url: string, expires: string}|null
     */
    public function receiptLink(Submission $submission): ?array
    {
        $receipt = $submission->intakeType()?->option('receipt');
        if (!$receipt) {
            return null;
        }
        $hours = is_array($receipt) && isset($receipt['hours'])
            ? (int) $receipt['hours']
            : (int) static::config()->get('receipt_hours');
        $token = bin2hex(random_bytes(24));
        $expires = DBDatetime::now()->getTimestamp() + max(1, $hours) * 3600;
        $submission->ReceiptTokenHash = hash('sha256', $token);
        $submission->ReceiptExpires = date('Y-m-d H:i:s', $expires);
        $submission->write();
        return [
            'url' => Director::absoluteURL('flow/receipt/' . $token),
            'expires' => date('c', $expires),
        ];
    }

    /**
     * The submission of a valid receipt token, or null.
     */
    public function submissionByReceiptToken(string $token): ?Submission
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $submission = Submission::get()->find('ReceiptTokenHash', hash('sha256', $token));
        if (
            !$submission instanceof Submission
            || strtotime((string) $submission->ReceiptExpires) < DBDatetime::now()->getTimestamp()
        ) {
            return null;
        }
        return $submission;
    }

    /**
     * HTML of the receipt (template: type option receipt.template, default
     * Atwx\Flow\Intake\IntakeReceipt).
     */
    public function receiptHtml(Submission $submission): string
    {
        $type = $submission->intakeType();
        $receipt = $type ? $type->option('receipt') : null;
        $template = is_array($receipt) && !empty($receipt['template'])
            ? (string) $receipt['template']
            : 'Atwx\\Flow\\Intake\\IntakeReceipt';
        $block = is_array($receipt) && !empty($receipt['block']) ? TextBlock::byCode((string) $receipt['block']) : null;
        $files = [];
        foreach ($submission->Files() as $link) {
            $files[] = ArrayData::create(['Name' => $link->OriginalName]);
        }
        $data = ArrayData::create([
            'Submission' => $submission,
            'Title' => $type ? $type->getTitle() : $submission->Type,
            'Reference' => $submission->Reference,
            'Date' => date('d.m.Y H:i', (int) strtotime((string) $submission->Created)),
            'Intro' => $block ? $block->Body : '',
            'Rows' => $this->rowList($type ? $type->rows($submission->values()) : []),
            'Files' => ArrayList::create($files),
        ]);
        $this->extend('updateReceiptData', $data, $submission);
        return (string) SSViewer::create([$template])->process($data);
    }

    public function receiptPdf(Submission $submission): string
    {
        return Injector::inst()->get(PdfRenderer::class)->render(
            $this->receiptHtml($submission),
            (string) $submission->Reference
        );
    }

    /**
     * Rows of IntakeType::rows() as nested ArrayLists for templates.
     *
     * @param array<int, array{title: string, value: string, rows: array<int, mixed>, path: string}> $rows
     * @return ArrayList<ArrayData>
     */
    public function rowList(array $rows): ArrayList
    {
        $list = ArrayList::create();
        foreach ($rows as $row) {
            $list->push(ArrayData::create([
                'Title' => $row['title'],
                'Value' => $row['value'],
                'Path' => $row['path'],
                'Rows' => $this->rowList($row['rows']),
            ]));
        }
        return $list;
    }

    /**
     * Submissions of one form type as CSV (semicolons, UTF-8 with BOM, for
     * Excel): reference, received, status, one column per field (groups as
     * "Group: Field", repeats numbered "Parent 1: Name" up to the most entries
     * among the submissions) and the file names. Values that a spreadsheet
     * would read as a formula are prefixed with an apostrophe.
     *
     * @param iterable<Submission> $submissions
     */
    public function csv(IntakeType $type, iterable $submissions): string
    {
        $list = [];
        foreach ($submissions as $submission) {
            $list[] = $submission;
        }
        $columns = $this->csvColumns($type->fields(), array_map(fn (Submission $s) => $s->values(), $list), '');
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        $header = [
            _t(Submission::class . '.db_Reference', 'Reference'),
            _t(Submission::class . '.db_Created', 'Received'),
            _t(Submission::class . '.db_Status', 'Status'),
        ];
        foreach ($columns as [$label]) {
            $header[] = $label;
        }
        $header[] = _t(IntakeController::class . '.FILES', 'Files');
        fputcsv($out, $header, ';', '"', '');
        foreach ($list as $submission) {
            $values = $submission->values();
            $row = [
                (string) $submission->Reference,
                date('d.m.Y H:i', (int) strtotime((string) $submission->Created)),
                $submission->getStatusTitle(),
            ];
            foreach ($columns as [, $definition, $path]) {
                $display = IntakeType::display($definition, $this->pathRaw($values, $path));
                $row[] = $display === '–' ? '' : $display;
            }
            $row[] = implode(', ', $submission->Files()->column('OriginalName'));
            fputcsv($out, array_map([$this, 'csvSafe'], $row), ';', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        return "\u{FEFF}" . $csv;
    }

    /**
     * Flat CSV columns: [label, field definition, data path].
     *
     * @param array<string, array<string, mixed>> $fields
     * @param array<int, array<string, mixed>> $rows Data of the submissions (to count repeats)
     * @return array<int, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    private function csvColumns(array $fields, array $rows, string $prefix, string $labelPrefix = ''): array
    {
        $columns = [];
        foreach ($fields as $name => $definition) {
            $title = $labelPrefix . (string) ($definition['title'] ?? $name);
            $type = (string) ($definition['type'] ?? 'string');
            $sub = IntakeType::normalizeFields((array) ($definition['fields'] ?? []));
            if ($type === 'group') {
                $inner = array_map(fn ($row) => is_array($row[$name] ?? null) ? $row[$name] : [], $rows);
                array_push($columns, ...$this->csvColumns($sub, $inner, $prefix . $name . '.', $title . ': '));
                continue;
            }
            if ($type === 'repeat') {
                $count = 1;
                foreach ($rows as $row) {
                    $count = max($count, is_array($row[$name] ?? null) ? count($row[$name]) : 0);
                }
                for ($i = 0; $i < $count; $i++) {
                    $inner = array_map(
                        fn ($row) => is_array($row[$name][$i] ?? null) ? $row[$name][$i] : [],
                        $rows
                    );
                    $path = $prefix . $name . '.' . $i . '.';
                    array_push($columns, ...$this->csvColumns($sub, $inner, $path, $title . ' ' . ($i + 1) . ': '));
                }
                continue;
            }
            $columns[] = [$title, $definition, $prefix . $name];
        }
        return $columns;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function pathRaw(array $data, string $path): mixed
    {
        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    private function csvSafe(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $value : $value;
    }

    public function takeoverHandler(Submission $submission): ?TakeoverHandler
    {
        $class = $submission->intakeType()?->option('takeover');
        if (!$class) {
            return null;
        }
        $handler = Injector::inst()->get((string) $class);
        return $handler instanceof TakeoverHandler ? $handler : null;
    }

    /**
     * Takes the submission over with the project's handler (in a transaction)
     * and marks it as taken over, linked to the records.
     */
    public function takeOver(Submission $submission, ?string $choice, Member $member): void
    {
        if ($submission->Status === Submission::TAKEN_OVER) {
            throw new TakeoverException(_t(self::class . '.ALREADY', 'This submission has already been taken over.'));
        }
        $handler = $this->takeoverHandler($submission);
        if (!$handler) {
            throw new TakeoverException(_t(self::class . '.NO_HANDLER', 'This form type has no takeover.'));
        }
        DB::get_conn()->withTransaction(function () use ($submission, $choice, $member, $handler) {
            $records = $handler->takeOver($submission, $choice, $member);
            $submission->setStatus(Submission::TAKEN_OVER);
            $submission->TakenOverByID = $member->ID;
            $submission->setTakeoverRecords($records);
            $submission->write();
        });
        $this->extend('onAfterTakeOver', $submission, $member);
    }

    public function discard(Submission $submission): void
    {
        $submission->setStatus(Submission::DISCARDED);
        $submission->write();
    }

    /**
     * Deletes submissions (with their files) whose retention has passed:
     * per type and status, days after the last status change.
     */
    public function applyRetention(): int
    {
        $deleted = 0;
        $now = DBDatetime::now()->getTimestamp();
        foreach ($this->types() as $type) {
            foreach ([Submission::NEW, Submission::TAKEN_OVER, Submission::DISCARDED] as $status) {
                $days = $type->retentionDays($status);
                if ($days <= 0) {
                    continue;
                }
                $before = date('Y-m-d H:i:s', $now - $days * 86400);
                $old = Submission::get()->filter([
                    'Type' => $type->getName(),
                    'Status' => $status,
                    'StatusChanged:LessThan' => $before,
                ]);
                foreach ($old as $submission) {
                    $submission->delete();
                    $deleted++;
                }
            }
        }
        return $deleted;
    }

    /**
     * Replaces {{key}} by $value(key).
     *
     * @param callable(string): string $value
     */
    private function fill(string $template, callable $value): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/',
            fn (array $match) => $value($match[1]),
            $template
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function pathValue(array $data, string $path): string
    {
        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return '';
            }
            $value = $value[$key];
        }
        return is_scalar($value) ? (string) $value : '';
    }
}
