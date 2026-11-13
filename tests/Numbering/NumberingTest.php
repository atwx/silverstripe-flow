<?php

namespace Atwx\Flow\Tests\Numbering;

use Atwx\Flow\Numbering\NumberSequence;
use Atwx\Flow\Numbering\NumberSequences;
use Atwx\Flow\Tests\Stubs\NumberedDraft;
use Atwx\Flow\Tests\Stubs\NumberedInvoice;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDatetime;

class NumberingTest extends SapphireTest
{
    protected $usesDatabase = true;

    protected static $extra_dataobjects = [NumberedInvoice::class, NumberedDraft::class];

    public function testSequencesCountPerCodeAndPeriod(): void
    {
        $sequences = NumberSequences::singleton();
        $this->assertSame(1, $sequences->next('a', '2026'));
        $this->assertSame(2, $sequences->next('a', '2026'));
        $this->assertSame(1, $sequences->next('a', '2027'), 'own count per year');
        $this->assertSame(1, $sequences->next('b'), 'own count per code');
        $this->assertSame(3, $sequences->peek('a', '2026'), 'peek does not draw');
        $this->assertSame(3, $sequences->next('a', '2026'));
        $this->assertSame(11, $sequences->next('c', '', fn () => 10), 'starts after the highest number in use');
        $sequences->setLast('a', '2026', 500);
        $this->assertSame(501, $sequences->next('a', '2026'));
        $this->assertSame(1, NumberSequence::get()->filter(['Code' => 'a', 'Period' => '2026'])->count());
    }

    public function testRecordGetsItsNumberOnceOnTheFirstWrite(): void
    {
        DBDatetime::set_mock_now('2026-12-30 10:00:00');
        $first = NumberedInvoice::create(['InvoiceDate' => '2026-12-30']);
        $first->write();
        $second = NumberedInvoice::create(['InvoiceDate' => '2026-12-31']);
        $second->write();
        $this->assertSame([1, 2], [$first->Number, $second->Number]);
        $this->assertSame('RE-2026-0002', $second->Reference);
        $this->assertSame('RE-2026-0002', $second->getFormattedNumber());

        $next = NumberedInvoice::create(['InvoiceDate' => '2027-01-02']);
        $next->write();
        $this->assertSame([1, 2027], [$next->Number, $next->NumberYear], 'new year, new count');

        $second->InvoiceDate = '2027-03-01';
        $second->write();
        $this->assertSame(
            [2, 2026, 'RE-2026-0002'],
            [$second->Number, $second->NumberYear, $second->Reference],
            'fixed'
        );
        DBDatetime::clear_mock_now();
    }

    public function testExistingNumbersAreContinued(): void
    {
        NumberedInvoice::create(['Number' => 41, 'NumberYear' => 2026, 'InvoiceDate' => '2026-01-01'])->write();
        $invoice = NumberedInvoice::create(['InvoiceDate' => '2026-02-01']);
        $invoice->write();
        $this->assertSame(42, $invoice->Number);
    }

    public function testManualAssignment(): void
    {
        DBDatetime::set_mock_now('2026-05-01 10:00:00');
        $draft = NumberedDraft::create();
        $draft->write();
        $this->assertFalse($draft->hasNumber(), 'manual: no number on write');
        $this->assertSame('', $draft->getFormattedNumber());
        $this->assertTrue($draft->assignNumber());
        $this->assertFalse($draft->assignNumber(), 'only once');
        $draft->write();
        $this->assertSame('A26/1', $draft->getFormattedNumber());
        DBDatetime::clear_mock_now();
    }

    public function testNumberFieldsAreReadonlyInTheCMS(): void
    {
        $fields = NumberedInvoice::create()->getCMSFields();
        $this->assertTrue($fields->dataFieldByName('Number')?->isReadonly());
        $this->assertTrue($fields->dataFieldByName('Reference')?->isReadonly());
        $this->assertFalse($fields->dataFieldByName('InvoiceDate')?->isReadonly());
    }
}
