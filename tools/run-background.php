#!/usr/bin/env php
<?php
/**
 * Manual recovery command for background work. Normal operation is driven by HTTP shutdown callbacks.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

use S2\Cms\Queue\BackgroundWorkRunner;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$app  = require dirname(__DIR__) . '/_include/common.php';
$jobs = $app->container->get(BackgroundWorkRunner::class)->run(45.0, 100);

fwrite(STDOUT, \sprintf("Attempted %d queue job(s).\n", $jobs));
