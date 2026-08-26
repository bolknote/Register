<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Http;

use Symfony\Component\HttpFoundation\AcceptHeader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResponseCompressor
{
    /**
     * @param \Closure(string): (string|false)|null $brotliCompressor
     * @param \Closure(string): (string|false)|null $gzipCompressor
     * @param \Closure(string): (string|false)|null $zstdCompressor
     */
    public function __construct(
        private ?\Closure $brotliCompressor,
        private ?\Closure $gzipCompressor,
        private bool      $managedByPhp = false,
        private ?\Closure $zstdCompressor = null,
        private ?ResponseCompressionCache $compressionCache = null,
    ) {
    }

    public static function fromEnvironment(?ResponseCompressionCache $compressionCache = null): self
    {
        if (self::phpManagesCompression()) {
            return new self(null, null, true);
        }

        $codecs = CompressionCodecRegistry::fromEnvironment();

        return new self(
            $codecs->compressor(CompressionCodecRegistry::BROTLI),
            $codecs->compressor(CompressionCodecRegistry::GZIP),
            false,
            $codecs->compressor(CompressionCodecRegistry::ZSTD),
            $compressionCache,
        );
    }

    public function compress(Request $request, Response $response): bool
    {
        if ($this->managedByPhp || !$this->canCompress($response)) {
            return false;
        }

        $response->setVary('Accept-Encoding', false);

        $encoding = $this->negotiate($request->headers->get('Accept-Encoding') ?? '');
        if ($encoding === null) {
            return false;
        }

        $content = $response->getContent();
        if (!\is_string($content)) {
            return false;
        }

        $compressor = match ($encoding) {
            CompressionCodecRegistry::BROTLI => $this->brotliCompressor,
            CompressionCodecRegistry::ZSTD   => $this->zstdCompressor,
            CompressionCodecRegistry::GZIP   => $this->gzipCompressor,
            default                          => null,
        };
        if (!$compressor instanceof \Closure) {
            return false;
        }

        $result = $this->compressionCache?->encode($response, $encoding, $content, $compressor);
        if ($result === null) {
            $compressed = $compressor($content);
            if (!\is_string($compressed)) {
                return false;
            }
        } else {
            $compressed = $result['content'];
            if ($result['cache_status'] !== null) {
                $response->headers->set('X-Register-Compression-Cache', $result['cache_status']);
            }
        }

        if ($compressed === '') {
            return false;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', $encoding);

        return true;
    }

    public function canSetContentLength(): bool
    {
        return !$this->managedByPhp;
    }

    private function negotiate(string $acceptEncoding): ?string
    {
        $accepted = AcceptHeader::fromString($acceptEncoding);
        $choices  = [];

        foreach ([
            CompressionCodecRegistry::BROTLI => $this->brotliCompressor,
            CompressionCodecRegistry::ZSTD   => $this->zstdCompressor,
            CompressionCodecRegistry::GZIP   => $this->gzipCompressor,
        ] as $encoding => $compressor) {
            $quality = $accepted->get($encoding)?->getQuality() ?? 0.0;
            if ($compressor instanceof \Closure && $quality > 0.0) {
                $choices[$encoding] = $quality;
            }
        }

        if ($choices === []) {
            return null;
        }

        arsort($choices, SORT_NUMERIC);

        return array_key_first($choices);
    }

    private function canCompress(Response $response): bool
    {
        if (
            !$this->brotliCompressor instanceof \Closure
            && !$this->zstdCompressor instanceof \Closure
            && !$this->gzipCompressor instanceof \Closure
        ) {
            return false;
        }

        if (
            $response->isInformational()
            || $response->isEmpty()
            || $response->headers->has('Content-Encoding')
            || $response->headers->has('Content-Range')
            || $response->headers->hasCacheControlDirective('no-transform')
        ) {
            return false;
        }

        $content = $response->getContent();
        if (!\is_string($content) || $content === '') {
            return false;
        }

        $contentType = strtolower((string)$response->headers->get('Content-Type'));
        $mediaType   = trim(explode(';', $contentType, 2)[0]);

        return str_starts_with($mediaType, 'text/')
            || $mediaType === 'image/svg+xml'
            || \in_array($mediaType, ['application/javascript', 'application/x-javascript'], true)
            || preg_match('~^application/(?:[a-z0-9.+-]+\+)?(?:json|xml)$~D', $mediaType) === 1;
    }

    private static function phpManagesCompression(): bool
    {
        foreach (['zlib.output_compression', 'brotli.output_compression', 'zstd.output_compression'] as $setting) {
            if (self::iniFlagEnabled(ini_get($setting))) {
                return true;
            }
        }

        $handlers = [...ob_list_handlers(), (string)ini_get('output_handler')];
        foreach ($handlers as $handler) {
            $handler = strtolower($handler);
            if (
                str_contains($handler, 'brotli')
                || str_contains($handler, 'gzhandler')
                || str_contains($handler, 'zlib')
                || str_contains($handler, 'zstd')
            ) {
                return true;
            }
        }

        return false;
    }

    private static function iniFlagEnabled(string|false $value): bool
    {
        if ($value === false) {
            return false;
        }

        return !\in_array(strtolower(trim($value)), ['', '0', 'off', 'false', 'no', 'none'], true);
    }
}
