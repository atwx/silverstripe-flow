<?php

namespace Atwx\Flow\Tests\Retention;

use Atwx\Flow\Cli\RetentionCommand;
use Atwx\Flow\Model\Comment;
use Atwx\Flow\Retention\DeletionRun;
use Atwx\Flow\Retention\DeletionRunController;
use Atwx\Flow\Retention\Retention;
use Atwx\Flow\Tests\Stubs\ArchivedLetter;
use Atwx\Flow\Tests\Stubs\KeepVenueRule;
use Atwx\Flow\Tests\Stubs\TestCourse;
use Atwx\Flow\Tests\Stubs\TestParticipant;
use Atwx\SilverstripeFrontdeskKit\Model\LastView;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\FunctionalTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\Security\Member;
use SilverStripe\Security\SecurityToken;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class RetentionTest extends FunctionalTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [TestCourse::class, TestParticipant::class, ArchivedLetter::class];

    protected function setUp(): void
    {
        parent::setUp();
        Config::modify()->set(Retention::class, 'rules', [
            'courses' => [
                'title' => 'Alte Kurse',
                'description' => 'Zwei Jahre nach dem Anlegen',
                'class' => TestCourse::class,
                'months' => 24,
                'exclude' => ['Title' => 'Archiv'],
                'rule' => KeepVenueRule::class,
            ],
            'letters' => [
                'title' => 'Briefe',
                'class' => ArchivedLetter::class,
                'days' => 30,
                'automatic' => true,
            ],
        ]);
        Config::modify()->merge(Director::class, 'rules', [
            'deletion-runs//$Action/$ID' => DeletionRunController::class,
        ]);
    }

    protected function tearDown(): void
    {
        DBDatetime::clear_mock_now();
        parent::tearDown();
    }

    private function course(string $title, string $created, string $venue = ''): TestCourse
    {
        DBDatetime::set_mock_now($created . ' 10:00:00');
        $course = TestCourse::create(['Title' => $title, 'Venue' => $venue]);
        $course->write();
        DBDatetime::set_mock_now('2026-11-13 10:00:00');
        return $course;
    }

    public function testPreviewRunCascadeAndLog(): void
    {
        $old = $this->course('Alt', '2023-01-10');
        $kept = $this->course('Alt in Marburg', '2023-02-10', 'Marburg');
        $archive = $this->course('Archiv', '2023-03-10');
        $new = $this->course('Neu', '2026-01-10');
        $comment = Comment::create(['RecordClass' => TestCourse::class, 'RecordID' => $old->ID, 'Text' => 'Notiz']);
        $comment->write();
        $member = $this->logInWithPermission('ADMIN');
        LastView::note($old, Member::get()->byID($member));

        $retention = Retention::singleton();
        $rule = $retention->rule('courses');
        $this->assertNotNull($rule);
        $this->assertSame('2024-11-13', $rule->cutoff());
        $preview = $retention->preview($rule);
        $this->assertSame(2, $preview['count'], 'excluded and new ones are not due');
        $this->assertSame(
            ['Alt (#' . $old->ID . ')', 'Alt in Marburg (#' . $kept->ID . ') – Marburg'],
            $preview['labels']
        );

        $run = $retention->run($rule, Member::get()->byID($member));
        $this->assertSame([1, 1, 0], [$run->Deleted, $run->Kept, $run->Failed]);
        $this->assertSame(['Marburg' => 1], $run->keptReasons());
        $this->assertSame($member, $run->MemberID);
        $this->assertNull(TestCourse::get()->byID($old->ID));
        $this->assertNotNull(TestCourse::get()->byID($kept->ID));
        $this->assertNotNull(TestCourse::get()->byID($archive->ID));
        $this->assertNotNull(TestCourse::get()->byID($new->ID));
        $this->assertNull(Comment::get()->byID($comment->ID), 'cascade');
        $this->assertSame(0, LastView::get()->filter('RecordID', $old->ID)->count(), 'recently viewed');
        $this->assertSame(1, DeletionRun::get()->count());
    }

    public function testVersionedRecordsLoseTheirHistoryAndAutomaticRulesRunDaily(): void
    {
        DBDatetime::set_mock_now('2026-09-01 10:00:00');
        $letter = ArchivedLetter::create(['Title' => 'Alt']);
        $letter->write();
        $letter->publishSingle();
        $letter->Title = 'Alt, geändert';
        $letter->write();
        DBDatetime::set_mock_now('2026-11-13 10:00:00');
        $this->assertGreaterThan(0, $this->versions($letter->ID));

        $output = new BufferedOutput();
        (new RetentionCommand())->run(new ArrayInput([]), $output);
        $this->assertStringContainsString('Briefe: 1 deleted', $output->fetch());
        $this->assertSame(0, $this->versions($letter->ID), 'history purged');
        $this->assertNull(ArchivedLetter::get()->byID($letter->ID));
        $run = DeletionRun::get()->filter('Rule', 'letters')->first();
        $this->assertTrue((bool) $run?->Automatic);
        $this->assertSame(0, DeletionRun::get()->filter('Rule', 'courses')->count(), 'manual rules not by cron');
    }

    public function testPageRequiresPermissionAndPostWithToken(): void
    {
        $old = $this->course('Alt', '2023-01-10');
        $this->logInWithPermission('FDK_DELETION_RUNS_VIEW');
        $page = $this->get('deletion-runs');
        $this->assertSame(200, $page->getStatusCode());
        $this->assertStringContainsString('Alte Kurse', (string) $page->getBody());
        $preview = $this->get('deletion-runs/rule/courses');
        $this->assertStringContainsString('Alt (#' . $old->ID . ')', (string) $preview->getBody());
        $body = (string) $preview->getBody();
        $this->assertStringNotContainsString('deletion-runs/execute/courses', $body, 'no run without edit');
        $this->assertSame(403, $this->post('deletion-runs/execute/courses', [])->getStatusCode());
        $this->assertSame(404, $this->get('deletion-runs/rule/unknown')->getStatusCode());
    }

    public function testRunWithEditPermission(): void
    {
        $old = $this->course('Alt', '2023-01-10');
        $this->logInWithPermission('FDK_DELETION_RUNS_EDIT');
        $body = (string) $this->get('deletion-runs/rule/courses')->getBody();
        $this->assertStringContainsString('action="deletion-runs/execute/courses"', $body);
        SecurityToken::enable();
        $this->assertSame(400, $this->get('deletion-runs/execute/courses')->getStatusCode(), 'GET');
        $this->assertSame(400, $this->post('deletion-runs/execute/courses', [])->getStatusCode(), 'no token');
        $this->post('deletion-runs/execute/courses', ['SecurityID' => SecurityToken::inst()->getValue()]);
        SecurityToken::disable();
        $this->assertNull(TestCourse::get()->byID($old->ID));
    }

    private function versions(int $id): int
    {
        return (int) DB::prepared_query(
            'SELECT COUNT(*) FROM "FlowTest_ArchivedLetter_Versions" WHERE "RecordID" = ?',
            [$id]
        )->value();
    }
}
