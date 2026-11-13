<?php

namespace Atwx\Flow\Tests\Contact;

use Atwx\Flow\Contact\ContactChannel;
use Atwx\Flow\Contact\ContactChannelSubController;
use Atwx\Flow\Contact\ContactPerson;
use Atwx\Flow\Contact\ContactPersonSubController;
use Atwx\Flow\Tests\Stubs\TestSchool;
use Atwx\Flow\Tests\Stubs\TestSchoolController;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\Session;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\TextField;

class ContactTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [TestSchool::class];

    private function school(): TestSchool
    {
        $school = TestSchool::create(['Title' => 'Schule am See']);
        $school->write();
        return $school;
    }

    private function channel(TestSchool $school, string $kind, string $type, string $value, bool $mailings = true): void
    {
        ContactChannel::create([
            'OwnerClass' => TestSchool::class,
            'OwnerID' => $school->ID,
            'Kind' => $kind,
            'Type' => $type,
            'Value' => $value,
            'Mailings' => $mailings,
        ])->write();
    }

    public function testHelpersOrderAndSync(): void
    {
        $school = $this->school();
        $this->channel($school, ContactChannel::PHONE, 'Mobil', '0170 1234');
        $this->channel($school, ContactChannel::PHONE, 'Fax dienstlich', '040 99');
        $this->channel($school, ContactChannel::PHONE, 'Privat', '040 12 34');
        $this->channel($school, ContactChannel::EMAIL, 'Dienstlich', 'sekretariat@example.org');
        $this->channel($school, ContactChannel::EMAIL, 'Privat', 'leitung@example.org', false);

        $this->assertSame(5, $school->ContactChannels()->count());
        $this->assertSame('sekretariat@example.org', $school->getMailingEmails(), 'only for mailings');
        $this->assertSame('040 12 34; 0170 1234', $school->phonesOfType(), 'type order, no fax');
        $this->assertSame('040 99', $school->phonesOfType(['Fax dienstlich']));
        $this->assertSame(
            ['leitung@example.org', 'sekretariat@example.org'],
            $school->emailChannels()->column('Value'),
            'in the order of the types'
        );

        $school = TestSchool::get()->byID($school->ID);
        $this->assertSame('leitung@example.org', $school->Email, 'synced to the owner');
        $this->assertSame('040 12 34', $school->Phone);
        $school->ContactChannels()->filter('Value', '040 12 34')->first()?->delete();
        $this->assertSame('0170 1234', TestSchool::get()->byID($school->ID)?->Phone, 'sync after delete');

        $phone = $school->phoneChannels()->first();
        $this->assertSame('<a href="tel:01701234">0170 1234</a>', (string) $phone?->getValueLink());
        $this->assertFalse((bool) $phone?->Mailings, 'mailings only for e-mail');
    }

    public function testValidation(): void
    {
        $channel = ContactChannel::create(['Kind' => ContactChannel::EMAIL, 'Value' => 'keine-adresse']);
        $this->assertFalse($channel->validate()->isValid());
        $channel = ContactChannel::create(['Kind' => ContactChannel::PHONE, 'Value' => 'abc']);
        $this->assertFalse($channel->validate()->isValid());
        $channel = ContactChannel::create(['Kind' => ContactChannel::PHONE, 'Value' => '+49 (40) 123-45/6']);
        $this->assertTrue($channel->validate()->isValid());
        $this->assertFalse(ContactPerson::create()->validate()->isValid(), 'name or function');
        $this->assertTrue(ContactPerson::create(['Function' => 'Sekretariat'])->validate()->isValid());
    }

    public function testContactPersonsWithRolesAndCascade(): void
    {
        Config::modify()->set(ContactPerson::class, 'roles', ['invoices' => 'Rechnungen', 'visits' => 'Besuche']);
        $school = $this->school();
        $anna = ContactPerson::create([
            'OwnerClass' => TestSchool::class,
            'OwnerID' => $school->ID,
            'FirstName' => 'Anna',
            'Surname' => 'Berg',
            'Function' => 'Sekretariat',
        ]);
        $anna->setRoles(['visits', 'invoices', 'unknown']);
        $anna->write();
        $ben = ContactPerson::create([
            'OwnerClass' => TestSchool::class,
            'OwnerID' => $school->ID,
            'FirstName' => 'Ben',
            'Surname' => 'Arndt',
            'Roles' => 'visits',
        ]);
        $ben->write();

        $this->assertSame('["visits","invoices"]', $anna->Roles);
        $this->assertSame(['visits'], ContactPerson::get()->byID($ben->ID)?->roleCodes(), 'normalised on write');
        $this->assertSame('Besuche, Rechnungen', $anna->getRolesLabel());
        $this->assertSame('Anna Berg (Sekretariat)', $anna->getTitle());
        $this->assertSame((int) $anna->ID, (int) $school->contactFor('invoices')?->ID);
        $this->assertSame(['Ben', 'Anna'], $school->contactsFor('visits')->sort('Surname')->column('FirstName'));
        $this->assertNull($school->contactFor('board'));

        $this->channel($school, ContactChannel::EMAIL, 'Dienstlich', 'info@example.org');
        $school->delete();
        $this->assertSame(0, ContactPerson::get()->count(), 'cascade');
        $this->assertSame(0, ContactChannel::get()->count(), 'cascade');
    }

    public function testSubListsSaveWithTheOwner(): void
    {
        $school = $this->school();
        $sub = $this->sub(ContactChannelSubController::class, $school);
        $form = $sub->EditForm();
        $this->assertInstanceOf(DropdownField::class, $form->Fields()->dataFieldByName('Type'), 'fixed types');
        $data = [
            'Kind' => ContactChannel::EMAIL,
            'Type' => 'Dienstlich',
            'Value' => 'post@example.org',
            'Mailings' => 1,
        ];
        $form->loadDataFrom($data);
        $sub->save($data, $form);
        $channel = ContactChannel::get()->find('Value', 'post@example.org');
        $this->assertSame([TestSchool::class, (int) $school->ID], [$channel?->OwnerClass, (int) $channel?->OwnerID]);
        $this->assertSame(1, $sub->Items()->getTotalItems());
        $this->assertNotNull($channel);
        $cell = (string) $sub->Columns()->byName('ValueLink')?->renderValue($channel);
        $this->assertSame('<a href="mailto:post@example.org">post@example.org</a>', $cell, 'link, not escaped');

        Config::modify()->set(TestSchool::class, 'flow_contact_free_types', true);
        $this->assertInstanceOf(
            TextField::class,
            $this->sub(ContactChannelSubController::class, $school)->EditForm()->Fields()->dataFieldByName('Type')
        );

        Config::modify()->set(ContactPerson::class, 'roles', ['invoices' => 'Rechnungen']);
        $persons = $this->sub(ContactPersonSubController::class, $school);
        $this->assertInstanceOf(CheckboxSetField::class, $persons->EditForm()->Fields()->dataFieldByName('Roles'));
        $other = $this->school();
        $items = $this->sub(ContactChannelSubController::class, $other)->Items();
        $this->assertSame(0, $items->getTotalItems(), 'per owner');
    }

    /**
     * @template T of ContactChannelSubController|ContactPersonSubController
     * @param class-string<T> $class
     * @return T
     */
    private function sub(string $class, TestSchool $school): ContactChannelSubController|ContactPersonSubController
    {
        $parent = TestSchoolController::create();
        $request = new HTTPRequest('POST', 'test-schools/contactchannels/' . $school->ID . '/save');
        $request->setSession(new Session([]));
        $parent->setRequest($request);
        $sub = $class::create()->setParentContext($parent, (int) $school->ID);
        $sub->setRequest($request);
        return $sub;
    }
}
