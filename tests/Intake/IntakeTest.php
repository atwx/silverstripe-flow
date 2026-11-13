<?php

namespace Atwx\Flow\Tests\Intake;

use Atwx\Flow\Api\ApiClient;
use Atwx\Flow\Api\ApiLog;
use Atwx\Flow\Document\PdfRenderer;
use Atwx\Flow\Intake\IntakeController;
use Atwx\Flow\Intake\IntakeService;
use Atwx\Flow\Intake\Submission;
use Atwx\Flow\Intake\SubmissionFile;
use Atwx\Flow\Intake\UploadReader;
use Atwx\Flow\Model\TextBlock;
use Atwx\Flow\Tests\Stubs\FakePdfRenderer;
use Atwx\Flow\Tests\Stubs\TestIntakeHooks;
use Atwx\Flow\Tests\Stubs\TestParticipant;
use Atwx\Flow\Tests\Stubs\TestTakeover;
use Atwx\Flow\Tests\Stubs\TestUploadReader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\SecurityToken;
use SilverStripe\Versioned\Versioned;
use SilverStripe\View\SSViewer;

class IntakeTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [TestParticipant::class];

    /**
     * @var array<string, array<int, string>>
     */
    protected static $required_extensions = [IntakeService::class => [TestIntakeHooks::class]];

    private const string PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        TestAssetStore::activate('flow-intake');
        Injector::inst()->registerService(new NullLogger(), LoggerInterface::class);
        Injector::inst()->registerService(new FakePdfRenderer(), PdfRenderer::class);
        Config::modify()->merge(Director::class, 'rules', ['intake//$Action/$ID/$OtherID' => IntakeController::class]);
        // A page template around the frontdesk layouts (the module itself has none)
        $theme = rtrim((string) ModuleLoader::getModule('atwx/silverstripe-flow')->getRelativePath(), '/');
        SSViewer::set_themes(['/' . ltrim($theme . '/tests/theme', '/'), SSViewer::DEFAULT_THEME]);
        Config::modify()->set(IntakeService::class, 'types', [
            'registration' => [
                'title' => 'Anmeldung',
                'reference_prefix' => 'AN',
                'summary' => '{{name}}',
                'reply_to' => 'email',
                'fields' => [
                    'name' => ['type' => 'string', 'title' => 'Name', 'required' => true, 'max' => 50],
                    'email' => ['type' => 'email', 'title' => 'E-Mail', 'required' => true],
                    'birthday' => ['type' => 'date', 'title' => 'Geburtstag'],
                    'years' => ['type' => 'integer', 'title' => 'Jahre Englisch', 'min' => 0, 'max' => 20],
                    'sports' => [
                        'type' => 'choice',
                        'multiple' => true,
                        'options' => ['tennis' => 'Tennis', 'golf' => 'Golf'],
                    ],
                    'consent' => [
                        'type' => 'boolean',
                        'title' => 'Einwilligung',
                        'accepted' => true,
                        'required' => true,
                    ],
                    'address' => [
                        'type' => 'group',
                        'title' => 'Adresse',
                        'fields' => ['city' => ['type' => 'string', 'title' => 'Ort']],
                    ],
                    'siblings' => [
                        'type' => 'repeat',
                        'title' => 'Geschwister',
                        'max_items' => 3,
                        'fields' => ['first_name' => ['type' => 'string', 'title' => 'Vorname', 'required' => true]],
                    ],
                ],
                'files' => [
                    'photo' => ['title' => 'Foto', 'types' => ['pdf', 'png'], 'max_size' => '1KB', 'max_count' => 2],
                ],
                'notify' => [
                    'to' => 'office@example.org',
                    'subject' => 'Neue Anmeldung {{Reference}}',
                    'block' => 'Intake_Notify',
                    'fields' => ['address.city'],
                ],
                'receipt' => ['hours' => 2],
                'retention' => ['taken_over' => 30, 'discarded' => 10],
                'takeover' => TestTakeover::class,
            ],
            'feedback' => ['title' => 'Rückmeldung', 'fields' => ['text' => ['type' => 'text', 'required' => true]]],
        ]);
        TextBlock::create([
            'Code' => 'Intake_Notify',
            'Title' => 'Benachrichtigung',
            'Body' => '<p>Neu: {{Type}} {{Reference}} aus {{Field.address.city}} {{Field.name}} '
                . '<a href="{{Link}}">öffnen</a></p>',
        ])->write();
        [, $this->key] = ApiClient::createWithKey('Website', ['intake:registration']);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Ruth Tester',
            'email' => 'ruth@example.org',
            'birthday' => '2012-04-03',
            'years' => 4,
            'sports' => ['golf'],
            'consent' => true,
            'address' => ['city' => 'Hamburg'],
            'siblings' => [['first_name' => 'Max']],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function submit(string $type, array $body, array $headers = [], ?string $key = null): HTTPResponse
    {
        return Director::test('api/intake/' . $type, [], [], 'POST', (string) json_encode($body), array_merge([
            'Authorization' => 'Bearer ' . ($key ?? $this->key),
            'Content-Type' => 'application/json',
        ], $headers));
    }

    /**
     * @return array<mixed>
     */
    private function json(HTTPResponse $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testSubmissionWithFilesNotificationAndReceipt(): void
    {
        $response = $this->submit('registration', [
            'data' => $this->registration(),
            'files' => ['photo' => [['name' => 'Ruth Tester Zeugnis.pdf', 'content' => base64_encode(self::PDF)]]],
        ]);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $answer = $this->json($response);
        $year = (int) date('Y');
        $this->assertSame('AN-' . $year . '-0001', $answer['reference']);
        $this->assertSame('new', $answer['status']);
        $this->assertStringContainsString('/flow/receipt/', $answer['receipt']['url']);

        $submission = Submission::get()->find('Reference', $answer['reference']);
        $this->assertSame('Ruth Tester', $submission->Summary);
        $this->assertSame('Hamburg', $submission->value('address.city'));
        $this->assertSame(['golf'], $submission->value('sports'));
        $this->assertSame(4, $submission->value('years'));
        $files = $submission->filesOf('photo');
        $this->assertCount(1, $files);
        $this->assertStringNotContainsString('Ruth', (string) $files[0]->getFilename(), 'no personal data in names');
        $this->assertFalse($files[0]->isPublished(), 'protected');
        $this->assertSame('Ruth Tester Zeugnis.pdf', $submission->Files()->first()->OriginalName);

        $mail = $this->findEmail('office@example.org');
        $this->assertNotNull($mail);
        $this->assertSame('Neue Anmeldung ' . $answer['reference'], $mail['Subject']);
        $this->assertStringContainsString('aus Hamburg', $mail['Content']);
        $this->assertStringNotContainsString('Ruth', $mail['Content'], 'only listed fields');
        $this->assertStringContainsString('intake/view/' . $submission->ID, $mail['Content']);
        $this->assertSame('ruth@example.org', TestIntakeHooks::$replyTo);
        $this->assertNotNull($submission->NotifiedAt);

        $receipt = Director::test(parse_url($answer['receipt']['url'], PHP_URL_PATH) ?: '');
        $this->assertSame(200, $receipt->getStatusCode());
        $this->assertSame('application/pdf', $receipt->getHeader('Content-Type'));
        $renderer = Injector::inst()->get(PdfRenderer::class);
        $this->assertInstanceOf(FakePdfRenderer::class, $renderer);
        $html = $renderer->lastHtml;
        $this->assertStringContainsString('Ruth Tester', $html);
        $this->assertStringContainsString('03.04.2012', $html);
        $this->assertStringContainsString('Ruth Tester Zeugnis.pdf', $html);
        DBDatetime::set_mock_now(date('Y-m-d H:i:s', time() + 3 * 3600));
        try {
            $expired = Director::test(parse_url($answer['receipt']['url'], PHP_URL_PATH) ?: '');
            $this->assertSame(404, $expired->getStatusCode());
        } finally {
            DBDatetime::clear_mock_now();
        }
        $this->assertSame(404, Director::test('flow/receipt/' . str_repeat('a', 48))->getStatusCode());

        $second = $this->json($this->submit('registration', ['data' => $this->registration(['name' => 'Karl'])]));
        $this->assertSame('AN-' . $year . '-0002', $second['reference']);

        $log = ApiLog::get()->sort('ID')->first();
        $this->assertSame('intake.registration.create', $log->Operation);

        $submission->delete();
        $this->assertSame([], Versioned::get_by_stage(File::class, Versioned::DRAFT)
            ->filter('Name', ['photo-1.pdf', $answer['reference']])->column('Name'), 'file and its folder deleted');
    }

    public function testValidationErrorsAndScope(): void
    {
        $response = $this->submit('registration', [
            'data' => $this->registration([
                'name' => str_repeat('x', 60),
                'email' => 'no-mail',
                'birthday' => '2012-02-30',
                'years' => 'many',
                'sports' => ['chess'],
                'consent' => false,
                'siblings' => [['first_name' => ''], ['first_name' => 'A', 'age' => 3]],
                'secret' => 'x',
            ]),
            'files' => [
                'photo' => [['name' => 'a.pdf', 'content' => base64_encode('not a pdf')]],
                'other' => [],
            ],
        ]);
        $this->assertSame(422, $response->getStatusCode());
        $fields = $this->json($response)['fields'];
        $this->assertSame([
            'secret', 'name', 'email', 'birthday', 'years', 'sports', 'consent',
            'siblings.0.first_name', 'siblings.1.age', 'files.other', 'files.photo.0',
        ], array_keys($fields));
        $this->assertSame('Content does not match the file type', $fields['files.photo.0']);

        $tooBig = $this->submit('registration', [
            'data' => $this->registration(),
            'files' => ['photo' => [
                ['name' => 'a.pdf', 'content' => base64_encode(self::PDF . str_repeat(' ', 1100))],
            ]],
        ]);
        $this->assertSame(['files.photo.0'], array_keys($this->json($tooBig)['fields']));
        $missing = $this->submit('registration', ['data' => ['name' => 'X']]);
        $this->assertSame(['email', 'consent'], array_keys($this->json($missing)['fields']));
        $this->assertSame(422, $this->submit('registration', ['nodata' => 1])->getStatusCode());
        $this->assertSame(0, Submission::get()->count());

        $this->assertSame(403, $this->submit('feedback', ['data' => ['text' => 'Hi']])->getStatusCode(), 'scope');
        $this->assertSame(404, $this->submit('unknown', ['data' => []])->getStatusCode());
    }

    public function testIdempotencyKey(): void
    {
        $first = $this->submit('registration', ['data' => $this->registration()], ['Idempotency-Key' => 'form-123']);
        $this->clearEmails();
        $again = $this->submit('registration', ['data' => $this->registration()], ['Idempotency-Key' => 'form-123']);
        $this->assertSame(201, $first->getStatusCode());
        $this->assertSame(200, $again->getStatusCode());
        $this->assertSame('true', $again->getHeader('Idempotent-Replayed'));
        $this->assertSame($this->json($first)['reference'], $this->json($again)['reference']);
        $this->assertSame(1, Submission::get()->count());
        $this->assertNull($this->findEmail('office@example.org'), 'notified once');

        [, $other] = ApiClient::createWithKey('Other site', ['intake:*']);
        $this->submit('registration', ['data' => $this->registration()], ['Idempotency-Key' => 'form-123'], $other);
        $this->assertSame(2, Submission::get()->count(), 'keys are per client');
        $conflict = $this->submit('feedback', ['data' => ['text' => 'x']], ['Idempotency-Key' => 'form-123'], $other);
        $this->assertSame(409, $conflict->getStatusCode());
    }

    public function testMultipartUpload(): void
    {
        Injector::inst()->registerService(TestUploadReader::create(), UploadReader::class);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'intake');
        file_put_contents($tmp, self::PDF);
        $response = Director::test('api/intake/registration', [], [], 'POST', null, [
            'Authorization' => 'Bearer ' . $this->key,
            'Content-Type' => 'multipart/form-data; boundary=x',
        ]);
        $this->assertSame(422, $response->getStatusCode(), 'no data part');

        // @phpstan-ignore argument.type (an uploaded file entry, as PHP puts it into the post vars)
        $request = new \SilverStripe\Control\HTTPRequest('POST', 'api/intake/registration', [], [
            'data' => json_encode($this->registration()),
            'photo' => [
                'name' => ['one.pdf', 'two.pdf'],
                'type' => ['application/pdf', 'application/pdf'],
                'tmp_name' => [$tmp, $tmp],
                'error' => [0, 0],
                'size' => [strlen(self::PDF), strlen(self::PDF)],
            ],
        ]);
        $request->addHeader('Authorization', 'Bearer ' . $this->key);
        $request->addHeader('Content-Type', 'multipart/form-data; boundary=x');
        $request->setSession(new \SilverStripe\Control\Session([]));
        $response = Director::singleton()->handleRequest($request);
        @unlink($tmp);
        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $submission = Submission::get()->first();
        $this->assertSame(['one.pdf', 'two.pdf'], $submission->Files()->column('OriginalName'));
    }

    public function testNotificationFailureKeepsTheSubmission(): void
    {
        $types = Config::inst()->get(IntakeService::class, 'types');
        $types['registration']['notify']['to'] = 'not an address';
        Config::modify()->set(IntakeService::class, 'types', $types);
        $response = $this->submit('registration', ['data' => $this->registration()]);
        $this->assertSame(201, $response->getStatusCode());
        $submission = Submission::get()->first();
        $this->assertNotEmpty($submission->NotifyError);
        $this->assertNull($submission->NotifiedAt);
    }

    public function testInboxViewCorrectTakeOverAndRetention(): void
    {
        $existing = TestParticipant::create(['Name' => 'Old Ruth', 'Email' => 'ruth@example.org']);
        $existing->write();
        $answer = $this->json($this->submit('registration', [
            'data' => $this->registration(),
            'files' => ['photo' => [
                ['name' => 'photo.pdf', 'content' => base64_encode(self::PDF)],
                ['name' => 'other.pdf', 'content' => base64_encode(self::PDF)],
            ]],
        ]));
        $submission = Submission::get()->find('Reference', $answer['reference']);

        $this->assertStringNotContainsString(
            'Hamburg',
            (string) $this->get('intake/view/' . $submission->ID)->getBody(),
            'not logged in → login'
        );
        $this->logInWithPermission('FDK_INTAKE_VIEW');
        $view = $this->get('intake/view/' . $submission->ID);
        $this->assertSame(200, $view->getStatusCode());
        $this->assertStringContainsString('Hamburg', (string) $view->getBody());
        $this->assertStringContainsString('photo.pdf', (string) $view->getBody());
        $this->assertStringNotContainsString('recordaction', (string) $view->getBody(), 'viewers cannot take over');
        $this->assertSame(200, $this->get('intake')->getStatusCode());
        $link = $submission->Files()->first();
        $download = $this->get('intake/download/' . $submission->ID . '/' . $link->ID);
        $this->assertSame(self::PDF, (string) $download->getBody());

        $this->logOut();
        $this->logInWithPermission('FDK_INTAKE_EDIT');
        $edit = $this->get('intake/edit/' . $submission->ID);
        $this->assertSame(200, $edit->getStatusCode());
        $this->assertStringContainsString('IntakeData[siblings][0][first_name]', (string) $edit->getBody());
        $this->submitForm('Form_EditForm', 'action_save', [
            'IntakeData[name]' => 'Ruth Corrected',
            'IntakeData[years]' => '5',
            'IntakeData[siblings][0][first_name]' => 'Maxi',
        ]);
        $submission = Submission::get()->byID($submission->ID);
        $this->assertSame('Ruth Corrected', $submission->value('name'));
        $this->assertSame(5, $submission->value('years'));
        $this->assertSame('Maxi', $submission->value('siblings.0.first_name'));
        $this->assertSame('Hamburg', $submission->value('address.city'));
        $this->assertTrue($submission->value('consent'));
        $this->assertSame('Ruth Corrected', $submission->Summary);

        $this->post('intake/recordaction/' . $submission->ID, [
            'RecordAction' => 'takeover',
            'Option' => (string) $existing->ID,
            'SecurityID' => SecurityToken::inst()->getValue(),
        ]);
        $submission = Submission::get()->byID($submission->ID);
        $this->assertSame(Submission::TAKEN_OVER, $submission->Status);
        $this->assertSame(
            [['class' => TestParticipant::class, 'id' => $existing->ID, 'title' => 'Ruth Corrected']],
            $submission->takeoverLinks()
        );
        $participant = TestParticipant::get()->byID($existing->ID);
        $this->assertSame('Ruth Corrected', $participant->Name);
        $this->assertSame(['other.pdf'], $submission->Files()->column('OriginalName'), 'photo claimed');

        $discarded = Submission::create(['Type' => 'registration', 'Reference' => 'X-1', 'Year' => 1, 'Number' => 1]);
        $discarded->storeValues($this->registration(['name' => 'Gone']))->write();
        IntakeService::singleton()->discard($discarded);

        DBDatetime::set_mock_now(date('Y-m-d H:i:s', time() + 15 * 86400));
        try {
            $this->assertSame(1, IntakeService::singleton()->applyRetention(), 'discarded after 10 days');
        } finally {
            DBDatetime::clear_mock_now();
        }
        DBDatetime::set_mock_now(date('Y-m-d H:i:s', time() + 31 * 86400));
        try {
            $this->assertSame(1, IntakeService::singleton()->applyRetention(), 'taken over after 30 days');
        } finally {
            DBDatetime::clear_mock_now();
        }
        $this->assertSame(0, Submission::get()->count());
        $this->assertSame(0, SubmissionFile::get()->count());
        $remaining = Versioned::get_by_stage(File::class, Versioned::DRAFT)
            ->exclude('ClassName', Folder::class)->column('ID');

        $this->assertSame([(int) $participant->PhotoID], array_map('intval', $remaining), 'claimed photo stays');
    }

    public function testTakeoverRefusedAndOpenApi(): void
    {
        $answer = $this->json($this->submit('registration', ['data' => $this->registration(['name' => 'Refused'])]));
        $submission = Submission::get()->find('Reference', $answer['reference']);
        $this->logInWithPermission('FDK_INTAKE_EDIT');
        $this->post('intake/recordaction/' . $submission->ID, [
            'RecordAction' => 'takeover',
            'Option' => 'new',
            'SecurityID' => SecurityToken::inst()->getValue(),
        ]);
        $this->assertSame(Submission::NEW, Submission::get()->byID($submission->ID)->Status);
        $this->assertSame(0, TestParticipant::get()->count(), 'rolled back');

        $document = $this->json(Director::test('api/openapi.json', [], [], 'GET', null, [
            'Authorization' => 'Bearer ' . $this->key,
        ]));
        $this->assertSame(['/api/intake/registration'], array_keys($document['paths']));
        $post = $document['paths']['/api/intake/registration']['post'];
        $schema = $post['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['name', 'email', 'consent'], $schema['properties']['data']['required']);
        $this->assertSame(2, $schema['properties']['files']['properties']['photo']['maxItems']);
    }
}
