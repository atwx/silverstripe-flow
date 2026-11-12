<?php

namespace Atwx\Flow\Tests\Service;

use Atwx\Flow\Actor\MemberActor;
use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Controller\CaseMailSubController;
use Atwx\Flow\Document\PdfRenderer;
use Atwx\Flow\Extension\CaseFileExtension;
use Atwx\Flow\Mail\RecipientResolver;
use Atwx\Flow\Model\CaseDocument;
use Atwx\Flow\Model\CaseMail;
use Atwx\Flow\Model\ChecklistEntry;
use Atwx\Flow\Model\MailTemplate;
use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Status;
use Atwx\Flow\Service\AssignmentService;
use Atwx\Flow\Service\ChecklistService;
use Atwx\Flow\Service\MailService;
use Atwx\Flow\Service\TransitionService;
use Atwx\Flow\Tests\Stubs\FakePdfRenderer;
use Atwx\Flow\Tests\Stubs\TestApplication;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestCourseController;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\i18n\i18n;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Group;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

class MailTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [
        TestCourse::class,
        TestApplication::class,
    ];

    private Member $olga;

    protected function setUp(): void
    {
        parent::setUp();
        i18n::set_locale('de_DE');
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        TestAssetStore::activate('flow-mails');
        Injector::inst()->registerService(new FakePdfRenderer(), PdfRenderer::class);
        MailService::config()->set('from', 'flow@example.org');

        $this->olga = Member::create(['Email' => 'olga@example.com', 'FirstName' => 'Olga']);
        $this->olga->write();
        $group = Group::get()->find('Code', 'organisers');
        $this->assertNotNull($group);
        $this->olga->Groups()->add($group);
        Permission::grant($group->ID, TestCourseController::singleton()->getPermissionCode('EDIT'));
        $this->logInAs($this->olga);
    }

    protected function tearDown(): void
    {
        TestAssetStore::reset();
        parent::tearDown();
    }

    private function course(string $status = 'draft'): TestCourse
    {
        $course = TestCourse::create(['Title' => 'Paläographie', 'Venue' => 'Marburg']);
        $statusID = Status::get()->filter(['Code' => $status, 'Workflow.Code' => 'course'])->first()?->ID;
        CaseFileExtension::writeStatus($course, (int) $statusID);
        return $course;
    }

    private function template(string $code): MailTemplate
    {
        $template = MailTemplate::get()->find('Code', $code);
        $this->assertNotNull($template);
        return $template;
    }

    public function testDraftResolvesRecipientsPlaceholdersAndAttachments(): void
    {
        $course = $this->course();
        $lead = Member::create(['Email' => 'Lena@Example.com', 'FirstName' => 'Lena']);
        $lead->write();
        $lead->Groups()->add(Group::get()->find('Code', 'organisers'));
        $responsibility = Responsibility::get()->find('Code', 'lead');
        $this->assertNotNull($responsibility);
        AssignmentService::singleton()->assign($course, $responsibility, $lead, $this->olga);

        $draft = MailService::singleton()->draft($course, $this->template('confirmation'), $this->olga);

        $this->assertSame(['lena@example.com', 'info@example.org'], $draft->to);
        $this->assertSame(['olga@example.com'], $draft->cc);
        $this->assertSame('Bestätigung: Paläographie', $draft->subject);
        $this->assertSame("Der Kurs Paläographie hat den Status Entwurf.\nBis bald!", $draft->body);
        $this->assertCount(1, $draft->documentTemplateIDs);
    }

    public function testSendRecordsTheMailWithAttachmentAndTimeline(): void
    {
        $course = $this->course();
        $draft = MailService::singleton()->draft($course, $this->template('confirmation'), $this->olga);

        $mail = MailService::singleton()->send($course, $draft, $this->olga);

        $this->assertSame(CaseMail::SENT, $mail->Mode);
        $sent = $this->findEmail('info@example.org', 'flow@example.org', 'Bestätigung: Paläographie');
        $this->assertNotNull($sent);
        $this->assertStringEndsWith('.pdf', $mail->AttachmentNames);
        $this->assertSame(1, CaseDocument::get()->filter(['CaseID' => $course->ID])->count(), 'the PDF is kept');

        $titles = TestCourseController::create()->timeline($course)?->column('Title') ?? [];
        $this->assertContains('Mail gesendet: Bestätigung: Paläographie', $titles);
    }

    public function testAFailingTransportIsRecordedNotThrown(): void
    {
        Injector::inst()->registerService(new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('Connection refused');
            }
        }, MailerInterface::class);
        $course = $this->course();
        $draft = MailService::singleton()->draft($course, $this->template('closed'), $this->olga);

        $mail = MailService::singleton()->send($course, $draft, $this->olga);

        $this->assertSame(CaseMail::FAILED, $mail->Mode);
        $this->assertStringContainsString('Connection refused', $mail->Error);
        $this->assertTrue($mail->isInDB());
    }

    public function testMailIsSentAutomaticallyWhenTheStatusIsEntered(): void
    {
        $course = $this->course('review');
        ChecklistService::singleton()->ensureEntries($course);
        foreach (ChecklistEntry::get()->filter('CaseID', $course->ID) as $entry) {
            $entry->State = 'not_needed';
            $entry->write();
        }
        $actor = MemberActor::current();
        $this->assertNotNull($actor);

        TransitionService::singleton()->transition($course, 'done', $actor);

        $this->assertNotNull($this->findEmail('info@example.org', null, 'Abgeschlossen: Paläographie'));
        $mail = CaseMail::get()->filter('CaseID', $course->ID)->first();
        $this->assertNotNull($mail);
        $this->assertTrue((bool) $mail->Automatic);
    }

    public function testMailFileForTheMailProgram(): void
    {
        $course = $this->course();
        $draft = MailService::singleton()->draft($course, $this->template('closed'), $this->olga);

        [$mail, $raw] = MailService::singleton()->eml($course, $draft, $this->olga);

        $this->assertSame(CaseMail::EML, $mail->Mode);
        $this->assertStringContainsString('X-Unsent: 1', $raw);
        $this->assertStringContainsString('info@example.org', $raw);
        $this->assertNull($this->findEmail('info@example.org'), 'not sent');
    }

    public function testRecipientExpressions(): void
    {
        $course = $this->course();
        $resolver = RecipientResolver::singleton();
        $this->assertSame(
            ['a@example.org', 'olga@example.com'],
            $resolver->resolve(
                'A@example.org; not-an-address, {{Me}}, a@example.org, Unknown.Field',
                $course,
                $this->olga
            )
        );
        $this->assertSame([['a@example.org'], ['x@']], $resolver->parseAddresses('a@example.org, x@'));
    }

    public function testComposeNeedsTheEditPermissionAndRefusesInvalidAddresses(): void
    {
        $course = $this->course();
        $sub = $this->composer($course, ['TemplateID' => $this->template('closed')->ID]);
        $this->assertTrue($sub->canCompose());

        $form = $sub->mailForm();
        $data = [
            'TemplateID' => $this->template('closed')->ID,
            'Recipients' => 'info@example.org, kaputt',
            'Subject' => 'Test',
            'Body' => 'Text',
        ];
        $sub->doSend($data, $form);
        $this->assertSame(0, CaseMail::get()->count(), 'refused');

        $data['Recipients'] = 'info@example.org';
        $sub = $this->composer($course, $data);
        $sub->doSend($data, $sub->mailForm());
        $this->assertNotNull($this->findEmail('info@example.org', null, 'Test'));

        $reader = Member::create(['Email' => 'lena@example.com']);
        $reader->write();
        $readers = Group::get()->find('Code', 'readers');
        $this->assertNotNull($readers);
        $reader->Groups()->add($readers);
        Permission::grant($readers->ID, TestCourseController::singleton()->getPermissionCode('VIEW'));
        $this->logInAs($reader);
        $this->assertFalse($this->composer($course, [])->canCompose());
    }

    /**
     * @param array<string, mixed> $post
     */
    private function composer(TestCourse $course, array $post): CaseMailSubController
    {
        $parent = TestCourseController::create();
        $request = new HTTPRequest('POST', 'test-courses/mails/' . $course->ID . '/MailForm', [], $post);
        $request->setSession(new Session([]));
        $parent->setRequest($request);
        $sub = CaseMailSubController::create()->setParentContext($parent, (int) $course->ID);
        $sub->setRequest($request);
        return $sub;
    }
}
