<?php

namespace Atwx\Flow\Tests\Unit;

use SilverStripe\Core\Manifest\ModuleLoader;
use SilverStripe\Dev\SapphireTest;

/**
 * Smoke test: the module is registered in the Silverstripe module manifest.
 */
class ModuleTest extends SapphireTest
{
    protected $usesDatabase = false;

    public function testModuleIsRegistered(): void
    {
        $module = ModuleLoader::inst()->getManifest()->getModule('atwx/silverstripe-flow');

        $this->assertSame('atwx/silverstripe-flow', $module->getName());
        $this->assertFileExists($module->getPath() . '/_config/config.yml');
    }
}
