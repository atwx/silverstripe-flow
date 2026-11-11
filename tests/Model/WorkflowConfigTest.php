<?php

namespace Atwx\Flow\Tests\Model;

use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Phase;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\Transition;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestTicket;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;

class WorkflowConfigTest extends SapphireTest
{
    protected static $fixture_file = 'WorkflowConfigTest.yml';

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
        TestTicket::class,
    ];

    private function workflowRecord(string $key = 'course'): Workflow
    {
        return $this->objFromFixture(Workflow::class, $key);
    }

    private function statusRecord(string $key): Status
    {
        return $this->objFromFixture(Status::class, $key);
    }

    private function roleRecord(string $key): FlowRole
    {
        return $this->objFromFixture(FlowRole::class, $key);
    }

    /**
     * @return array<int, string>
     */
    private function errorFields(ValidationResult $result): array
    {
        return array_values(array_filter(array_column($result->getMessages(), 'fieldName')));
    }

    public function testFixtureIsValid(): void
    {
        foreach ([Workflow::class, Phase::class, Status::class, FlowRole::class, Transition::class] as $class) {
            foreach ($class::get() as $record) {
                $this->assertTrue($record->validate()->isValid(), "{$class} #{$record->ID} is valid");
            }
        }
    }

    public function testCodeFormatIsValidated(): void
    {
        foreach (['', 'Draft', '1st', 'in-review', 'in review'] as $code) {
            $status = $this->statusRecord('draft');
            $status->Code = $code;
            $this->assertContains('Code', $this->errorFields($status->validate()), "Code '{$code}' is rejected");
        }
        $status = $this->statusRecord('draft');
        $status->Code = 'in_review_2';
        $this->assertTrue($status->validate()->isValid());
    }

    public function testCodeIsUniqueWithinWorkflowOnly(): void
    {
        $status = Status::create([
            'Code' => 'draft',
            'Title' => 'Entwurf 2',
            'WorkflowID' => $this->workflowRecord()->ID,
            'PhaseID' => $this->objFromFixture(Phase::class, 'planning')->ID,
        ]);
        $this->assertContains('Code', $this->errorFields($status->validate()));

        $other = Status::create([
            'Code' => 'draft',
            'Title' => 'Entwurf',
            'WorkflowID' => $this->workflowRecord('application')->ID,
            'PhaseID' => $this->objFromFixture(Phase::class, 'intake')->ID,
        ]);
        $this->assertTrue($other->validate()->isValid());
    }

    public function testWorkflowCodeIsGloballyUnique(): void
    {
        $workflow = Workflow::create(['Code' => 'course', 'Title' => 'Kurs 2', 'CaseClass' => TestTicket::class]);
        $this->assertContains('Code', $this->errorFields($workflow->validate()));
    }

    public function testCaseClassMustBeUniqueDataClass(): void
    {
        $workflow = Workflow::create(['Code' => 'other', 'Title' => 'Anderer']);

        $workflow->CaseClass = TestCourse::class;
        $this->assertContains('CaseClass', $this->errorFields($workflow->validate()), 'one workflow per class');

        $workflow->CaseClass = 'Does\\Not\\Exist';
        $this->assertContains('CaseClass', $this->errorFields($workflow->validate()));

        $workflow->CaseClass = \stdClass::class;
        $this->assertContains('CaseClass', $this->errorFields($workflow->validate()));

        $workflow->CaseClass = Member::class;
        $this->assertContains('CaseClass', $this->errorFields($workflow->validate()), 'needs the extension');

        $workflow->CaseClass = TestTicket::class;
        $this->assertTrue($workflow->validate()->isValid());
    }

    public function testForClass(): void
    {
        $this->assertSame($this->workflowRecord()->ID, Workflow::forClass(TestCourse::class)?->ID);
        $this->assertSame($this->workflowRecord('application')->ID, Workflow::forClass(TestApplication::class)?->ID);
        $this->assertNull(Workflow::forClass(Member::class));
    }

    public function testInitialStatusMustBelongToWorkflow(): void
    {
        $workflow = $this->workflowRecord();
        $workflow->InitialStatusID = $this->statusRecord('received')->ID;
        $this->assertContains('InitialStatusID', $this->errorFields($workflow->validate()));

        $workflow->InitialStatusID = $this->statusRecord('draft')->ID;
        $this->assertTrue($workflow->validate()->isValid());
    }

    public function testStatusPhaseMustBelongToSameWorkflow(): void
    {
        $status = $this->statusRecord('draft');
        $status->PhaseID = $this->objFromFixture(Phase::class, 'intake')->ID;
        $this->assertContains('PhaseID', $this->errorFields($status->validate()));

        $status->PhaseID = 0;
        $this->assertContains('PhaseID', $this->errorFields($status->validate()));
    }

    public function testTransitionStatusesMustBelongToSameWorkflow(): void
    {
        $transition = Transition::create([
            'WorkflowID' => $this->workflowRecord()->ID,
            'FromID' => $this->statusRecord('draft')->ID,
            'ToID' => $this->statusRecord('received')->ID,
        ]);
        $this->assertContains('ToID', $this->errorFields($transition->validate()));
    }

    public function testTransitionNeedsTwoDifferentStatuses(): void
    {
        $transition = Transition::create([
            'WorkflowID' => $this->workflowRecord()->ID,
            'FromID' => $this->statusRecord('draft')->ID,
            'ToID' => $this->statusRecord('draft')->ID,
        ]);
        $this->assertContains('ToID', $this->errorFields($transition->validate()));

        $transition->ToID = 0;
        $this->assertContains('ToID', $this->errorFields($transition->validate()));
    }

    public function testTransitionIsUnique(): void
    {
        $transition = Transition::create([
            'WorkflowID' => $this->workflowRecord()->ID,
            'FromID' => $this->statusRecord('draft')->ID,
            'ToID' => $this->statusRecord('review')->ID,
        ]);
        $this->assertContains('ToID', $this->errorFields($transition->validate()));

        // The reverse direction is a different transition.
        $transition->FromID = $this->statusRecord('review')->ID;
        $transition->ToID = $this->statusRecord('draft')->ID;
        $this->assertTrue($transition->validate()->isValid());
    }

    public function testTransitionTitleDefaultsToStatuses(): void
    {
        $this->assertSame('Zur Prüfung', $this->objFromFixture(Transition::class, 'draft_review')->getLabel());
        $transition = $this->objFromFixture(Transition::class, 'review_done');
        $this->assertSame('In Prüfung → Abgeschlossen', $transition->getLabel());
    }

    public function testTransitionRoles(): void
    {
        $transition = $this->objFromFixture(Transition::class, 'review_done');
        $this->assertTrue($transition->allowsAnyRole(['organisers']));
        $this->assertTrue($transition->allowsAnyRole(['speaker', 'organisers']));
        $this->assertFalse($transition->allowsAnyRole(['speaker']));
        $this->assertFalse($transition->allowsAnyRole([]));
        $this->assertSame('Organisation', $transition->getRoleTitles());
    }

    public function testRolesOfOtherWorkflowsAreDetached(): void
    {
        $transition = $this->objFromFixture(Transition::class, 'review_done');
        $transition->Roles()->add($this->roleRecord('clerk'));

        $this->assertFalse($transition->allowsAnyRole(['clerk']), 'foreign roles never grant a transition');
        $this->assertSame(['organisers'], $transition->allowedRoles()->column('Code'));

        $transition->Title = 'Abschließen';
        $transition->write();
        $this->assertSame(['organisers'], $transition->Roles()->column('Code'), 'detached on write');
    }

    public function testRoleGroupRules(): void
    {
        $external = $this->roleRecord('speaker');
        $external->GroupID = $this->objFromFixture(Group::class, 'readers')->ID;
        $this->assertContains('GroupID', $this->errorFields($external->validate()));

        $internal = $this->roleRecord('organisers');
        $internal->GroupID = 0;
        $this->assertContains('GroupID', $this->errorFields($internal->validate()));
    }

    public function testDeletingStatusRemovesItsTransitionsAndInitialStatus(): void
    {
        $workflow = $this->workflowRecord();
        $workflow->InitialStatusID = $this->statusRecord('review')->ID;
        $workflow->write();

        $this->statusRecord('review')->delete();

        $this->assertSame(0, Transition::get()->filter('WorkflowID', $workflow->ID)->count());
        $this->assertSame(0, (int) Workflow::get()->byID($workflow->ID)?->InitialStatusID);
    }

    public function testDeletingWorkflowCascades(): void
    {
        $workflow = $this->workflowRecord();
        $workflowID = $workflow->ID;
        $workflow->delete();

        foreach ([Phase::class, Status::class, FlowRole::class, Transition::class] as $class) {
            $this->assertSame(0, $class::get()->filter('WorkflowID', $workflowID)->count(), $class);
        }
        $this->assertSame(1, Status::get()->filter('WorkflowID', $this->workflowRecord('application')->ID)->count());
    }

    public function testPhaseWithStatusesCannotBeDeleted(): void
    {
        $this->logInWithPermission('FLOW_CONFIGURE');
        $this->assertFalse($this->objFromFixture(Phase::class, 'planning')->canDelete());

        $empty = Phase::create(['Code' => 'empty', 'Title' => 'Leer', 'WorkflowID' => $this->workflowRecord()->ID]);
        $empty->write();
        $this->assertTrue($empty->canDelete());
    }

    public function testConfigurationRequiresPermission(): void
    {
        $organiser = $this->objFromFixture(Member::class, 'organiser');
        $reader = $this->objFromFixture(Member::class, 'reader');

        foreach ([$this->workflowRecord(), $this->statusRecord('draft'), $this->roleRecord('speaker')] as $record) {
            $this->assertTrue($record->canView($organiser));
            $this->assertTrue($record->canEdit($organiser));
            $this->assertTrue($record->canCreate($organiser));
            $this->assertFalse($record->canView($reader));
            $this->assertFalse($record->canEdit($reader));
            $this->assertFalse($record->canDelete($reader));
            $this->assertFalse($record->canCreate($reader));
        }
    }
}
