#!/usr/bin/env php
<?php
/**
 * Requeues one explicitly selected failed background job.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Core\Queue\QueueRecovery;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$arguments = $_SERVER['argv'] ?? null;
if (
    !\is_array($arguments)
    || \count($arguments) !== 3
    || !\is_string($arguments[1] ?? null)
    || !\is_string($arguments[2] ?? null)
) {
    fwrite(STDERR, "Usage: php tools/retry-background-job.php <id> <code>\n");
    exit(64);
}

$id   = $arguments[1];
$code = $arguments[2];
$app  = require dirname(__DIR__) . '/_include/common.php';

if (!$app->container->get(QueueRecovery::class)->retryFailed($id, $code)) {
    fwrite(STDERR, "The selected failed queue job does not exist.\n");
    exit(2);
}

fwrite(STDOUT, \sprintf("Requeued background job %s/%s.\n", $id, $code));
