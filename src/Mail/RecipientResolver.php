<?php

namespace Atwx\Flow\Mail;

use Atwx\Flow\Model\Responsibility;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Service\AssignmentService;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\Model\List\SS_List;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;

/**
 * Resolves recipient expressions of mail templates to addresses. An expression
 * is a comma-, semicolon- or line-separated list of:
 *
 * - addresses: info@example.org
 * - fields of the case file, also through relations: Email, Contact.Email,
 *   Family.Parents.Email (has_one, has_many and many_many; all records)
 * - Assignee.<responsibility>: the member assigned to the responsibility
 * - Me: the member who writes the mail
 * - Item.<field path>: the sub-list entry of a mail per entry (Item.Email,
 *   Item.School.Contact.Email)
 *
 * Placeholder braces are allowed ({{Contact.Email}}). Invalid and duplicate
 * addresses are left out. A domain class may add own tokens with
 * updateFlowMailRecipients(string $token, array &$addresses).
 */
class RecipientResolver
{
    use Injectable;

    /**
     * @param array<int, string>|null $skipped Set to the parts that gave no valid
     *   address (e.g. "Assignee.lead (admin)" or "Contact.Email"), to tell the member
     * @param DataObject|null $item The sub-list entry for Item.<field>; without an
     *   entry these parts are ignored (not reported as skipped)
     * @return array<int, string>
     */
    public function resolve(
        string $expression,
        DataObject $case,
        ?Member $me,
        ?array &$skipped = null,
        ?DataObject $item = null
    ): array {
        $addresses = [];
        $skipped = [];
        foreach (preg_split('/[,;\n]+/', $expression) ?: [] as $token) {
            $token = trim(str_replace(['{{', '}}'], '', $token));
            if ($token === '') {
                continue;
            }
            if (str_starts_with($token, 'Item.')) {
                if (!$item) {
                    continue;
                }
                $found = $this->values([$item], explode('.', substr($token, 5)));
                $this->collect($token, $found, $addresses, $skipped);
                continue;
            }
            $found = $this->token($token, $case, $me);
            // extend() passes arguments by reference: keep the token itself unchanged.
            $hookToken = $token;
            $case->extend('updateFlowMailRecipients', $hookToken, $found);
            $this->collect($token, $found, $addresses, $skipped);
        }
        return $addresses;
    }

    /**
     * Adds the valid addresses found for a token; reports the token as skipped
     * when none was valid.
     *
     * @param array<int, mixed> $found
     * @param array<int, string> $addresses
     * @param array<int, string> $skipped
     */
    private function collect(string $token, array $found, array &$addresses, array &$skipped): void
    {
        $valid = 0;
        $invalid = [];
        foreach ($found as $address) {
            $address = strtolower(trim((string) $address));
            if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
                if ($address !== '') {
                    $invalid[] = $address;
                }
                continue;
            }
            $valid++;
            if (!in_array($address, $addresses, true)) {
                $addresses[] = $address;
            }
        }
        if ($invalid) {
            $skipped[] = $token . ' (' . implode(', ', $invalid) . ')';
        } elseif ($valid === 0) {
            $skipped[] = $token;
        }
    }

    /**
     * Addresses of a comma-separated input (e.g. edited in the mail form);
     * returns the invalid parts separately.
     *
     * @return array{0: array<int, string>, 1: array<int, string>} [valid, invalid]
     */
    public function parseAddresses(string $input): array
    {
        $valid = [];
        $invalid = [];
        foreach (preg_split('/[,;\s]+/', $input) ?: [] as $part) {
            $part = strtolower(trim($part));
            if ($part === '') {
                continue;
            }
            if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                if (!in_array($part, $valid, true)) {
                    $valid[] = $part;
                }
            } else {
                $invalid[] = $part;
            }
        }
        return [$valid, $invalid];
    }

    /**
     * @return array<int, string>
     */
    private function token(string $token, DataObject $case, ?Member $me): array
    {
        if (str_contains($token, '@')) {
            return [$token];
        }
        if ($token === 'Me') {
            return $me ? [(string) $me->Email] : [];
        }
        $parts = explode('.', $token);
        if ($parts[0] === 'Assignee' && isset($parts[1])) {
            $member = $this->assignee($case, $parts[1]);
            return $member ? [(string) $member->Email] : [];
        }
        return $this->values([$case], $parts);
    }

    /**
     * Values of a field path for all records reached through the relations.
     *
     * @param array<int, DataObject> $records
     * @param array<int, string> $parts
     * @return array<int, string>
     */
    private function values(array $records, array $parts): array
    {
        $field = (string) array_pop($parts);
        $schema = DataObject::getSchema();
        foreach ($parts as $relation) {
            $next = [];
            foreach ($records as $record) {
                $class = $record::class;
                if ($schema->hasOneComponent($class, $relation)) {
                    $related = $record->getComponent($relation);
                    if ($related->exists()) {
                        $next[] = $related;
                    }
                } elseif (
                    $schema->hasManyComponent($class, $relation)
                    || $schema->manyManyComponent($class, $relation)
                ) {
                    $list = $record->$relation();
                    if ($list instanceof SS_List) {
                        foreach ($list as $item) {
                            $next[] = $item;
                        }
                    }
                }
            }
            $records = $next;
        }
        $values = [];
        foreach ($records as $record) {
            if ($schema->fieldSpec($record::class, $field)) {
                $values[] = (string) $record->getField($field);
            }
        }
        return $values;
    }

    private function assignee(DataObject $case, string $code): ?Member
    {
        $workflow = Workflow::forClass($case::class);
        $responsibility = $workflow
            ? Responsibility::get()->filter(['WorkflowID' => $workflow->ID, 'Code' => $code])->first()
            : null;
        return $responsibility ? AssignmentService::singleton()->assignee($case, $responsibility) : null;
    }
}
