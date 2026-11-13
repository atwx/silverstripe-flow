<?php

namespace Atwx\Flow\Intake;

use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskController;
use Atwx\SilverstripeFrontdeskKit\Filter\FilterCollection;
use Atwx\SilverstripeFrontdeskKit\Filter\MultiSelectFilter;
use Atwx\SilverstripeFrontdeskKit\Filter\SelectFilter;
use Atwx\SilverstripeFrontdeskKit\Filter\TextFilter;
use Atwx\SilverstripeFrontdeskKit\Table\ActionException;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\RecordAction;
use SilverStripe\Assets\File;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPStreamResponse;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\EmailField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HeaderField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\TextareaField;
use SilverStripe\Forms\TextField;
use SilverStripe\Model\ArrayData;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Security\Security;
use SilverStripe\Versioned\Versioned;

/**
 * The inbox: submissions of all form types with their status, the data by its
 * form type, files, correcting, taking over and discarding. Route it in the
 * project ('intake//$Action/$ID/$OtherID': Atwx\Flow\Intake\IntakeController)
 * and grant FDK_INTAKE_VIEW / FDK_INTAKE_EDIT.
 */
class IntakeController extends FrontdeskController
{
    private static string $managed_model = Submission::class;

    private static string $url_segment = 'intake';

    private static string $title = 'Eingang';

    private static string $permission_code = 'FDK_INTAKE';

    private static bool $check_record_permissions = true;

    private static bool $allow_add = false;

    private static array $allowed_actions = [
        'download',
        'csv',
    ];

    public function absoluteRecordLink(Submission $submission): string
    {
        return Director::absoluteURL($this->Link('view/' . $submission->ID));
    }

    private function typeTitle(Submission $submission): string
    {
        $type = $submission->intakeType();
        return $type ? $type->getTitle() : (string) $submission->Type;
    }

    protected function defineColumns(): ColumnCollection
    {
        return ColumnCollection::create()
            ->make('Reference', _t(Submission::class . '.db_Reference', 'Reference'))
                ->link('view/{ID}')->sortable()->end()
            ->make('Created.Nice', _t(Submission::class . '.db_Created', 'Received'))->sortable('Created')->end()
            ->make('TypeTitle', _t(Submission::class . '.db_Type', 'Form'))
                ->format(fn ($value, Submission $submission) => $this->typeTitle($submission))
                ->end()
            ->make('Summary', _t(Submission::class . '.db_Summary', 'Summary'))->end()
            ->make('StatusTitle', _t(Submission::class . '.db_Status', 'Status'))->sortable('Status')->end();
    }

    protected function defineFilters(): FilterCollection
    {
        $types = [];
        foreach (IntakeService::singleton()->types() as $name => $type) {
            $types[$name] = $type->getTitle();
        }
        return FilterCollection::create()
            ->add(
                TextFilter::create('Query', _t(self::class . '.SEARCH', 'Search'))
                    ->apply(fn ($list, $value) => $list->filterAny([
                        'Reference:PartialMatch' => $value,
                        'Summary:PartialMatch' => $value,
                    ]))
            )
            ->add(
                SelectFilter::create('Type', _t(Submission::class . '.db_Type', 'Form'))
                    ->options($types)
                    ->apply(fn ($list, $value) => $list->filter('Type', $value))
            )
            ->add(
                MultiSelectFilter::create('Status', _t(Submission::class . '.db_Status', 'Status'))
                    ->options([
                        Submission::NEW => _t(Submission::class . '.STATUS_NEW', 'New'),
                        Submission::TAKEN_OVER => _t(Submission::class . '.STATUS_TAKEN_OVER', 'Taken over'),
                        Submission::DISCARDED => _t(Submission::class . '.STATUS_DISCARDED', 'Discarded'),
                    ])
                    ->field('Status')
            );
    }

    protected function defineViewContent(DataObject $record): DBHTMLText
    {
        /** @var Submission $record */
        $service = IntakeService::singleton();
        $type = $record->intakeType();
        $files = [];
        foreach ($record->Files() as $link) {
            $files[] = ArrayData::create([
                'Name' => $link->OriginalName,
                'FieldTitle' => $type?->files()[(string) $link->FieldName]['title'] ?? $link->FieldName,
                'Link' => $this->Link('download/' . $record->ID . '/' . $link->ID),
            ]);
        }
        $links = [];
        foreach ($record->takeoverLinks() as $link) {
            $target = class_exists($link['class']) ? DataObject::get($link['class'])->byID($link['id']) : null;
            $url = $target && $target->hasMethod('Link') ? (string) $target->Link() : '';
            $this->extend('updateTakeoverLink', $url, $target, $link);
            $links[] = ArrayData::create(['Title' => $link['title'], 'Link' => $url]);
        }
        return $this->customise(ArrayData::create([
            'Submission' => $record,
            'TypeTitle' => $type ? $type->getTitle() : $record->Type,
            'Rows' => $service->rowList($type ? $type->rows($record->values()) : []),
            'Files' => ArrayList::create($files),
            'TakeoverRecords' => ArrayList::create($links),
        ]))->renderWith('Atwx\\Flow\\Intake\\Includes\\IntakeSubmission');
    }

    /**
     * @return array<int, RecordAction>
     */
    protected function defineRecordActions(DataObject $record): array
    {
        /** @var Submission $record */
        $member = Security::getCurrentUser();
        if (!$member || !$record->canEdit($member)) {
            return [];
        }
        $service = IntakeService::singleton();
        $actions = [];
        $handler = $service->takeoverHandler($record);
        if ($handler && $record->Status === Submission::NEW) {
            $action = RecordAction::create('takeover', _t(self::class . '.TAKE_OVER', 'Take over'))
                ->submitLabel(_t(self::class . '.TAKE_OVER', 'Take over'))
                ->handle(function (DataObject $submission, ?string $choice) use ($service, $member) {
                    /** @var Submission $submission */
                    try {
                        $service->takeOver($submission, $choice, $member);
                    } catch (TakeoverException $e) {
                        throw new ActionException($e->getMessage());
                    }
                    return _t(self::class . '.TAKEN_OVER', 'The submission has been taken over.');
                });
            $choices = $handler->choices($record);
            if ($choices !== []) {
                $action->options($choices);
            }
            $actions[] = $action;
        }
        if ($record->Status === Submission::NEW) {
            $actions[] = RecordAction::create('discard', _t(self::class . '.DISCARD', 'Discard'))
                ->description(_t(
                    self::class . '.DISCARD_HELP',
                    'The submission is not taken over and deleted after the retention period.'
                ))
                ->handle(function (DataObject $submission) use ($service) {
                    /** @var Submission $submission */
                    $service->discard($submission);
                    return _t(self::class . '.DISCARDED', 'The submission has been discarded.');
                });
        } elseif ($record->Status === Submission::DISCARDED) {
            $actions[] = RecordAction::create('reopen', _t(self::class . '.REOPEN', 'Back to new'))
                ->handle(function (DataObject $submission) {
                    /** @var Submission $submission */
                    $submission->setStatus(Submission::NEW);
                    $submission->write();
                    return null;
                });
        }
        return $actions;
    }

    /**
     * "CSV" in the list, once a single form type is filtered.
     *
     * @return ArrayList<ArrayData>
     */
    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- template method of the kit
    public function Actions(): ArrayList
    {
        $actions = parent::Actions();
        $type = IntakeService::singleton()->type((string) $this->getRequest()->getVar('Type'));
        if ($type) {
            $query = $this->CurrentQuery();
            $actions->push(ArrayData::create([
                'Title' => _t(self::class . '.CSV', 'CSV export'),
                'Primary' => false,
                'Link' => $this->Link('csv') . '?' . $query,
                'Target' => '_blank',
                'KeepQuery' => true,
                'ExtraQuery' => '',
            ]));
        }
        return $actions;
    }

    /**
     * @return ArrayList<ArrayData>
     */
    protected function defineViewActions(DataObject $record): ArrayList
    {
        $actions = parent::defineViewActions($record);
        $actions->push(ArrayData::create([
            'Title' => _t(self::class . '.CSV', 'CSV export'),
            'Link' => $this->Link('csv/' . $record->ID),
            'Primary' => false,
            'Active' => false,
        ]));
        return $actions;
    }

    /**
     * csv/<ID>: one submission; csv?Type=<type>&…: the filtered list of a form
     * type (columns from the type's fields).
     */
    public function csv(HTTPRequest $request): HTTPResponse
    {
        $service = IntakeService::singleton();
        $id = (int) $request->param('ID');
        if ($id) {
            $submission = $this->findRecord($id);
            $type = $submission instanceof Submission ? $submission->intakeType() : null;
            if (!$submission instanceof Submission || !$type) {
                return $this->httpError(404);
            }
            $submissions = [$submission];
            $name = (string) $submission->Reference;
        } else {
            $type = $service->type((string) $request->getVar('Type'));
            if (!$type) {
                return $this->httpError(400, 'Choose a form type');
            }
            $submissions = [];
            foreach ($this->getQuery()->filter('Type', $type->getName()) as $submission) {
                if ($submission instanceof Submission && $submission->canView()) {
                    $submissions[] = $submission;
                }
            }
            $name = $type->getName() . '-' . date('Y-m-d');
        }
        $response = HTTPResponse::create($service->csv($type, $submissions));
        $response->addHeader('Content-Type', 'text/csv; charset=utf-8');
        $response->addHeader('Content-Disposition', 'attachment; filename="' . $name . '.csv"');
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    /**
     * A file of a submission (protected, only through here).
     */
    public function download(HTTPRequest $request): HTTPResponse
    {
        $submission = $this->findRecord((int) $request->param('ID'));
        $link = $submission instanceof Submission
            ? $submission->Files()->byID((int) $request->param('OtherID'))
            : null;
        $file = $link ? Versioned::withVersionedMode(function () use ($link) {
            Versioned::set_stage(Versioned::DRAFT);
            return File::get()->byID($link->FileID);
        }) : null;
        $stream = $file instanceof File ? $file->getStream() : null;
        if (!$link || !$file instanceof File || !is_resource($stream)) {
            return $this->httpError(404);
        }
        $response = HTTPStreamResponse::create($stream, (int) $file->getAbsoluteSize());
        $response->addHeader('Content-Type', $file->getMimeType() ?: 'application/octet-stream');
        $name = (string) ($link->OriginalName ?: basename((string) $file->getFilename()));
        $response->addHeader('Content-Disposition', 'attachment; filename="' . addcslashes($name, '"\\') . '"');
        $response->addHeader('Cache-Control', 'private, no-store');
        return $response;
    }

    /**
     * The edit form shows the data fields of the form type.
     */
    protected function formFields(FieldList $fields): FieldList
    {
        $submission = $this->currentRecordID()
            ? Submission::get()->byID((int) $this->currentRecordID())
            : null;
        $type = $submission?->intakeType();
        $result = FieldList::create();
        if ($submission && $type) {
            foreach ($this->dataFields($type->fields(), $submission->values(), []) as $field) {
                $result->push($field);
            }
        }
        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<string, mixed> $data
     * @param array<int, string> $path
     * @return array<int, FormField>
     */
    private function dataFields(array $definitions, array $data, array $path): array
    {
        $fields = [];
        foreach ($definitions as $name => $definition) {
            $title = (string) ($definition['title'] ?? $name);
            $type = (string) ($definition['type'] ?? 'string');
            $value = $data[$name] ?? null;
            $here = array_merge($path, [$name]);
            $fieldName = Submission::formFieldName(implode('.', $here));
            if ($type === 'group') {
                $fields[] = HeaderField::create($fieldName . '_Header', $title, 4);
                $sub = IntakeType::normalizeFields((array) ($definition['fields'] ?? []));
                array_push($fields, ...$this->dataFields($sub, is_array($value) ? $value : [], $here));
                continue;
            }
            if ($type === 'repeat') {
                $sub = IntakeType::normalizeFields((array) ($definition['fields'] ?? []));
                foreach (is_array($value) ? array_values($value) : [] as $i => $item) {
                    $fields[] = HeaderField::create($fieldName . '_' . $i . '_Header', $title . ' ' . ($i + 1), 4);
                    array_push($fields, ...$this->dataFields(
                        $sub,
                        is_array($item) ? $item : [],
                        array_merge($here, [(string) $i])
                    ));
                }
                continue;
            }
            $field = match ($type) {
                'text' => TextareaField::create($fieldName, $title),
                'email' => EmailField::create($fieldName, $title),
                'date' => DateField::create($fieldName, $title),
                'integer', 'number' => NumericField::create($fieldName, $title)->setScale($type === 'integer' ? 0 : 2),
                'boolean' => CheckboxField::create($fieldName, $title),
                'choice' => !empty($definition['multiple'])
                    ? CheckboxSetField::create($fieldName, $title, IntakeType::options($definition))
                    : DropdownField::create($fieldName, $title, IntakeType::options($definition))->setEmptyString('–'),
                'list' => TextareaField::create($fieldName, $title)
                    ->setDescription(_t(self::class . '.ONE_PER_LINE', 'One per line')),
                default => TextField::create($fieldName, $title),
            };
            $field->setValue($type === 'list' && is_array($value) ? implode("\n", $value) : $value);
            $fields[] = $field;
        }
        return $fields;
    }

    /**
     * Turns the posted data fields back into typed data.
     *
     * @param array<string, mixed> $data
     */
    protected function beforeWrite(DataObject $item, array $data): void
    {
        /** @var Submission $item */
        $type = $item->intakeType();
        if (!$type) {
            return;
        }
        $posted = is_array($data['IntakeData'] ?? null) ? $data['IntakeData'] : [];
        foreach (array_keys($item->toMap()) as $key) {
            if (str_starts_with((string) $key, 'IntakeData[')) {
                $item->setField((string) $key, null);
            }
        }
        $item->storeValues($this->typed($type->fields(), $posted, $item->values()));
    }

    /**
     * @param array<string, array<string, mixed>> $definitions
     * @param array<mixed> $posted
     * @param array<mixed> $current
     * @return array<string, mixed>
     */
    private function typed(array $definitions, array $posted, array $current): array
    {
        $data = [];
        foreach ($definitions as $name => $definition) {
            $type = (string) ($definition['type'] ?? 'string');
            $value = $posted[$name] ?? null;
            $sub = IntakeType::normalizeFields((array) ($definition['fields'] ?? []));
            $data[$name] = match ($type) {
                'group' => $this->typed($sub, is_array($value) ? $value : [], (array) ($current[$name] ?? [])),
                'repeat' => array_map(
                    fn ($i) => $this->typed($sub, (array) (is_array($value) ? ($value[$i] ?? []) : []), []),
                    array_keys(is_array($current[$name] ?? null) ? $current[$name] : [])
                ),
                'boolean' => !empty($value),
                'integer' => is_numeric($value) ? (int) $value : ($value === '' || $value === null ? null : $value),
                'number' => is_numeric($value) ? (float) $value : ($value === '' || $value === null ? null : $value),
                'list' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $value) ?: []))),
                'choice' => !empty($definition['multiple'])
                    ? array_values(array_filter((array) $value, fn ($one) => $one !== ''))
                    : ($value === '' ? null : $value),
                default => is_string($value) ? trim($value) : $value,
            };
        }
        return $data;
    }
}
