<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Architecture;

use Codeception\Test\Unit;

final class CoreProductBoundaryTest extends Unit
{
    /**
     * Transitional baseline. It is intentionally empty now that the boundary is closed.
     *
     * @var array<string, positive-int>
     */
    private const array ALLOWED_PRODUCT_REFERENCES = [];

    public function testCoreDoesNotDependOnProductNamespaces(): void
    {
        $sourceDirectory = dirname(__DIR__, 4) . '/_include/src';
        $actual           = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($sourceDirectory) + 1);
            if (str_starts_with($relativePath, 'Register/')) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents, sprintf('Unable to read %s.', $relativePath));
            $count = 0;
            foreach (token_get_all($contents) as $token) {
                if (!\is_array($token) || !\in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    continue;
                }

                $name = ltrim($token[1], '\\');
                if (!str_starts_with($name, 'Register\\')) {
                    continue;
                }

                if (
                    $name === 'Register\\Core'
                    || str_starts_with($name, 'Register\\Core\\')
                    || $name === 'Register\\Rose'
                    || str_starts_with($name, 'Register\\Rose\\')
                    || $name === 'Register\\AdminYard'
                    || str_starts_with($name, 'Register\\AdminYard\\')
                ) {
                    continue;
                }

                ++$count;
            }

            if ($count > 0) {
                $actual[$relativePath] = $count;
            }
        }

        ksort($actual);
        self::assertSame(
            self::ALLOWED_PRODUCT_REFERENCES,
            $actual,
            'Register\\Core depends on product code. Move the code to Register or add a Core contract.',
        );
    }
}
