<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http;

/** Discovers optional native content encoders without making them hard dependencies. */
final readonly class CompressionCodecRegistry
{
    public const string BROTLI = 'br';

    public const string ZSTD = 'zstd';

    public const string GZIP = 'gzip';

    private const array LEVELS = [
        self::BROTLI => 3,
        self::ZSTD   => 3,
        self::GZIP   => 6,
    ];

    private const array FUNCTIONS = [
        self::BROTLI => 'brotli_compress',
        self::ZSTD   => 'zstd_compress',
        self::GZIP   => 'gzencode',
    ];

    /** @param array<string, \Closure(string): (string|false)> $compressors */
    public function __construct(private array $compressors)
    {
    }

    public static function fromEnvironment(): self
    {
        $compressors = [];
        foreach (self::FUNCTIONS as $encoding => $functionName) {
            if (!\function_exists($functionName)) {
                continue;
            }

            $nativeCompressor = \Closure::fromCallable($functionName);
            $level = self::LEVELS[$encoding];
            $compressors[$encoding] = static function (string $content) use ($nativeCompressor, $level): string|false {
                $compressed = $nativeCompressor($content, $level);

                return \is_string($compressed) ? $compressed : false;
            };
        }

        return new self($compressors);
    }

    /** @return list<string> */
    public function encodings(): array
    {
        return array_values(array_filter(
            [self::BROTLI, self::ZSTD, self::GZIP],
            fn(string $encoding): bool => isset($this->compressors[$encoding]),
        ));
    }

    /** @return (\Closure(string): (string|false))|null */
    public function compressor(string $encoding): ?\Closure
    {
        return $this->compressors[$encoding] ?? null;
    }

    public function supports(string $encoding): bool
    {
        return isset($this->compressors[$encoding]);
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        $result = [];
        foreach ([self::BROTLI, self::ZSTD, self::GZIP] as $encoding) {
            $result[$encoding] = $this->supports($encoding);
        }

        return $result;
    }
}
