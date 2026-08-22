#!/usr/bin/env php
<?php
/**
 * Prints request-driven background queue health as JSON for operators and monitors.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Core\Queue\QueueMonitor;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$app    = require dirname(__DIR__) . '/_include/common.php';
$status = $app->container->get(QueueMonitor::class)->status();

fwrite(STDOUT, json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL);
exit($status['failed'] > 0 ? 2 : 0);
