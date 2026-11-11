<?php

namespace Atwx\Flow\Security;

use SilverStripe\Security\Permission;
use SilverStripe\Security\PermissionProvider;

/**
 * Permission codes of the flow module.
 */
class FlowPermissions implements PermissionProvider
{
    /**
     * Maintain workflows: phases, statuses, transitions, roles.
     */
    public const string CONFIGURE = 'FLOW_CONFIGURE';

    public static function canConfigure(mixed $member = null): bool
    {
        return Permission::check(self::CONFIGURE, 'any', $member);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function providePermissions(): array
    {
        return [
            self::CONFIGURE => [
                'name' => _t(self::class . '.CONFIGURE', 'Configure workflows'),
                'help' => _t(
                    self::class . '.CONFIGURE_HELP',
                    'Maintain phases, statuses, transitions and roles of the case file workflows.'
                ),
                'category' => _t(self::class . '.CATEGORY', 'Case files'),
                'sort' => 0,
            ],
        ];
    }
}
