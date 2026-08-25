#!/usr/bin/env php
<?php
/**
 * Runs one explicitly bounded background-queue slice from the command line.
 *
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Core\Queue\BackgroundWorkRunner;
use Register\Core\Queue\QueueMonitor;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$options = getopt('', ['help', 'jobs:', 'seconds:']);
if (!\is_array($options)) {
    throw new RuntimeException('Unable to parse command-line options.');
}
if (isset($options['help'])) {
    echo <<<'HELP'
Runs one queue slice under the same global lease and execution budget as web shutdown work.

Usage:
  php tools/run-queue.php [--seconds=5] [--jobs=5]

Limits: 1-300 seconds and 1-1000 attempted jobs.

HELP;
    exit(0);
}

$positiveInteger = static function (mixed $value, int $default, int $maximum, string $name): int {
    $value ??= (string)$default;
    if (!\is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
        throw new InvalidArgumentException('--' . $name . ' must be a positive integer.');
    }
    $result = (int)$value;
    if ($result > $maximum) {
        throw new InvalidArgumentException(sprintf('--%s must not exceed %d.', $name, $maximum));
    }

    return $result;
};

$seconds = $positiveInteger($options['seconds'] ?? null, 5, 300, 'seconds');
$jobs = $positiveInteger($options['jobs'] ?? null, 5, 1000, 'jobs');
$app = require dirname(__DIR__) . '/_include/common.php';
$monitor = $app->container->get(QueueMonitor::class);
$before = $monitor->status();
$attempted = $app->container->get(BackgroundWorkRunner::class)->run((float)$seconds, $jobs);
$after = $monitor->status();

echo json_encode([
    'attempted' => $attempted,
    'before' => $before,
    'after' => $after,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

exit($after['failed'] > 0 ? 2 : 0);
