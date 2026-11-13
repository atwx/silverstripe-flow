<?php

namespace Atwx\Flow\Admin;

use Atwx\Flow\Api\ApiClient;
use Atwx\Flow\Api\ApiLog;
use Atwx\Flow\Model\TextBlock;
use Atwx\Flow\Model\Workflow;
use Atwx\Flow\Security\FlowPermissions;
use SilverStripe\Admin\ModelAdmin;

/**
 * CMS section to maintain the workflows of the case files.
 */
class FlowAdmin extends ModelAdmin
{
    private static string $url_segment = 'flow';

    private static string $menu_title = 'Workflows';

    private static string $menu_icon_class = 'font-icon-flow-tree';

    private static array $managed_models = [
        Workflow::class,
        TextBlock::class,
        ApiClient::class,
        ApiLog::class,
    ];

    private static array $required_permission_codes = [
        FlowPermissions::CONFIGURE,
    ];

    /**
     * Configuration is maintained per workflow; a CSV import would bypass the
     * code-based YAML import (see the import task).
     *
     * @var bool
     */
    public $showImportForm = false;
}
