<?php

namespace Atwx\Flow\Tests\Model;

use Atwx\Flow\Cli\CleanupDataCommand;
use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\AccessLink;
use Atwx\Flow\Model\CustomField;
use Atwx\Flow\Model\FieldGroup;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use Atwx\Flow\Ui\FlowFieldLayout;
use Atwx\SilverstripeFrontdeskKit\Catalog\Catalogs;
use Atwx\SilverstripeFrontdeskKit\Forms\SearchableDropdownField;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Custom fields: definition, storage as JSON, permissions via the field group,
 * list column, filter and the edit link.
 */
class CustomFieldTest extends FunctionalTest
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

    private function workflow(): Workflow
    {
        $workflow = Workflow::get()->find('Code', 'course');
        $this->assertNotNull($workflow);
        return $workflow;
    }

    private function field(string $code): CustomField
    {
        $field = CustomField::get()->find('Code', $code);
        $this->assertNotNull($field);
        return $field;
    }

    /**
     * @param array<string, mixed> $data Custom field values
     */
    private function courseIn(string $status, array $data = []): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        foreach ($data as $code => $value) {
            $course->setFlowValue($code, $value);
        }
        $statusID = Status::get()->filter(['Code' => $status, 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    private function logInTo(string $groupCode): Member
    {
        $member = Member::create(['Email' => $groupCode . '@example.com']);
        $member->write();
        $group = Group::get()->find('Code', $groupCode);
        $this->assertNotNull($group);
        $member->Groups()->add($group);
        $this->logInAs($member);
        return $member;
    }

    public function testValidation(): void
    {
        $group = FieldGroup::get()->filter(['Code' => 'basics', 'WorkflowID' => $this->workflow()->ID])->first();
        $field = CustomField::create([
            'WorkflowID' => $this->workflow()->ID,
            'FieldGroupID' => $group?->ID,
            'Code' => 'level',
            'Title' => 'Niveau',
            'Type' => 'select',
        ]);
        $this->assertFalse($field->validate()->isValid(), 'select needs options');
        $field->setOptionLines("a1: Anfänger\nb2: Mittel\n\nc1");
        $this->assertSame(['a1' => 'Anfänger', 'b2' => 'Mittel', 'c1' => 'c1'], $field->optionMap());
        $this->assertTrue($field->validate()->isValid());

        $field->Code = 'audience';
        $this->assertFalse($field->validate()->isValid(), 'code taken');

        $field->Code = 'level';
        $field->FieldGroupID = 0;
        $this->assertFalse($field->validate()->isValid(), 'group required');
    }

    public function testValuesAreStoredAsJsonByCode(): void
    {
        $course = TestCourse::create(['Title' => 'Kurs']);
        $course->setFlowValue('audience', 'beginners');
        $course->setFlowValue('certificate', true);
        $course->write();

        $stored = TestCourse::get()->byID($course->ID);
        $this->assertNotNull($stored);
        $this->assertSame(['audience' => 'beginners', 'certificate' => true], $stored->flowData());
        $this->assertSame('{"audience":"beginners","certificate":true}', $stored->FlowData);

        $stored->setFlowValue('certificate', null);
        $this->assertSame(['audience' => 'beginners'], $stored->flowData());
    }

    public function testFormValuesAreCollectedAndNormalised(): void
    {
        $course = $this->courseIn('draft', ['audience' => 'beginners']);
        // As a form saves into the virtual fields:
        $course->Flow_audience = 'advanced';
        $course->Flow_certificate = '1';
        $course->write();
        $this->assertSame(['audience' => 'advanced', 'certificate' => true], $course->flowData());

        $course->Flow_audience = 'unknown';
        $course->write();
        $this->assertArrayNotHasKey('audience', $course->flowData(), 'invalid choice clears the value');
    }

    public function testDisplay(): void
    {
        $this->assertSame('Einsteiger', $this->field('audience')->display('beginners'));
        $this->assertContains($this->field('certificate')->display(true), ['Yes', 'Ja']);
        $this->assertSame('', $this->field('audience')->display(null));
    }

    public function testCustomFieldsBelongToTheirGroup(): void
    {
        $fields = FieldGroup::fieldsFor($this->workflow());
        $this->assertSame(['Description', 'Audience', 'Flow_audience', 'Flow_certificate'], $fields['announcement']);
    }

    public function testLockedCustomFieldIsNotSaved(): void
    {
        $this->logInTo('organisers');
        $course = $this->courseIn('done', ['audience' => 'beginners']);
        $layout = FlowFieldLayout::build($course, \Atwx\Flow\Actor\MemberActor::current());
        $this->assertNotNull($layout);
        $this->assertSame('read', $layout->levelFor('Flow_audience'), 'announcement is read-only in "done"');

        $course->Flow_audience = 'advanced';
        $layout->resetLockedChanges($course);
        $course->write();
        $this->assertSame('beginners', TestCourse::get()->byID($course->ID)?->flowValue('audience'));
    }

    public function testColumnFilterAndForm(): void
    {
        $this->logInTo('organisers');
        $beginners = $this->courseIn('draft', ['audience' => 'beginners', 'certificate' => true]);
        $advanced = $this->courseIn('draft', ['audience' => 'advanced']);
        $controller = TestCourseController::create();

        $column = null;
        foreach ($controller->columns() as $candidate) {
            if ($candidate->getName() === 'Flow_audience') {
                $column = $candidate;
            }
        }
        $this->assertNotNull($column, 'custom field marked as list column');
        $this->assertSame('Einsteiger', (string) $column->renderValue($beginners));
        $names = array_map(fn ($column) => $column->getName(), $controller->columns()->toArray());
        $this->assertNotContains('Flow_certificate', $names, 'not a list column');

        $filter = function (array $vars) use ($controller): array {
            $request = new HTTPRequest('GET', 'test-courses', $vars);
            $request->setSession(new Session([]));
            $controller->setRequest($request);
            return $controller->filteredIDs();
        };
        $this->assertSame([$beginners->ID], $filter(['Flow_audience' => 'beginners']));
        $this->assertSame([$advanced->ID], $filter(['Flow_audience' => 'advanced']));
        $this->assertSame([$beginners->ID], $filter(['Flow_certificate' => '1']));
        $this->assertSame([$advanced->ID], $filter(['Flow_certificate' => '0']));
    }

    public function testEditLinkWritesCustomFieldOfTheRole(): void
    {
        $course = $this->courseIn('with_speaker', ['audience' => 'beginners']);
        $role = FlowRole::get()->filter(['Code' => 'speaker', 'WorkflowID' => $this->workflow()->ID])->first();
        $link = AccessLink::create(['Name' => 'Ruth', 'RoleID' => $role?->ID]);
        $link->setField('CaseClass', TestCourse::class);
        $link->setField('CaseID', $course->ID);
        $link->write();
        $url = AccessLink::URL_SEGMENT . '/' . $link->getPlainToken();

        $body = (string) $this->get($url)->getBody();
        $this->assertStringContainsString('name="Flow_audience"', $body);

        $this->post(
            $url . '/RecordForm',
            ['Flow_audience' => 'advanced', 'Flow_certificate' => '1', 'action_save' => 'x']
        );
        $this->assertSame(
            ['audience' => 'advanced', 'certificate' => true],
            TestCourse::get()->byID($course->ID)?->flowData()
        );
    }

    public function testCleanupRemovesValuesOfDeletedFields(): void
    {
        $course = $this->courseIn('draft', ['audience' => 'beginners', 'removed' => 'x']);
        $output = new BufferedOutput();
        (new CleanupDataCommand())->run(new ArrayInput([]), $output);

        $this->assertSame(['audience' => 'beginners'], TestCourse::get()->byID($course->ID)?->flowData());
        $this->assertStringContainsString('removed', $output->fetch());
    }

    /**
     * A custom field of type "catalog": entries of a frontdesk-kit catalog, stored
     * by code; inactive entries stay on the case file; entries in use cannot be
     * deleted.
     */
    public function testCatalogField(): void
    {
        i18n::set_locale('de_DE');
        Catalogs::config()->set('catalogs', ['channels' => ['title' => 'Akquisekanal']]);
        $entries = Catalogs::singleton()->sync('channels', [
            'web' => 'Website',
            'fair' => 'Messe',
            'old' => 'Zeitung',
        ]);
        $group = FieldGroup::get()->filter(['Code' => 'announcement', 'WorkflowID' => $this->workflow()->ID])->first();
        $this->assertNotNull($group);

        $custom = CustomField::create([
            'WorkflowID' => $this->workflow()->ID,
            'FieldGroupID' => $group->ID,
            'Code' => 'channel',
            'Title' => 'Kanal',
            'Type' => 'catalog',
            'ShowInList' => true,
        ]);
        try {
            $custom->write();
            $this->fail('a catalog is required');
        } catch (ValidationException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $custom->Catalog = 'channels';
        $custom->write();

        $entries['old']->Active = false;
        $entries['old']->write();
        $this->assertSame(['web' => 'Website', 'fair' => 'Messe'], $custom->choices());
        $this->assertSame(
            ['web' => 'Website', 'fair' => 'Messe', 'old' => 'Zeitung (inaktiv)'],
            $custom->choices('old')
        );
        $this->assertSame('fair', $custom->normalise('fair'));
        $this->assertNull($custom->normalise('nope'));
        $this->assertSame('Zeitung (inaktiv)', $custom->display('old'));
        $field = $custom->formField(null);
        $this->assertInstanceOf(SearchableDropdownField::class, $field);
        $this->assertSame(['web' => 'Website', 'fair' => 'Messe'], $field->getSource());

        $this->logInTo('organisers');
        $fair = $this->courseIn('draft', ['channel' => 'fair']);
        $this->courseIn('draft', ['channel' => 'web']);
        $controller = TestCourseController::create();
        $request = new HTTPRequest('GET', 'test-courses', ['Flow_channel' => 'fair']);
        $request->setSession(new Session([]));
        $controller->setRequest($request);
        $this->assertSame([$fair->ID], $controller->filteredIDs());

        $this->assertTrue($entries['fair']->isInUse(), 'stored on a case file');
        $this->assertFalse($entries['old']->isInUse());

        $yaml = \Atwx\Flow\Config\ConfigExporter::create()->toYaml();
        $this->assertStringContainsString('catalog: channels', $yaml);
    }
}
