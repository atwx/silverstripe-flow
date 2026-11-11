<?php

/**
 * Test bootstrap for atwx/silverstripe-flow.
 *
 * Two ways to run the tests:
 *
 * 1. From a consuming project root (e.g. silverstripe-flow-demo), where the
 *    module is usually symlinked into vendor/ via a Composer path repository:
 *
 *      vendor/bin/phpunit -c vendor/atwx/silverstripe-flow/phpunit.xml.dist
 *
 *    __DIR__ resolves to the real checkout path, not the symlink, so the
 *    project is found via the current working directory instead.
 *
 * 2. Standalone, after `composer install` in the module checkout (as in CI).
 */

$candidates = [
    getcwd() . '/vendor/silverstripe/framework/tests/bootstrap.php',
    __DIR__ . '/../vendor/silverstripe/framework/tests/bootstrap.php',
];

foreach ($candidates as $bootstrap) {
    if (file_exists($bootstrap)) {
        require $bootstrap;
        return;
    }
}

throw new RuntimeException(
    'No Silverstripe test bootstrap found. Run `composer install` in the project or module root.'
);
