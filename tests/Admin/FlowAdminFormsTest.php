<?php

namespace Atwx\Flow\Tests\Admin;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\FieldPermission;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Phase;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\Transition;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\ORM\DataObject;

/**
 * The CMS forms of the configuration records build, and their choices are
 * limited to the record's own workflow.
 */
class FlowAdminFormsTest extends SapphireTest
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

    public function testFormsBuildForAllRecords(): void
    {
        $classes = [
            Workflow::class, Phase::class, Status::class, Transition::class,
            FlowRole::class, FieldGroup::class, FieldPermission::class,
        ];
        foreach ($classes as $class) {
            $record = $class::get()->first();
            $this->assertNotNull($record, $class);
            $this->assertGreaterThan(0, count($record->getCMSFields()->dataFields()), $class);
        }
    }

    public function testWorkflowFormHasTabsAndInitialStatus(): void
    {
        $workflow = Workflow::get()->find('Code', 'course');
        $this->assertNotNull($workflow);
        $fields = $workflow->getCMSFields();
        foreach (['Phases', 'Statuses', 'Transitions', 'Roles', 'FieldGroups', 'FieldPermissions'] as $relation) {
            $this->assertInstanceOf(GridField::class, $fields->dataFieldByName($relation), $relation);
        }
        $initial = $fields->dataFieldByName('InitialStatusID');
        $this->assertInstanceOf(DropdownField::class, $initial);
        $this->assertCount(4, $initial->getSource());
    }

    public function testChoicesAreLimitedToOwnWorkflow(): void
    {
        $permission = FieldPermission::get()->filter('Workflow.Code', 'course')->first();
        $this->assertNotNull($permission);
        $fields = $permission->getCMSFields();

        $roles = $fields->dataFieldByName('RoleID');
        $this->assertInstanceOf(DropdownField::class, $roles);
        $this->assertSame(['Organisation', 'Lesend', 'Referent:in'], array_values($roles->getSource()));

        $statuses = $fields->dataFieldByName('StatusID');
        $this->assertInstanceOf(DropdownField::class, $statuses);
        $this->assertCount(4, $statuses->getSource());

        $transition = Transition::get()->filter('Workflow.Code', 'application')->first();
        $this->assertNotNull($transition);
        $from = $transition->getCMSFields()->dataFieldByName('FromID');
        $this->assertInstanceOf(DropdownField::class, $from);
        $this->assertSame(['Eingegangen', 'Bewilligt'], array_values($from->getSource()));
    }
}
