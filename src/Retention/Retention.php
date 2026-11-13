<?php

namespace Atwx\Flow\Retention;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\Queries\SQLDelete;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;
use Throwable;

/**
 * Deletion runs: deletes records whose retention period has passed, by rule,
 * with everything that hangs on them (cascade_deletes of the model; the case
 * file, comments, mails and documents of flow), and logs each run in
 * DeletionRun. Versioned records are removed with their history.
 *
 *   Atwx\Flow\Retention\Retention:
 *     rules:
 *       registrations:
 *         title: 'Anmeldungen vergangener Veranstaltungen'
 *         description: 'Zwölf Monate nach der Veranstaltung'
 *         class: 'App\Models\Registration'
 *         date_field: 'Event.StartDate'   # a field or relation path (default Created)
 *         months: 12                      # and/or days
 *         filter: { Status: 'done' }      # optional
 *         exclude: { Keep: true }         # optional
 *         automatic: true                 # also run by the daily flow:retention
 *         rule: 'App\Retention\MyRule'    # optional RetentionRule subclass
 *
 * Extension hooks: onBeforeRetentionDelete($record, $rule),
 * onAfterRetentionDelete($class, $id, $rule).
 */
class Retention
{
    use Configurable;
    use Extensible;
    use Injectable;

    /**
     * @var array<string, array<string, mixed>|null>
     */
    private static array $rules = [];

    /**
     * Records shown in the preview.
     */
    private static int $preview_limit = 50;

    /**
     * @return array<string, RetentionRule>
     */
    public function rules(): array
    {
        $rules = [];
        foreach ((array) static::config()->get('rules') as $name => $settings) {
            if (!$settings) {
                continue;
            }
            $class = (string) ($settings['rule'] ?? RetentionRule::class);
            if (!is_a($class, RetentionRule::class, true)) {
                throw new InvalidArgumentException(sprintf('Retention rule %s: %s is no RetentionRule', $name, $class));
            }
            $rules[(string) $name] = new $class((string) $name, $settings);
        }
        return $rules;
    }

    public function rule(string $name): ?RetentionRule
    {
        return $this->rules()[$name] ?? null;
    }

    /**
     * Due records of a rule: their number and the first ones as labels.
     *
     * @return array{count: int, labels: list<string>}
     */
    public function preview(RetentionRule $rule): array
    {
        $candidates = $rule->candidates();
        $labels = [];
        foreach ($candidates->limit((int) static::config()->get('preview_limit')) as $record) {
            $reason = $rule->keepReason($record);
            $labels[] = $rule->label($record) . ($reason !== null ? ' – ' . $reason : '');
        }
        return ['count' => $candidates->count(), 'labels' => $labels];
    }

    /**
     * Deletes the due records of the rule and logs the run.
     */
    public function run(RetentionRule $rule, ?Member $member = null, bool $automatic = false): DeletionRun
    {
        $run = DeletionRun::create([
            'Rule' => $rule->getName(),
            'RuleTitle' => $rule->getTitle(),
            'Cutoff' => $rule->cutoff(),
            'Automatic' => $automatic,
            'MemberID' => $member ? $member->ID : 0,
            'Deleted' => 0,
            'Kept' => 0,
            'Failed' => 0,
        ]);
        $kept = [];
        // IDs first: deleting while iterating the list would skip records
        foreach ($rule->candidates()->column('ID') as $id) {
            $record = DataObject::get_by_id($rule->modelClass(), (int) $id);
            if (!$record) {
                continue;
            }
            $reason = $rule->keepReason($record);
            if ($reason !== null) {
                $kept[$reason] = ($kept[$reason] ?? 0) + 1;
                $run->Kept++;
                continue;
            }
            try {
                $this->delete($record, $rule);
                $run->Deleted++;
            } catch (Throwable $e) {
                $run->Failed++;
                $this->logger()->error(sprintf(
                    'Deletion run %s: %s #%d not deleted: %s',
                    $rule->getName(),
                    get_class($record),
                    $record->ID,
                    $e->getMessage()
                ));
            }
        }
        $run->Details = $kept ? (string) json_encode($kept) : null;
        $run->write();
        return $run;
    }

    /**
     * Deletes one record with everything that hangs on it, in one transaction.
     */
    public function delete(DataObject $record, ?RetentionRule $rule = null): void
    {
        $class = get_class($record);
        $id = (int) $record->ID;
        $connection = DB::get_conn();
        $connection->transactionStart();
        try {
            $this->extend('onBeforeRetentionDelete', $record, $rule);
            if ($record->hasExtension(Versioned::class)) {
                /** @var DataObject&Versioned $record */
                $record->doArchive();
                $this->purgeVersions($class, $id);
            } else {
                $record->delete();
            }
            $this->forgetViews($class, $id);
            $this->extend('onAfterRetentionDelete', $class, $id, $rule);
            $connection->transactionEnd();
        } catch (Throwable $e) {
            $connection->transactionRollback();
            throw $e;
        }
    }

    /**
     * The history of a versioned record (archive keeps it otherwise).
     */
    private function purgeVersions(string $class, int $id): void
    {
        $schema = DataObject::getSchema();
        foreach (ClassInfo::ancestry($class, true) as $ancestor) {
            $table = $schema->tableName($ancestor) . '_Versions';
            if (DB::get_schema()->hasTable($table)) {
                SQLDelete::create('"' . $table . '"', ['"RecordID"' => $id])->execute();
            }
        }
    }

    /**
     * "Recently viewed" entries of the frontdesk kit.
     */
    private function forgetViews(string $class, int $id): void
    {
        $base = DataObject::getSchema()->baseDataClass($class);
        $views = [
            'Atwx\\SilverstripeFrontdeskKit\\Model\\RecentView' => ['RecordClass' => $class, 'RecordID' => $id],
            'Atwx\\SilverstripeFrontdeskKit\\Model\\LastView' => ['BaseClass' => $base, 'RecordID' => $id],
        ];
        foreach ($views as $model => $filter) {
            if (class_exists($model)) {
                DataObject::get($model)->filter($filter)->removeAll();
            }
        }
    }

    private function logger(): LoggerInterface
    {
        return Injector::inst()->get(LoggerInterface::class);
    }
}
