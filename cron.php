<?php
/**
 * @copyright 2023-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

use Register\Content\ContentPublicationScheduler;
use Register\Backup\BackupScheduler;
use S2\Cms\Comment\Antispam\SpamMaintenance;
use S2\Cms\Queue\QueueConsumer;

if (PHP_SAPI !== 'cli') {
    return;
}

$app = require __DIR__ . '/_include/common.php';

$app->container->get(ContentPublicationScheduler::class)->publishDue();

$consumer = $app->container->get(QueueConsumer::class);
$startedAt = microtime(true);
while ($consumer->runQueue() && microtime(true) - $startedAt < 45);

$app->container->get(SpamMaintenance::class)->run();
$app->container->get(BackupScheduler::class)->run();
