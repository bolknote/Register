#!/usr/bin/env php
<?php
/**
 * Verifies the exact ActivityPub release attestation and archived peer-matrix results.
 *
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

use s2_extensions\activitypub\Application\BundledReleaseInteroperabilityGate;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('ActivityPub interoperability verification can only run from the command line.');
}

require dirname(__DIR__) . '/_vendor/autoload.php';

$result = (new BundledReleaseInteroperabilityGate())->check();
fwrite($result->passed ? STDOUT : STDERR, $result->detail . PHP_EOL);
exit($result->passed ? 0 : 1);
