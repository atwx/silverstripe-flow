<?php

namespace Atwx\Flow\Tests\Config;

use Atwx\Flow\Config\ConfigException;
use Atwx\Flow\Config\ConfigExporter;
use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Model\FlowRole;
use Atwx\Flow\Model\Phase;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Model\Transition;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use Symfony\Component\Yaml\Yaml;

class ConfigImportExportTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
    ];

    private const string FILE = __DIR__ . '/config.yml';

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        return Yaml::parseFile(self::FILE);
    }

    public function testImportCreatesConfiguration(): void
    {
        $stats = ConfigImporter::create()->importFile(self::FILE);

        $this->assertSame(['created' => 2, 'updated' => 0], $stats['Workflow']);
        $this->assertSame(4, $stats['FlowRole']['created']);
        $this->assertSame(6, $stats['Status']['created']);
        $this->assertSame(4, $stats['Transition']['created']);
        $this->assertSame(3, $stats['Group']['created'], 'missing groups are created');

        $course = Workflow::get()->find('Code', 'course');
        $this->assertNotNull($course);
        $this->assertSame(TestCourse::class, $course->CaseClass);
        $this->assertSame($course->InitialStatusID, Status::get()->find('Code', 'draft')?->ID);
        $this->assertSame(
            ['draft', 'with_speaker', 'review', 'done'],
            Status::get()->filter('WorkflowID', $course->ID)->column('Code'),
            'list order becomes sort order'
        );

        $done = Status::get()->filter(['WorkflowID' => $course->ID, 'Code' => 'done'])->first();
        $this->assertInstanceOf(Status::class, $done);
        $this->assertTrue((bool) $done->IsFinal);
        $this->assertSame(Phase::get()->find('Code', 'execution')?->ID, (int) $done->PhaseID);

        $speaker = FlowRole::get()->filter(['WorkflowID' => $course->ID, 'Code' => 'speaker'])->first();
        $this->assertTrue((bool) $speaker?->IsExternal);
        $organisers = FlowRole::get()->filter(['WorkflowID' => $course->ID, 'Code' => 'organisers'])->first();
        $this->assertSame('organisers', $organisers?->Group()->Code);

        $back = Transition::get()->filter('Title', 'Fertig, an Organisation zurück')->first();
        $this->assertSame(['speaker'], $back?->Roles()->column('Code'));
    }

    public function testExportReproducesImportedFile(): void
    {
        ConfigImporter::create()->importFile(self::FILE);
        $this->assertSame(file_get_contents(self::FILE), ConfigExporter::create()->toYaml());
    }

    public function testExportSingleWorkflow(): void
    {
        ConfigImporter::create()->importFile(self::FILE);
        $export = ConfigExporter::create()->export(['course']);
        $this->assertSame(['course'], array_column($export['workflows'], 'code'));
    }

    public function testReimportChangesNothing(): void
    {
        ConfigImporter::create()->importFile(self::FILE);
        $this->assertSame([], ConfigImporter::create()->importFile(self::FILE));
        $this->assertSame(2, Workflow::get()->count());
        $this->assertSame(4, Transition::get()->count());
    }

    public function testExistingGroupIsReused(): void
    {
        $group = Group::create(['Title' => 'Orga-Team', 'Code' => 'organisers']);
        $group->write();

        ConfigImporter::create()->importFile(self::FILE);

        $this->assertSame(1, Group::get()->filter('Code', 'organisers')->count());
        $this->assertSame('Orga-Team', Group::get()->byID($group->ID)?->Title);
    }

    public function testImportUpdatesByCodeAndDeletesNothing(): void
    {
        ConfigImporter::create()->importFile(self::FILE);
        $statusID = Status::get()->filter('Code', 'review')->first()?->ID;

        $data = $this->data();
        $data['workflows'][1]['statuses'][2]['title'] = 'Prüfung';
        $data['workflows'][1]['transitions'][2]['roles'] = ['organisers', 'readers'];
        unset($data['workflows'][1]['statuses'][1]);
        $data['workflows'][1]['statuses'] = array_values($data['workflows'][1]['statuses']);
        unset($data['workflows'][1]['transitions'][0]);
        $data['workflows'][1]['transitions'] = array_values($data['workflows'][1]['transitions']);

        $stats = ConfigImporter::create()->import($data);

        $review = Status::get()->filter('Code', 'review')->first();
        $this->assertSame($statusID, $review?->ID, 'matched by code');
        $this->assertSame('Prüfung', $review?->Title);
        // "review" (title and position) and "done" (position moved up)
        $this->assertSame(2, $stats['Status']['updated'] ?? 0);
        $this->assertSame(1, Status::get()->filter('Code', 'with_speaker')->count(), 'not deleted');
        $this->assertSame(4, Transition::get()->count(), 'not deleted');
        $this->assertSame(
            ['organisers', 'readers'],
            Transition::get()->filter('To.Code', 'done')->first()?->Roles()->sort('Sort')->column('Code')
        );
    }

    public function testInvalidEntryImportsNothing(): void
    {
        $data = $this->data();
        $data['workflows'][1]['statuses'][3]['phase'] = 'unknown';

        $this->assertConfigError($data, 'unknown phase "unknown"');
        $this->assertSame(0, Workflow::get()->count());
        $this->assertSame(0, Phase::get()->count());
    }

    public function testValidationErrorsAreReported(): void
    {
        $data = $this->data();
        $data['workflows'][0]['class'] = Group::class;
        $this->assertConfigError($data, 'workflow application');

        $data = $this->data();
        $data['workflows'][1]['statuses'][0]['code'] = 'Draft';
        $this->assertConfigError($data, 'statuses[0]');
    }

    public function testMissingKeysAreReported(): void
    {
        $this->assertConfigError([], '"workflows"');
        $this->assertConfigError(['workflows' => [['code' => 'x']]], '"title" is missing');
        $data = $this->data();
        $data['workflows'][1]['transitions'][0]['roles'] = ['nobody'];
        $this->assertConfigError($data, 'unknown role "nobody"');
        $data = $this->data();
        $data['workflows'][1]['initial_status'] = 'nowhere';
        $this->assertConfigError($data, 'unknown status "nowhere"');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertConfigError(array $data, string $expected): void
    {
        try {
            ConfigImporter::create()->import($data);
        } catch (ConfigException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
            return;
        }
        $this->fail("Expected a ConfigException containing '{$expected}'");
    }
}
