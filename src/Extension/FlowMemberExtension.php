<?php

namespace Atwx\Flow\Extension;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use SilverStripe\Security\Member;

/**
 * Settings of a member for flow: no daily mail about due follow-ups
 * (FlowDigestOff) and the day the last one was sent (FlowDigestSentOn, so a
 * second run on the same day sends nothing).
 *
 * @property bool $FlowDigestOff
 * @property ?string $FlowDigestSentOn
 * @extends Extension<Member>
 */
class FlowMemberExtension extends Extension
{
    private static array $db = [
        'FlowDigestOff' => 'Boolean',
        'FlowDigestSentOn' => 'Date',
    ];

    protected function updateCMSFields(FieldList $fields): void
    {
        $fields->removeByName('FlowDigestSentOn');
        $field = $fields->dataFieldByName('FlowDigestOff');
        $field?->setTitle(_t(self::class . '.DIGEST_OFF', 'No daily mail about due follow-ups'));
    }

    /**
     * @param array<string, string> $labels
     */
    protected function updateFieldLabels(array &$labels): void
    {
        $labels['FlowDigestOff'] = _t(self::class . '.DIGEST_OFF', 'No daily mail about due follow-ups');
    }
}
