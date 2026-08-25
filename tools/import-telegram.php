<?php
/**
 * Imports a Telegram Desktop discussion JSON through the same idempotent service as the admin UI.
 *
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

use Register\Import\Telegram\TelegramImportService;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['file:', 'user::', 'dry-run']);
if (!\is_array($options)) {
    fwrite(STDERR, "Unable to parse command-line options.\n");
    exit(2);
}

$path = $options['file'] ?? null;
if (!\is_string($path) || $path === '') {
    fwrite(STDERR, "Usage: php tools/import-telegram.php --file=/path/result.json [--user=1] [--dry-run]\n");
    exit(2);
}

$userId = $options['user'] ?? null;
if ($userId !== null && (!\is_string($userId) || preg_match('/^[1-9][0-9]*$/D', $userId) !== 1)) {
    fwrite(STDERR, "The --user value must be a positive integer.\n");
    exit(2);
}

try {
    $app = require dirname(__DIR__) . '/_include/common.php';
    $report = $app->container->get(TelegramImportService::class)->importFile(
        $path,
        $userId === null ? null : (int)$userId,
        array_key_exists('dry-run', $options),
    );
    echo json_encode(
        $report,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    ) . "\n";
} catch (\Throwable $throwable) {
    fwrite(STDERR, 'Telegram import failed: ' . $throwable->getMessage() . "\n");
    exit(1);
}
