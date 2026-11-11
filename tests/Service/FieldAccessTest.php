<?php

namespace Atwx\Flow\Tests\Service;

use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\FieldPermission;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Service\FieldAccess;
use Atwx\Flow\Tests\Stubs\TestActor;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;

/**
 * The permission matrix of the course workflow (see tests/Config/config.yml):
 *
 *                 basics   announcement                         organisation
 *   organisers    write    write; read in "done"                write
 *   readers       read     read                                 read
 *   speaker       read     read; write in "with_speaker";       –
 *                          hidden in "done"
 */
class FieldAccessTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
    ];

    private FieldAccess $access;

    protected function setUp(): void
    {
        parent::setUp();
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        $this->access = FieldAccess::create();
    }

    private function courseIn(string $status): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        $statusID = Status::get()->filter(['Code' => $status, 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    private function member(string $groupCode): MemberActor
    {
        $member = Member::create(['Email' => $groupCode . '@example.com']);
        $member->write();
        $group = Group::get()->find('Code', $groupCode);
        $this->assertNotNull($group);
        $member->Groups()->add($group);
        return MemberActor::create($member);
    }

    public function testDeclaredGroupsAreCreated(): void
    {
        $workflow = Workflow::get()->find('Code', 'course');
        $this->assertSame(
            ['basics', 'announcement', 'organisation'],
            FieldGroup::get()->filter('WorkflowID', $workflow?->ID)->column('Code')
        );
        $this->assertSame(
            ['title' => 'organisation', 'fields' => ['InternalNote']],
            FieldGroup::declaredFor(TestCourse::class)['organisation'],
            'short form'
        );
    }

    public function testMatrixForInternalRoles(): void
    {
        $course = $this->courseIn('draft');

        $this->assertSame(
            ['basics' => 'write', 'announcement' => 'write', 'organisation' => 'write'],
            $this->access->groupLevels($course, $this->member('organisers'))
        );
        $this->assertSame(
            ['basics' => 'read', 'announcement' => 'read', 'organisation' => 'read'],
            $this->access->groupLevels($course, $this->member('readers'))
        );
    }

    public function testStatusSpecificEntryWins(): void
    {
        $speaker = new TestActor(['speaker']);

        $this->assertSame(
            ['basics' => 'read', 'announcement' => 'read', 'organisation' => 'hidden'],
            $this->access->groupLevels($this->courseIn('draft'), $speaker)
        );
        $this->assertSame(
            'write',
            $this->access->groupLevels($this->courseIn('with_speaker'), $speaker)['announcement']
        );
        $this->assertSame(
            'hidden',
            $this->access->groupLevels($this->courseIn('done'), $speaker)['announcement'],
            'a specific entry may also lower the level'
        );
        $this->assertSame(
            'read',
            $this->access->groupLevels($this->courseIn('done'), $this->member('organisers'))['announcement']
        );
    }

    public function testHighestLevelOfSeveralRolesWins(): void
    {
        $both = new TestActor(['readers', 'speaker']);
        $levels = $this->access->groupLevels($this->courseIn('with_speaker'), $both);
        $this->assertSame(['basics' => 'read', 'announcement' => 'write', 'organisation' => 'read'], $levels);
    }

    public function testWithoutRoleEverythingIsHidden(): void
    {
        $course = $this->courseIn('draft');
        $this->assertSame(
            ['basics' => 'hidden', 'announcement' => 'hidden', 'organisation' => 'hidden'],
            $this->access->groupLevels($course, new TestActor([]))
        );
        $this->assertSame([], $this->access->readableFields($course, new TestActor(['unknown'])));
    }

    public function testFieldLevels(): void
    {
        $course = $this->courseIn('with_speaker');
        $speaker = new TestActor(['speaker']);

        $this->assertSame(
            [
                'Title' => 'read',
                'Venue' => 'read',
                'Description' => 'write',
                'Audience' => 'write',
                'InternalNote' => 'hidden',
            ],
            $this->access->fieldLevels($course, $speaker)
        );
        $this->assertSame(['Description', 'Audience'], $this->access->writableFields($course, $speaker));
        $this->assertSame(
            ['Title', 'Venue', 'Description', 'Audience'],
            $this->access->readableFields($course, $speaker)
        );
        $this->assertTrue($this->access->canWrite($course, $speaker, 'Description'));
        $this->assertFalse($this->access->canWrite($course, $speaker, 'Title'));
        $this->assertTrue($this->access->canRead($course, $speaker, 'Title'));
        $this->assertFalse($this->access->canRead($course, $speaker, 'InternalNote'));
        $this->assertFalse($this->access->canRead($course, $speaker, 'NotInAnyGroup'), 'ungrouped fields are hidden');
    }

    public function testFilterWritableDropsLockedFields(): void
    {
        $course = $this->courseIn('with_speaker');
        $submitted = [
            'Description' => 'Neu',
            'Title' => 'Manipuliert',
            'InternalNote' => 'Manipuliert',
            'FlowStatusID' => 99,
            'ID' => 1,
        ];

        $this->assertSame(
            ['Description' => 'Neu'],
            $this->access->filterWritable($course, new TestActor(['speaker']), $submitted)
        );
        $this->assertSame(
            [],
            $this->access->filterWritable($this->courseIn('done'), new TestActor(['speaker']), $submitted)
        );
    }

    public function testClassWithoutGroupsHasNoFields(): void
    {
        $application = TestApplication::create(['Applicant' => 'A']);
        $application->write();
        $this->assertSame([], $this->access->fieldLevels($application, new TestActor(['clerk'])));
    }

    public function testPermissionValidation(): void
    {
        $workflow = Workflow::get()->find('Code', 'course');
        $other = Workflow::get()->find('Code', 'application');
        $group = FieldGroup::get()->filter(['WorkflowID' => $workflow?->ID, 'Code' => 'basics'])->first();
        $role = FlowRole::get()->filter(['WorkflowID' => $workflow?->ID, 'Code' => 'speaker'])->first();
        $foreignRole = FlowRole::get()->filter(['WorkflowID' => $other?->ID])->first();

        $duplicate = FieldPermission::create([
            'WorkflowID' => $workflow?->ID,
            'FieldGroupID' => $group?->ID,
            'RoleID' => $role?->ID,
            'Level' => 'write',
        ]);
        $this->assertFalse($duplicate->validate()->isValid(), 'one entry per group, role and status');

        $duplicate->RoleID = (int) $foreignRole?->ID;
        $this->assertFalse($duplicate->validate()->isValid(), 'role of another workflow');

        $duplicate->RoleID = (int) $role?->ID;
        $duplicate->StatusID = (int) Status::get()->find('Code', 'review')?->ID;
        $this->assertTrue($duplicate->validate()->isValid());

        $duplicate->Level = 'admin';
        $this->assertFalse($duplicate->validate()->isValid());
    }

    public function testDeclaredGroupsCannotBeCreatedOrDeleted(): void
    {
        $this->logInWithPermission('FLOW_CONFIGURE');
        $group = FieldGroup::get()->find('Code', 'basics');
        $this->assertNotNull($group);
        $this->assertFalse($group->canDelete());
        $this->assertFalse(FieldGroup::singleton()->canCreate());
        $this->assertTrue($group->canEdit());
    }

    public function testDeletingRoleRemovesItsPermissions(): void
    {
        $role = FlowRole::get()->find('Code', 'speaker');
        $this->assertNotNull($role);
        $roleID = $role->ID;
        $role->delete();
        $this->assertSame(0, FieldPermission::get()->filter('RoleID', $roleID)->count());
    }
}
