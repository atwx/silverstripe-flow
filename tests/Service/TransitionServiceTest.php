<?php

namespace Atwx\Flow\Tests\Service;

use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\StatusChange;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Service\TransitionException;
use Atwx\Flow\Service\TransitionService;
use Atwx\Flow\Tests\Stubs\TestActor;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCaseHooks;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestServiceHooks;
use Atwx\Flow\Tests\Stubs\TestTicket;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Case files and status changes, with two unrelated domain classes.
 */
class TransitionServiceTest extends SapphireTest
{
    protected static $fixture_file = 'TransitionServiceTest.yml';

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
        TestTicket::class,
    ];

    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected static $required_extensions = [
        TestCourse::class => [TestCaseHooks::class],
        TransitionService::class => [TestServiceHooks::class],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        TestCaseHooks::$log = [];
        TestCaseHooks::$vetoTo = null;
        TestServiceHooks::$log = [];

        foreach (['course' => 'draft', 'application' => 'received'] as $workflow => $status) {
            $record = $this->objFromFixture(Workflow::class, $workflow);
            $record->InitialStatusID = $this->objFromFixture(Status::class, $status)->ID;
            $record->write();
        }
    }

    private function statusCode(DataObject $case): string
    {
        $fresh = DataObject::get_by_id($case->ClassName, $case->ID, false);
        $status = $fresh ? Status::get()->byID((int) $fresh->getField('FlowStatusID')) : null;
        return $status ? $status->Code : '';
    }

    private function organiser(): MemberActor
    {
        return MemberActor::create($this->objFromFixture(Member::class, 'organiser'));
    }

    private function newCourse(): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Erschließung']);
        $course->write();
        return $course;
    }

    public function testNewCaseStartsInInitialStatusWithHistory(): void
    {
        $this->logInAs('organiser');
        $course = $this->newCourse();

        $this->assertSame('draft', $this->statusCode($course));
        $history = StatusChange::get()->filter(['CaseClass' => TestCourse::class, 'CaseID' => $course->ID]);
        $this->assertSame(1, $history->count());
        $first = $history->first();
        $this->assertInstanceOf(StatusChange::class, $first);
        $this->assertSame(0, (int) $first->FromID);
        $this->assertSame('draft', TestCaseHooks::code($first->To()));
        $this->assertSame($this->objFromFixture(Member::class, 'organiser')->ID, (int) $first->MemberID);
    }

    public function testClassWithoutWorkflowHasNoStatus(): void
    {
        $ticket = TestTicket::create(['Subject' => 'Frage']);
        $ticket->write();

        $this->assertSame(0, (int) $ticket->FlowStatusID);
        $this->assertSame(0, $ticket->FlowStatusChanges()->count());
        $this->assertSame(0, $ticket->availableTransitions($this->organiser())->count());

        $this->expectException(TransitionException::class);
        $ticket->transitionTo('draft', $this->organiser());
    }

    public function testAvailableTransitionsDependOnRoles(): void
    {
        $course = $this->newCourse();

        $this->assertSame(['review'], $this->targets($course->availableTransitions($this->organiser())));
        $this->assertSame(['review'], $this->targets($course->availableTransitions(new TestActor(['speaker']))));

        $reader = MemberActor::create($this->objFromFixture(Member::class, 'reader'));
        $this->assertSame([], $this->targets($course->availableTransitions($reader)));

        $course->transitionTo('review', $this->organiser());
        $this->assertSame([], $this->targets($course->availableTransitions(new TestActor(['speaker']))));
        $this->assertSame(['done'], $this->targets($course->availableTransitions($this->organiser())));
    }

    /**
     * @param iterable<\Atwx\Flow\Model\Transition> $transitions
     * @return array<int, string>
     */
    private function targets(iterable $transitions): array
    {
        $codes = [];
        foreach ($transitions as $transition) {
            $codes[] = TestCaseHooks::code($transition->To());
        }
        return $codes;
    }

    public function testTransitionWritesStatusAndHistory(): void
    {
        $course = $this->newCourse();
        $change = $course->transitionTo('review', $this->organiser(), 'Bitte prüfen');

        $this->assertSame('review', $this->statusCode($course));
        $this->assertSame('draft', TestCaseHooks::code($change->From()));
        $this->assertSame('review', TestCaseHooks::code($change->To()));
        $this->assertSame('Bitte prüfen', $change->Comment);
        $this->assertSame($this->objFromFixture(Member::class, 'organiser')->ID, (int) $change->MemberID);
        $this->assertSame(2, $course->FlowStatusChanges()->count());
    }

    public function testExternalActorIsRecordedWithoutMember(): void
    {
        $course = $this->newCourse();
        $change = $course->transitionTo('review', new TestActor(['speaker']));

        $this->assertSame('review', $this->statusCode($course));
        $this->assertSame(0, (int) $change->MemberID);
    }

    public function testTransitionAcceptsStatusRecord(): void
    {
        $course = $this->newCourse();
        $course->transitionTo($this->objFromFixture(Status::class, 'review'), $this->organiser());
        $this->assertSame('review', $this->statusCode($course));
    }

    public function testRoleWithoutPermissionIsRefused(): void
    {
        $course = $this->newCourse();
        $course->transitionTo('review', $this->organiser());

        $this->assertRefused(fn () => $course->transitionTo('done', new TestActor(['speaker'])));
        $this->assertSame('review', $this->statusCode($course));
        $this->assertSame(2, $course->FlowStatusChanges()->count());
    }

    public function testUndefinedTransitionIsRefused(): void
    {
        $course = $this->newCourse();
        $this->assertRefused(fn () => $course->transitionTo('done', $this->organiser()));
        $this->assertRefused(fn () => $course->transitionTo('unknown', $this->organiser()));
        $this->assertSame('draft', $this->statusCode($course));
    }

    public function testStatusOfOtherWorkflowIsRefused(): void
    {
        $course = $this->newCourse();
        $this->assertRefused(fn () => $course->transitionTo(
            $this->objFromFixture(Status::class, 'received'),
            $this->organiser()
        ));
        $this->assertRefused(fn () => $course->transitionTo('received', $this->organiser()));
    }

    public function testUnsavedCaseIsRefused(): void
    {
        $this->assertRefused(fn () => TestCourse::create()->transitionTo('review', $this->organiser()));
    }

    public function testWithoutLoginIsRefused(): void
    {
        $course = $this->newCourse();
        $this->logOut();
        $this->assertSame(0, $course->availableTransitions()->count());
        $this->assertRefused(fn () => $course->transitionTo('review'));
    }

    public function testCurrentMemberIsDefaultActor(): void
    {
        $course = $this->newCourse();
        $this->logInAs('organiser');
        $this->assertSame(1, $course->availableTransitions()->count());
        $course->transitionTo('review');
        $this->assertSame('review', $this->statusCode($course));
    }

    public function testDirectStatusWriteIsIgnored(): void
    {
        $course = $this->newCourse();
        $course->FlowStatusID = $this->objFromFixture(Status::class, 'done')->ID;
        $course->Title = 'Geändert';
        $course->write();

        $this->assertSame('draft', $this->statusCode($course));
        $this->assertSame('Geändert', TestCourse::get()->byID($course->ID)?->Title);
    }

    public function testNewCaseIgnoresGivenStatus(): void
    {
        $course = TestCourse::create(['FlowStatusID' => $this->objFromFixture(Status::class, 'done')->ID]);
        $course->write();
        $this->assertSame('draft', $this->statusCode($course));
    }

    public function testSeedCanCreateCaseInGivenStatus(): void
    {
        $course = TestCourse::create(['Title' => 'Altbestand']);
        CaseFileExtension::writeStatus($course, $this->objFromFixture(Status::class, 'done')->ID);

        $this->assertSame('done', $this->statusCode($course));
        $this->assertSame('done', $course->FlowStatusChanges()->first()?->To()->Code);
    }

    public function testStaleStatusIsRefused(): void
    {
        $course = $this->newCourse();
        $copy = TestCourse::get()->byID($course->ID);
        $this->assertNotNull($copy);
        $copy->transitionTo('review', $this->organiser());

        // $course still believes it is in "draft".
        $this->assertRefused(fn () => $course->transitionTo('review', $this->organiser()));
        $this->assertSame(2, $course->FlowStatusChanges()->count());
    }

    public function testHooksRunInOrder(): void
    {
        $course = $this->newCourse();
        $course->transitionTo('review', $this->organiser(), 'ok');

        $reviewID = $this->objFromFixture(Status::class, 'review')->ID;
        $this->assertSame(['before:draft>review:ok', "after:review:{$reviewID}"], TestCaseHooks::$log);
        $this->assertSame([TestCourse::class . '#' . $course->ID . ':review'], TestServiceHooks::$log);
    }

    public function testBeforeHookCanVeto(): void
    {
        $course = $this->newCourse();
        TestCaseHooks::$vetoTo = 'review';

        $this->assertRefused(fn () => $course->transitionTo('review', $this->organiser()));
        $this->assertSame('draft', $this->statusCode($course));
        $this->assertSame(1, $course->FlowStatusChanges()->count());
        $this->assertSame([], TestServiceHooks::$log, 'no after hook on veto');
    }

    public function testSecondDomainClassHasOwnWorkflow(): void
    {
        $application = TestApplication::create(['Applicant' => 'Archiv Nord']);
        $application->write();
        $this->assertSame('received', $this->statusCode($application));

        $clerk = $this->organiser(); // organisers group holds the "clerk" role in this workflow
        $this->assertSame(['approved'], $this->targets($application->availableTransitions($clerk)));
        $application->transitionTo('approved', $clerk);
        $this->assertSame('approved', $this->statusCode($application));

        // The course hooks are not attached to this class.
        $this->assertSame([], TestCaseHooks::$log);
        $this->assertSame(
            [TestApplication::class . '#' . $application->ID . ':approved'],
            TestServiceHooks::$log
        );
    }

    public function testWorkflowOffersOnlyCaseFileClasses(): void
    {
        $classes = Workflow::caseClasses();
        $this->assertContains(TestCourse::class, $classes);
        $this->assertContains(TestTicket::class, $classes);
        $this->assertNotContains(Member::class, $classes);
    }

    private function assertRefused(callable $action): void
    {
        try {
            $action();
        } catch (TransitionException) {
            $this->addToAssertionCount(1);
            return;
        }
        $this->fail('Expected a TransitionException');
    }
}
