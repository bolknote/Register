#!/usr/bin/env php
<?php
/**
 * Restores the ActivityPub master key from an authenticated recovery document.
 *
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

use Register\Extension\activitypub\Application\ActivityPubIdentityRecoveryService;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('ActivityPub identity recovery can only run from the command line.');
}

$arguments = $_SERVER['argv'] ?? [];
if (\count($arguments) !== 2 || $arguments[1] === '') {
    fwrite(STDERR, "Usage: php tools/restore-activitypub-identity.php <identity-recovery.json>\n");
    exit(64);
}

$filename = $arguments[1];
if (!str_starts_with($filename, DIRECTORY_SEPARATOR)
    && !str_starts_with($filename, '\\\\')
    && preg_match('/^[A-Za-z]:[\\\\\/]/D', $filename) !== 1
) {
    $workingDirectory = getcwd();
    if ($workingDirectory === false) {
        fwrite(STDERR, "Unable to determine the current working directory.\n");
        exit(1);
    }
    $filename = $workingDirectory . DIRECTORY_SEPARATOR . $filename;
}

try {
    $directory = realpath(dirname($filename));
    $basename  = basename($filename);
    if ($directory === false || $basename === '.' || $basename === '..') {
        throw new RuntimeException('The ActivityPub recovery document path is invalid.');
    }

    $expectedFilename = $directory . DIRECTORY_SEPARATOR . $basename;
    $filename         = realpath($expectedFilename);
    if ($filename === false || $filename !== $expectedFilename || !is_file($filename)) {
        throw new RuntimeException('The ActivityPub recovery document must be a regular non-symlink file.');
    }
    $size = filesize($filename);
    if (!\is_int($size) || $size < 1 || $size > 4_194_304) {
        throw new RuntimeException('The ActivityPub recovery document has an invalid size.');
    }
    $document = file_get_contents($filename);
    if (!\is_string($document)) {
        throw new RuntimeException('Unable to read the ActivityPub recovery document.');
    }
    $app = require dirname(__DIR__) . '/_include/common.php';
    $report = $app->container->get(ActivityPubIdentityRecoveryService::class)
        ->restoreRecoveryDocument($document);
    fwrite(STDOUT, json_encode([
        'actors'               => $report->actorCount,
        'keys'                 => $report->keyCount,
        'identity_fingerprint' => $report->identityFingerprint,
        'healthy'              => $report->isHealthy(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $throwable) {
    fwrite(STDERR, 'ActivityPub identity recovery failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
