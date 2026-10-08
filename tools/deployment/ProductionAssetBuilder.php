<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Tools\Deployment;

/** Node and its locked build dependencies are required on the builder, never on the hosting server. */
final readonly class ProductionAssetBuilder
{
    public function __construct(private string $sourceRoot)
    {
    }

    public function build(string $publicRoot): void
    {
        $script = $this->sourceRoot . '/tools/assets/build.mjs';
        if (!is_file($script) || !is_file($this->sourceRoot . '/node_modules/terser/package.json')) {
            throw new \RuntimeException('Install the locked asset build dependencies with npm ci before building a release.');
        }

        $pipes = [];
        $process = proc_open(['node', $script, '--root=' . $publicRoot], [
            0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start the production asset build.');
        }

        if (proc_close($process) !== 0) {
            throw new \RuntimeException('Production asset optimization failed. Node.js 24 or newer and npm ci are required.');
        }
    }
}
