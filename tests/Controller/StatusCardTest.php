<?php

namespace Atwx\Flow\Tests\Controller;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Service\AssignmentService;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;

class StatusCardTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
    ];

    private Member $anna;

    protected function setUp(): void
    {
        parent::setUp();
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        $this->anna = Member::create(['Email' => 'anna@example.com', 'FirstName' => 'Anna', 'Surname' => 'Berg']);
        $this->anna->write();
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        $this->anna->Groups()->add($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        $this->logInAs($this->anna);
    }

    protected function tearDown(): void
    {
        TestCourseController::$tabsLayout = false;
        parent::tearDown();
    }

    private function course(): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        $statusID = Status::get()->filter(['Code' => 'with_speaker', 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        $lead = Responsibility::get()->filter(['Code' => 'lead', 'Workflow.Code' => 'course'])->first();
        $this->assertNotNull($lead);
        AssignmentService::singleton()->assign($course, $lead, $this->anna, $this->anna);
        return $course;
    }

    public function testHeaderStatusInsteadOfHeaderFieldsInTheTabsLayout(): void
    {
        $course = $this->course();
        $controller = TestCourseController::create();
        $this->assertContains('Status', $controller->headerFields($course)?->column('Label') ?? [], 'sidebar layout');

        TestCourseController::$tabsLayout = true;
        $this->assertNull($controller->headerFields($course), 'tabs: status is in the header status');
        $status = $controller->headerStatus($course);
        $this->assertNotNull($status);
        $this->assertSame(['Status', 'Phase'], $status->column('Title'));
        $this->assertSame(['Bei Referent:in', 'Planung'], $status->column('Value'));
    }

    public function testStatusCardShowsStatusAssigneesAndGroups(): void
    {
        $course = $this->course();
        $card = TestCourseController::create()->statusCard('status', 'Status', ['organisation']);
        $this->assertSame(['organisation'], $card->groupKeys(), 'groups edited through the card');

        $html = (string) ($card->getBlocks()[0]['renderer'])($course);
        $this->assertStringContainsString('Bei Referent:in', $html);
        $this->assertStringContainsString('Planung', $html);
        $this->assertMatchesRegularExpression('#Zuständig</dt>\s*<dd[^>]*>AB</dd>#', $html, 'initials, combined');
        $this->assertStringNotContainsString('Status ändern', $html, 'no change link on the card');

        Config::modify()->set(TestCourseController::class, 'status_card_assignees', 'rows');
        $html = (string) ($card->getBlocks()[0]['renderer'])($course);
        $this->assertMatchesRegularExpression('#Leitung</dt>\s*<dd[^>]*>Anna Berg</dd>#', $html);
    }
}
