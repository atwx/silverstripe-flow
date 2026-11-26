<?php

namespace Atwx\Flow\Intake;

/**
 * Optional for a TakeoverHandler: data of the submission that differs from
 * data already there (e.g. the address of a known family). The inbox then asks
 * the staff member per field which value applies before taking over; without
 * conflicts the takeover stays a single step.
 *
 * The handler reads the decisions with $submission->takesNew($key) (default:
 * keep the current value) or $submission->takeoverDecisions().
 */
interface TakeoverConflicts
{
    /**
     * Conflicts for the chosen option (null: no choices), values as plain
     * text. Empty = none.
     *
     *   [['key' => 'parent.12.Surname', 'group' => 'Mutter Anna Castell',
     *     'label' => 'Nachname', 'current' => 'Castell', 'new' => 'Castell-Weber'], …]
     *
     * The key identifies the field for takesNew(); it must be stable between
     * showing the conflicts and taking over.
     *
     * @return array<int, array<string, ?string>> key, group, label, current, new
     */
    public function conflicts(Submission $submission, ?string $choice): array;
}
