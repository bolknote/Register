<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Dashboard;

use Register\AdminYard\TemplateRenderer;
use Register\Http\CompressionCodecRegistry;

final readonly class DashboardCompressionProvider implements SystemStatusProviderInterface
{
    public function __construct(
        private TemplateRenderer $templateRenderer,
        private string           $publicCacheDirectory,
        private bool             $responseCacheEnabled,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        $registry = CompressionCodecRegistry::fromEnvironment();
        $commands = [
            CompressionCodecRegistry::BROTLI => 'brotli',
            CompressionCodecRegistry::ZSTD   => 'zstd',
            CompressionCodecRegistry::GZIP   => 'gzip',
        ];
        $suffixes = [
            CompressionCodecRegistry::BROTLI => '.br',
            CompressionCodecRegistry::ZSTD   => '.zst',
            CompressionCodecRegistry::GZIP   => '.gz',
        ];
        $labels = [
            CompressionCodecRegistry::BROTLI => 'Brotli',
            CompressionCodecRegistry::ZSTD   => 'Zstandard',
            CompressionCodecRegistry::GZIP   => 'gzip',
        ];

        $codecs = [];
        foreach ($labels as $encoding => $label) {
            $phpAvailable = $registry->supports($encoding);
            $codecs[] = [
                'encoding'         => $encoding,
                'label'            => $label,
                'php_available'    => $phpAvailable,
                'build_available'  => $phpAvailable || $this->findExecutable($commands[$encoding]) !== null,
                'sidecar_count'    => $this->countSidecars($suffixes[$encoding]),
            ];
        }

        return $this->templateRenderer->render('_admin/templates/dashboard/compression-item.php.inc', [
            'codecs'               => $codecs,
            'responseCacheEnabled' => $this->responseCacheEnabled,
        ]);
    }

    private function findExecutable(string $name): ?string
    {
        $path = getenv('PATH');
        if (!\is_string($path)) {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory === '') {
                continue;
            }

            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function countSidecars(string $suffix): int
    {
        $directory = rtrim($this->publicCacheDirectory, '/\\');
        $count = 0;
        foreach (['css', 'js'] as $extension) {
            $files = glob($directory . '/*.' . $extension . $suffix);
            if (\is_array($files)) {
                $count += \count($files);
            }
        }

        return $count;
    }
}
