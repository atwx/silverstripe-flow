<?php

namespace Atwx\Flow\Numbering;

use RuntimeException;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\DB;

/**
 * Draws numbers from a sequence without duplicates, also when several
 * requests draw at the same time (a database lock per sequence).
 *
 *   NumberSequences::singleton()->next('invoice', '2026');   // 1, 2, 3 …
 *
 * A sequence starts at the highest number already in use when its row is
 * created ($current), so existing records keep their numbers.
 */
class NumberSequences
{
    use Injectable;

    /**
     * Seconds to wait for the lock of a sequence.
     */
    private const int LOCK_TIMEOUT = 10;

    /**
     * The next number of the sequence in the period.
     *
     * @param callable(): int|null $current the highest number in use, read once
     *     when the sequence is created
     */
    public function next(string $code, string $period = '', ?callable $current = null): int
    {
        $lock = $this->lockName($code, $period);
        $connection = DB::get_conn();
        if (!$connection->getLock($lock, self::LOCK_TIMEOUT)) {
            throw new RuntimeException(sprintf('Number sequence %s is locked', $code));
        }
        try {
            $sequence = $this->sequence($code, $period, $current);
            $sequence->LastNumber++;
            $sequence->write();
            return $sequence->LastNumber;
        } finally {
            $connection->releaseLock($lock);
        }
    }

    /**
     * The number next() would return, without drawing it.
     *
     * @param callable(): int|null $current
     */
    public function peek(string $code, string $period = '', ?callable $current = null): int
    {
        $sequence = NumberSequence::get()->filter(['Code' => $code, 'Period' => $period])->first();
        if ($sequence instanceof NumberSequence) {
            return $sequence->LastNumber + 1;
        }
        return ($current ? (int) $current() : 0) + 1;
    }

    /**
     * Sets the last number, e.g. to continue an old system (the next number
     * drawn is $last + 1).
     */
    public function setLast(string $code, string $period, int $last): void
    {
        $sequence = $this->sequence($code, $period, null);
        $sequence->LastNumber = $last;
        $sequence->write();
    }

    /**
     * @param callable(): int|null $current
     */
    private function sequence(string $code, string $period, ?callable $current): NumberSequence
    {
        $sequence = NumberSequence::get()->filter(['Code' => $code, 'Period' => $period])->first();
        if ($sequence instanceof NumberSequence) {
            return $sequence;
        }
        return NumberSequence::create([
            'Code' => $code,
            'Period' => $period,
            'LastNumber' => $current ? (int) $current() : 0,
        ]);
    }

    private function lockName(string $code, string $period): string
    {
        // MySQL lock names are limited to 64 characters
        return 'flow-seq-' . substr(sha1($code . '|' . $period), 0, 40);
    }
}
