<?php

namespace Atwx\Flow\Tests\Model;

use Atwx\Flow\Config\ConfigImporter;
use Atwx\Flow\Intake\IntakeService;
use Atwx\Flow\Model\DocumentTemplate;
use Atwx\Flow\Model\MailTemplate;
use Atwx\Flow\Model\TextBlock;
use Atwx\Flow\Tests\Stubs\TestCourse;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Validation\ValidationException;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DataObject;

class TextBlockTest extends SapphireTest
{
    protected $usesDatabase = true;

    /**
     * @var array<int, class-string<DataObject>>
     */
    protected static $extra_dataobjects = [TestCourse::class];

    public function testUsagesLockTheCodeAndPreventDeletion(): void
    {
        ConfigImporter::create()->importFile(__DIR__ . '/../Config/config.yml');
        $intro = TextBlock::create(['Code' => 'Greeting', 'Title' => 'Einleitung', 'Body' => '<p>Hallo</p>']);
        $intro->write();
        $free = TextBlock::create(['Code' => 'Free', 'Title' => 'Frei', 'Body' => '<p>-</p>']);
        $free->write();
        $this->assertCount(0, $intro->usages());

        $document = DocumentTemplate::get()->find('Code', 'confirmation');
        $this->assertNotNull($document);
        $document->Body = '<p>{{Block.Greeting}}</p>';
        $document->write();
        $mail = MailTemplate::get()->find('Code', 'confirmation');
        $this->assertNotNull($mail);
        $mail->Body = "{{Block.Greeting|}}\n{{Block.Greetings}}";
        $mail->write();
        TextBlock::create(['Code' => 'Outer', 'Title' => 'Außen', 'Body' => '{{Block.Greeting}}'])->write();
        TextBlock::create(['Code' => 'Case', 'Title' => 'Klein', 'Body' => '{{Block.greeting}}'])->write();
        Config::modify()->set(IntakeService::class, 'types', [
            'feedback' => ['title' => 'Rückmeldung', 'fields' => [], 'notify' => ['block' => 'Greeting']],
        ]);

        $usages = $intro->usages();
        $this->assertCount(4, $usages);
        $this->assertStringStartsWith('Document: Bestätigung', $usages[0]);
        $this->assertStringStartsWith('Mail: ', $usages[1]);
        $this->assertSame('Text block: Außen', $usages[2]);
        $this->assertSame('Form: Rückmeldung (notify)', $usages[3]);

        $intro->Code = 'Opening';
        try {
            $intro->write();
            $this->fail('code locked');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Text block: Außen', $e->getMessage());
        }
        $intro->Code = 'Greeting';
        $intro->Title = 'Einleitung neu';
        $intro->write();

        $free->Code = 'Free_Text';
        $free->write();
        $this->assertSame('Free_Text', TextBlock::get()->byID($free->ID)?->Code, 'unused: free to rename');

        $this->logInWithPermission('ADMIN');
        $this->assertFalse($intro->canDelete());
        $this->assertTrue($free->canDelete());
    }
}
