<?php

namespace Atwx\Flow\Tests\Service;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\Assignment;
use Atwx\Flow\Model\AssignmentChange;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Service\AssignmentService;
use Atwx\Flow\Service\TransitionException;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;

class AssignmentServiceTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
    }

    private function member(string $email, ?string $groupCode): Member
    {
        $member = Member::create(['Email' => $email, 'FirstName' => ucfirst(strtok($email, '@'))]);
        $member->write();
        if ($groupCode) {
            $group = Group::get()->find('Code', $groupCode);
            $this->assertNotNull($group);
            $member->Groups()->add($group);
        }
        return $member;
    }

    private function course(): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        $statusID = Status::get()->filter(['Code' => 'draft', 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    private function lead(): Responsibility
    {
        $lead = Responsibility::get()->filter(['Code' => 'lead', 'Workflow.Code' => 'course'])->first();
        $this->assertNotNull($lead);
        return $lead;
    }

    public function testCandidatesAreMembersOfTheRole(): void
    {
        $olga = $this->member('olga@example.com', 'organisers');
        $this->member('lena@example.com', 'readers');

        $this->assertSame([(int) $olga->ID => 'Olga'], $this->lead()->candidateOptions());
    }

    public function testAssignRecordsTheChange(): void
    {
        $olga = $this->member('olga@example.com', 'organisers');
        $otto = $this->member('otto@example.com', 'organisers');
        $course = $this->course();
        $service = AssignmentService::singleton();

        $this->assertTrue($service->assign($course, $this->lead(), $olga, $otto));
        $this->assertFalse($service->assign($course, $this->lead(), $olga, $otto), 'same member: no change');
        $this->assertSame((int) $olga->ID, (int) $service->assignee($course, $this->lead())?->ID);
        $this->assertSame((int) $olga->ID, (int) $course->flowAssignee('lead')?->ID);

        $this->assertTrue($service->assign($course, $this->lead(), $otto, $otto));
        $this->assertTrue($service->assign($course, $this->lead(), null, $otto));
        $this->assertNull($service->assignee($course, $this->lead()));
        $this->assertSame(0, Assignment::get()->count());

        $changes = AssignmentChange::get()->sort('ID')->toArray();
        $this->assertCount(3, $changes);
        $this->assertSame([0, (int) $olga->ID], [(int) $changes[0]->FromID, (int) $changes[0]->ToID]);
        $this->assertSame([(int) $otto->ID, 0], [(int) $changes[2]->FromID, (int) $changes[2]->ToID]);
        $this->assertSame((int) $otto->ID, (int) $changes[0]->MemberID);
    }

    public function testOnlyCandidatesCanBeAssigned(): void
    {
        $lena = $this->member('lena@example.com', 'readers');
        $this->expectException(TransitionException::class);
        AssignmentService::singleton()->assign($this->course(), $this->lead(), $lena, null);
    }

    public function testAssignmentsGoWithTheCaseFile(): void
    {
        $olga = $this->member('olga@example.com', 'organisers');
        $course = $this->course();
        AssignmentService::singleton()->assign($course, $this->lead(), $olga, $olga);

        $course->delete();
        $this->assertSame(0, Assignment::get()->count());
        $this->assertSame(0, AssignmentChange::get()->count());
    }
}
