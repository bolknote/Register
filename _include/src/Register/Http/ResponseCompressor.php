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
    private const int BROTLI_LEVEL = 3;

    private const int GZIP_LEVEL = 6;

    /**
     * @param \Closure(string): (string|false)|null $brotliCompressor
     * @param \Closure(string): (string|false)|null $gzipCompressor
     */
    public function __construct(
        private ?\Closure $brotliCompressor,
        private ?\Closure $gzipCompressor,
        private bool      $managedByPhp = false,
    ) {
    }

    public static function fromEnvironment(): self
    {
        if (self::phpManagesCompression()) {
            return new self(null, null, true);
        }

        return new self(
            self::nativeCompressor('brotli_compress', self::BROTLI_LEVEL),
            self::nativeCompressor('gzencode', self::GZIP_LEVEL),
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

        $compressor = $encoding === 'br' ? $this->brotliCompressor : $this->gzipCompressor;
        if (!$compressor instanceof \Closure) {
            return false;
        }

        $compressed = $compressor($content);
        if (!\is_string($compressed)) {
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

        foreach (['br' => $this->brotliCompressor, 'gzip' => $this->gzipCompressor] as $encoding => $compressor) {
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
        if (!$this->brotliCompressor instanceof \Closure && !$this->gzipCompressor instanceof \Closure) {
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

    /** @return (\Closure(string): (string|false))|null */
    private static function nativeCompressor(string $functionName, int $level): ?\Closure
    {
        if (!\function_exists($functionName)) {
            return null;
        }

        $compressor = \Closure::fromCallable($functionName);

        return static function (string $content) use ($compressor, $level): string|false {
            $compressed = $compressor($content, $level);

            return \is_string($compressed) ? $compressed : false;
        };
    }

    private static function phpManagesCompression(): bool
    {
        foreach (['zlib.output_compression', 'brotli.output_compression'] as $setting) {
            if (self::iniFlagEnabled(ini_get($setting))) {
                return true;
            }
        }

        $handlers = [...ob_list_handlers(), (string)ini_get('output_handler')];
        foreach ($handlers as $handler) {
            $handler = strtolower($handler);
            if (str_contains($handler, 'brotli') || str_contains($handler, 'gzhandler') || str_contains($handler, 'zlib')) {
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
