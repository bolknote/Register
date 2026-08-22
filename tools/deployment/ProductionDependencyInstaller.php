<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Tools\Deployment;

final readonly class ProductionDependencyInstaller
{
    public function install(string $applicationRoot): void
    {
        $composer = $this->findComposerBinary();
        if ($composer === null) {
            throw new \RuntimeException('Composer is required to prepare production dependencies.');
        }

        $command = [
            $composer,
            'install',
            '--working-dir=' . $applicationRoot,
            '--no-dev',
            '--prefer-dist',
            '--no-interaction',
            '--no-progress',
            '--no-plugins',
            '--no-scripts',
            '--optimize-autoloader',
        ];
        $pipes   = [];
        $process = proc_open($command, [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['file', 'php://stdout', 'w'],
            2 => ['file', 'php://stderr', 'w'],
        ], $pipes);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to start Composer.');
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_file($applicationRoot . '/_vendor/autoload.php')) {
            throw new \RuntimeException('Composer failed to install the locked production dependencies.');
        }
    }

    private function findComposerBinary(): ?string
    {
        $path = getenv('PATH');
        if (!\is_string($path)) {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $candidate = rtrim($directory, '/\\') . '/composer';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
