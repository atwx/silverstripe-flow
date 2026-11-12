<?php

namespace Atwx\Flow\Tests\Api;

use Atwx\Flow\Api\ApiController;
use Atwx\Flow\Api\ApiResources;
use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestParticipant;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;

class ApiTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestParticipant::class,
    ];

    private TestCourse $course;

    protected function setUp(): void
    {
        parent::setUp();
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        TestAssetStore::activate('flow-api');
        Environment::setEnv('FLOW_API_TOKEN', 'secret-token');
        Config::modify()->set(ApiController::class, 'token_env', ['FLOW_API_TOKEN']);
        Config::modify()->set(ApiResources::class, 'resources', [
            'course' => [
                'class' => TestCourse::class,
                'get' => true,
                'fields' => [
                    'id' => 'ID',
                    'title' => 'Title',
                    'status' => 'FlowStatus.Title',
                    'participants' => ['relation' => 'Participants', 'resource' => 'participant'],
                    'withMail' => [
                        'relation' => 'Participants',
                        'resource' => 'participant',
                        'filter' => ['Email:GreaterThan' => ''],
                    ],
                ],
            ],
            'participant' => [
                'class' => TestParticipant::class,
                'list' => true,
                'params' => ['CourseID'],
                'get' => true,
                'fields' => [
                    'name' => 'Name',
                    'photo' => ['file' => 'Photo'],
                    'course' => ['relation' => 'Course', 'resource' => 'courseref'],
                ],
            ],
            'person' => [
                'class' => TestParticipant::class,
                'get' => true,
                'key' => 'Name',
                'filter' => ['Email:GreaterThan' => ''],
                'fields' => ['name' => 'Name'],
            ],
            'courseref' => ['class' => TestCourse::class, 'fields' => ['id' => 'ID']],
            'document' => [
                'class' => CaseDocument::class,
                'get' => true,
                'fields' => ['title' => 'Title', 'pdf' => ['file' => 'File'], 'secret' => ['file' => 'Missing']],
            ],
        ]);

        $this->course = TestCourse::create(['Title' => 'Paläographie', 'Venue' => 'Marburg']);
        $statusID = Status::get()->filter(['Code' => 'draft', 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($this->course, (int) $statusID);
        TestParticipant::create(['Name' => 'Ruth', 'Email' => 'ruth@example.org', 'CourseID' => $this->course->ID])
            ->write();
        TestParticipant::create(['Name' => 'Karl', 'Email' => '', 'CourseID' => $this->course->ID])->write();
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        Environment::setEnv('FLOW_API_TOKEN', '');
        parent::tearDown();
    }

    private function get(string $url, ?string $token = 'secret-token'): HTTPResponse
    {
        return Director::test($url, [], [], 'GET', null, $token ? ['X-Api-Token' => $token] : []);
    }

    /**
     * @return array<mixed>
     */
    private function json(HTTPResponse $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testOneRecordWithNestedRelationsAndOnlyListedFields(): void
    {
        $response = $this->get('flow-api/course/' . $this->course->ID);
        $this->assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        $this->assertSame((int) $this->course->ID, $data['id']);
        $this->assertSame('Paläographie', $data['title']);
        $this->assertSame('Entwurf', $data['status']);
        $this->assertArrayNotHasKey('Venue', $data);
        $this->assertSame(['Ruth', 'Karl'], array_column($data['participants'], 'name'));
        $this->assertSame(['Ruth'], array_column($data['withMail'], 'name'), 'relation filter');
        $this->assertSame(['id' => (int) $this->course->ID], $data['participants'][0]['course']);
    }

    public function testFileFieldsAsUrlAndDownload(): void
    {
        $document = CaseDocument::create(['Title' => 'Brief']);
        $document->setField('CaseClass', TestCourse::class);
        $document->setField('CaseID', $this->course->ID);
        $document->File->setFromString('%PDF-1.4 test', 'flow-api/brief.pdf');
        $document->write();

        $data = $this->json($this->get('flow-api/document/' . $document->ID));
        $this->assertStringEndsWith('flow-api/file/document/' . $document->ID . '/pdf', (string) $data['pdf']);
        $this->assertNull($data['secret']);

        $file = $this->get('flow-api/file/document/' . $document->ID . '/pdf');
        $this->assertSame(200, $file->getStatusCode());
        $this->assertSame('%PDF-1.4 test', $file->getBody());
        $this->assertSame(401, $this->get('flow-api/file/document/' . $document->ID . '/pdf', null)->getStatusCode());
        $this->assertSame(404, $this->get('flow-api/file/document/' . $document->ID . '/title')->getStatusCode());
    }

    public function testTokenListsParametersAndExposure(): void
    {
        $this->assertSame(401, $this->get('flow-api/course/' . $this->course->ID, null)->getStatusCode());
        $this->assertSame(401, $this->get('flow-api/course/' . $this->course->ID, 'wrong')->getStatusCode());
        $bearer = Director::test('flow-api/course/' . $this->course->ID, [], [], 'GET', null, [
            'Authorization' => 'Bearer secret-token',
        ]);
        $this->assertSame(200, $bearer->getStatusCode());

        $this->assertSame(404, $this->get('flow-api/course')->getStatusCode(), 'no list for course');
        $this->assertSame(404, $this->get('flow-api/courseref/' . $this->course->ID)->getStatusCode(), 'not exposed');
        $this->assertSame(404, $this->get('flow-api/unknown/1')->getStatusCode());

        $list = $this->json($this->get('flow-api/participant?CourseID=' . $this->course->ID . '&limit=1'));
        $this->assertSame(2, $list['total']);
        $this->assertCount(1, $list['items']);
        $this->assertSame(400, $this->get('flow-api/participant?Email=x')->getStatusCode(), 'not an allowed parameter');

        Environment::setEnv('FLOW_API_TOKEN', '');
        $this->assertSame(404, $this->get('flow-api/course/' . $this->course->ID)->getStatusCode(), 'API off');
    }

    public function testGetByKeyField(): void
    {
        $this->assertSame(['name' => 'Ruth'], $this->json($this->get('flow-api/person/Ruth')));
        $this->assertSame(404, $this->get('flow-api/person/ruth')->getStatusCode(), 'case-sensitive');
        $this->assertSame(404, $this->get('flow-api/person/Karl')->getStatusCode(), 'fixed filter applies');
        $ruth = TestParticipant::get()->find('Name', 'Ruth');
        $this->assertNotNull($ruth);
        $this->assertSame(404, $this->get('flow-api/person/' . $ruth->ID)->getStatusCode(), 'not by ID');

        TestParticipant::create(['Name' => 'Ruth', 'Email' => 'ruth2@example.org'])->write();
        $this->assertSame(404, $this->get('flow-api/person/Ruth')->getStatusCode(), 'ambiguous');
        $this->assertSame(404, $this->get('flow-api/course/abc')->getStatusCode(), 'ID must be numeric');
    }

    public function testUnpublishedFileOfAHasOne(): void
    {
        $photo = File::create();
        $photo->setFromString('JPEGDATA', 'flow-api/ruth.jpg');
        $photo->write();
        $ruth = TestParticipant::get()->find('Name', 'Ruth');
        $this->assertNotNull($ruth);
        $ruth->PhotoID = $photo->ID;
        $ruth->write();
        $this->assertFalse($photo->isPublished());

        $data = $this->json($this->get('flow-api/participant/' . $ruth->ID));
        $this->assertStringEndsWith('flow-api/file/participant/' . $ruth->ID . '/photo', (string) $data['photo']);
        $download = $this->get('flow-api/file/participant/' . $ruth->ID . '/photo');
        $this->assertSame(200, $download->getStatusCode());
        $this->assertSame('JPEGDATA', $download->getBody());
    }
}
