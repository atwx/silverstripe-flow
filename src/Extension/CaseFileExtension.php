<?php

namespace Atwx\Flow\Extension;

use Atwx\Flow\Actor\AccessLinkActor;
use Atwx\Flow\Actor\FlowActor;
use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Model\AccessLink;
use Atwx\Flow\Model\Assignment;
use Atwx\Flow\Model\AssignmentChange;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\CaseMail;
use Atwx\Flow\Model\ChecklistEntry;
use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\FollowUp;
use Atwx\Flow\Model\Note;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Transition;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Service\FieldAccess;
use Atwx\Flow\Service\TransitionException;
use Atwx\Flow\Service\TransitionService;
use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Model\List\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;

/**
 * Turns a domain class into a case file: it gets a status within the workflow
 * configured for its class, and a status history.
 *
 *   App\Models\Event:
 *     extensions:
 *       - Atwx\Flow\Extension\CaseFileExtension
 *
 * The status is only ever changed through {@see TransitionService}; writing
 * FlowStatusID directly is ignored. New records start in the workflow's
 * initial status.
 *
 * @property int $FlowStatusID
 * @property string $FlowData JSON object of custom field values, keyed by code
 * @method Status FlowStatus()
 * @method HasManyList<StatusChange> FlowStatusChanges()
 * @method HasManyList<Note> FlowNotes()
 * @method HasManyList<AccessLink> FlowAccessLinks()
 * @method HasManyList<Assignment> FlowAssignments()
 * @method HasManyList<AssignmentChange> FlowAssignmentChanges()
 * @method HasManyList<CaseDocument> FlowDocuments()
 * @method HasManyList<FollowUp> FlowFollowUps()
 * @method HasManyList<ChecklistEntry> FlowChecklist()
 * @method HasManyList<CaseMail> FlowMails()
 * @extends Extension<DataObject&static>
 */
class CaseFileExtension extends Extension
{
    private static array $db = [
        'FlowData' => 'Text',
    ];

    private static array $has_one = [
        'FlowStatus' => Status::class,
    ];

    private static array $has_many = [
        'FlowStatusChanges' => StatusChange::class . '.Case',
        'FlowNotes' => Note::class . '.Case',
        'FlowAccessLinks' => AccessLink::class . '.Case',
        'FlowAssignments' => Assignment::class . '.Case',
        'FlowAssignmentChanges' => AssignmentChange::class . '.Case',
        'FlowDocuments' => CaseDocument::class . '.Case',
        'FlowFollowUps' => FollowUp::class . '.Case',
        'FlowChecklist' => ChecklistEntry::class . '.Case',
        'FlowMails' => CaseMail::class . '.Case',
    ];

    /**
     * History and notes go with the case file.
     */
    private static array $cascade_deletes = [
        'FlowStatusChanges',
        'FlowNotes',
        'FlowAccessLinks',
        'FlowAssignments',
        'FlowAssignmentChanges',
        'FlowDocuments',
        'FlowFollowUps',
        'FlowChecklist',
        'FlowMails',
    ];

    /**
     * Records currently written by the transition service (by object hash).
     *
     * @internal Runtime state, not configuration.
     * @var array<string, true>
     */
    private static array $authorisedWrites = [];

    /**
     * Whether the record was new before the current write.
     */
    private bool $wasNew = false;

    /**
     * Write the record with the given status, bypassing the workflow rules.
     *
     * Used by the transition service. Seeds and imports may use it to create
     * case files in a given status; everything else uses transitionTo().
     *
     * @internal
     */
    public static function writeStatus(DataObject $case, int $statusID): void
    {
        $key = spl_object_hash($case);
        self::$authorisedWrites[$key] = true;
        try {
            $case->setField('FlowStatusID', $statusID);
            $case->write();
        } finally {
            unset(self::$authorisedWrites[$key]);
        }
    }

    /**
     * The member responsible for the case file in the responsibility with this code.
     */
    public function flowAssignee(string $code): ?\SilverStripe\Security\Member
    {
        $assignment = $this->getOwner()->FlowAssignments()->filter('Responsibility.Code', $code)->first();
        $member = $assignment?->Member();
        return $member && $member->exists() ? $member : null;
    }

    /**
     * Custom field values, keyed by code.
     *
     * @return array<string, mixed>
     */
    public function flowData(): array
    {
        $data = json_decode((string) $this->getOwner()->getField('FlowData'), true);
        return is_array($data) ? $data : [];
    }

    public function flowValue(string $code): mixed
    {
        return $this->flowData()[$code] ?? null;
    }

    /**
     * Set a custom field value (stored on the next write).
     */
    public function setFlowValue(string $code, mixed $value): void
    {
        $data = $this->flowData();
        if ($value === null) {
            unset($data[$code]);
        } else {
            $data[$code] = $value;
        }
        $this->getOwner()->setField(
            'FlowData',
            $data === [] ? null : json_encode($data, JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Custom fields of the workflow of this case file.
     *
     * @return array<int, CustomField>
     */
    public function flowCustomFields(): array
    {
        $workflow = $this->flowWorkflow();
        return $workflow ? CustomField::get()->filter('WorkflowID', $workflow->ID)->toArray() : [];
    }

    /**
     * Take submitted custom field values ("Flow_<code>", e.g. from a form) into
     * FlowData. Values reset to null (fields that are not writable) are left alone.
     */
    private function collectFlowValues(): void
    {
        $owner = $this->getOwner();
        foreach ($this->flowCustomFields() as $custom) {
            $name = $custom->formFieldName();
            $changed = $owner->getChangedFields(false, DataObject::CHANGE_VALUE);
            if (!array_key_exists($name, $changed)) {
                continue;
            }
            $value = $owner->getField($name);
            if ($value === null) {
                continue;
            }
            $this->setFlowValue((string) $custom->Code, $custom->normalise($value));
        }
    }

    public function flowWorkflow(): ?Workflow
    {
        return Workflow::forClass($this->getOwner()->ClassName);
    }

    /**
     * Transitions the actor (default: the current member) may perform now.
     *
     * @return ArrayList<Transition>
     */
    public function availableTransitions(?FlowActor $actor = null): ArrayList
    {
        $actor ??= MemberActor::current();
        if (!$actor) {
            return ArrayList::create();
        }
        return TransitionService::singleton()->availableTransitions($this->getOwner(), $actor);
    }

    /**
     * Change the status, see {@see TransitionService::transition()}.
     */
    public function transitionTo(Status|string $target, ?FlowActor $actor = null, string $comment = ''): StatusChange
    {
        $actor ??= MemberActor::current();
        if (!$actor) {
            throw new TransitionException(
                _t(TransitionService::class . '.NO_ACTOR', 'Please log in.')
            );
        }
        return TransitionService::singleton()->transition($this->getOwner(), $target, $actor, $comment);
    }

    /**
     * Whether the actor may add notes: anyone who may change at least one field
     * of the case file in its current status.
     */
    public function canAddFlowNote(?FlowActor $actor = null): bool
    {
        $actor ??= MemberActor::current();
        $owner = $this->getOwner();
        return $actor !== null
            && $owner->isInDB()
            && FieldAccess::singleton()->writableFields($owner, $actor) !== [];
    }

    /**
     * Add a note to the timeline.
     *
     * @throws TransitionException if the actor may not add notes or the text is empty
     */
    public function addFlowNote(string $text, ?FlowActor $actor = null): Note
    {
        $actor ??= MemberActor::current();
        $text = trim($text);
        if ($text === '') {
            throw new TransitionException(_t(self::class . '.NOTE_EMPTY', 'Please enter a text.'));
        }
        if (!$actor || !$this->canAddFlowNote($actor)) {
            throw new TransitionException(_t(self::class . '.NOTE_NOT_ALLOWED', 'You may not add notes here.'));
        }
        $owner = $this->getOwner();
        $note = Note::create([
            'Text' => $text,
            'MemberID' => (int) $actor->flowMember()?->ID,
            'AccessLinkID' => $actor instanceof AccessLinkActor ? $actor->getLink()->ID : 0,
        ]);
        $note->setField('CaseID', $owner->ID);
        $note->setField('CaseClass', $owner->ClassName);
        $note->write();
        $owner->invokeWithExtensions('onAfterFlowNote', $note, $actor);
        return $note;
    }

    protected function onBeforeWrite(): void
    {
        $owner = $this->getOwner();
        $this->wasNew = !$owner->isInDB();
        $this->collectFlowValues();

        $authorised = isset(self::$authorisedWrites[spl_object_hash($owner)]);

        if ($this->wasNew) {
            if ($authorised) {
                // Created with an explicit status (e.g. seeding test data).
                return;
            }
            // New case files always start in the initial status of their workflow.
            $workflow = $this->flowWorkflow();
            $owner->setField('FlowStatusID', $workflow ? (int) $workflow->InitialStatusID : 0);
            return;
        }

        if (!$authorised && $owner->isChanged('FlowStatusID')) {
            // Status changes go through the transition service only.
            $owner->setField('FlowStatusID', (int) $owner->getChangedFields()['FlowStatusID']['before']);
        }
    }

    protected function onAfterWrite(): void
    {
        if ($this->wasNew) {
            $this->wasNew = false;
            $owner = $this->getOwner();
            if ($owner->getField('FlowStatusID')) {
                TransitionService::singleton()->record(
                    $owner,
                    0,
                    (int) $owner->getField('FlowStatusID'),
                    MemberActor::current()
                );
            }
        }
    }

    /**
     * @param array<string, string> $labels
     */
    protected function updateFieldLabels(array &$labels): void
    {
        $labels['FlowStatus'] = _t(self::class . '.has_one_FlowStatus', 'Status');
        $labels['FlowStatusID'] = $labels['FlowStatus'];
        $labels['FlowStatusChanges'] = _t(self::class . '.has_many_FlowStatusChanges', 'Status history');
        $labels['FlowNotes'] = _t(self::class . '.has_many_FlowNotes', 'Notes');
        $labels['FlowAccessLinks'] = _t(self::class . '.has_many_FlowAccessLinks', 'Edit links');
    }

    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName([
            'FlowStatusID', 'FlowStatus', 'FlowStatusChanges', 'FlowNotes', 'FlowAccessLinks', 'FlowData',
        ]);
    }
}
