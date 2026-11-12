<?php

namespace Atwx\Flow\Controller;

use Atwx\Flow\Model\ChecklistEntry;
use Atwx\Flow\Service\ChecklistService;
use Atwx\SilverstripeFrontdeskKit\Controller\FrontdeskSubController;
use Atwx\SilverstripeFrontdeskKit\Table\ColumnCollection;
use Atwx\SilverstripeFrontdeskKit\Table\RowAction;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\ReadonlyField;
use SilverStripe\ORM\DataList;
use SilverStripe\ORM\DataObject;

/**
 * Checklist of a case file: one row per item of the workflow; state, date and
 * comment are edited, items are configured in the workflow.
 */
class ChecklistSubController extends FrontdeskSubController
{
    private static string $managed_model = ChecklistEntry::class;

    private static string $url_segment = 'checklist';

    private static string $title = 'Checkliste';

    private static bool $allow_add = false;

    /**
     * @return DataList<ChecklistEntry>
     */
    protected function getBaseQuery(): DataList
    {
        $parent = $this->parentController;
        $case = $parent instanceof FlowCaseController ? $parent->findCaseFile($this->getParentID()) : null;
        if ($case) {
            ChecklistService::singleton()->ensureEntries($case);
        }
        // PHPStan reports static<T> from DataList::filter() as incompatible with DataList<T>.
        // @phpstan-ignore return.type
        return ChecklistEntry::get()
            ->filter([
                'CaseClass' => $parent?->getManagedModel(),
                'CaseID' => $case ? $this->getParentID() : 0,
            ])
            ->sort(['Item.Sort' => 'ASC', 'ID' => 'ASC']);
    }

    protected function defineColumns(): ColumnCollection
    {
        $labels = ChecklistEntry::singleton()->fieldLabels();
        return ColumnCollection::create()
            ->make('Item.Title', $labels['Item.Title'])->end()
            ->make('StateLabel', $labels['State'])
                ->format(fn ($value, DataObject $record) => $this->stateBadge($record))
                ->type('html')
                ->end()
            ->make('Date', $labels['Date'])
                ->format(fn ($value) => $value ? date('d.m.Y', (int) strtotime((string) $value)) : '')
                ->end()
            ->make('Comment', $labels['Comment'])->end();
    }

    private function stateBadge(DataObject $record): string
    {
        if (!$record instanceof ChecklistEntry) {
            return '';
        }
        $class = match ((string) $record->State) {
            'done' => 'badge-success',
            'not_needed' => 'badge-ghost',
            'requested' => 'badge-warning',
            default => 'badge-outline',
        };
        return '<span class="badge ' . $class . '">' . htmlspecialchars($record->getStateLabel()) . '</span>';
    }

    protected function formFields(FieldList $fields): FieldList
    {
        $fields = parent::formFields($fields);
        $fields->removeByName(['CaseID', 'CaseClass', 'Case', 'ChangedByID']);
        $fields->replaceField(
            'ItemID',
            ReadonlyField::create('ItemTitle', ChecklistEntry::singleton()->fieldLabel('Item'))
        );
        $fields->replaceField('State', DropdownField::create(
            'State',
            ChecklistEntry::singleton()->fieldLabel('State'),
            ChecklistEntry::stateOptions()
        ));
        return $fields;
    }

    /**
     * Rows can be edited, not deleted.
     *
     * @return array<int, RowAction>
     */
    protected function defineRowActions(DataObject $record): array
    {
        return $this->canEdit() ? [RowAction::edit($this->Link('edit/' . $record->ID))] : [];
    }

    /**
     * Entries belong to the configured items and are never deleted here.
     */
    public function delete(HTTPRequest $request): HTTPResponse
    {
        return $this->httpError(403);
    }
}
