<?php
/**
 * Runs Register's asynchronous jobs beside the one-command local web server.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use S2\Cms\Queue\QueueConsumer;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('The local queue worker can only run from the command line.');
}

$app      = require dirname(__DIR__) . '/_include/common.php';
$consumer = $app->container->get(QueueConsumer::class);

while (getenv('S2_DEV_WORKER_RUNNING') === '1') {
    if (!$consumer->runQueue()) {
        usleep(200_000);
    }
}
