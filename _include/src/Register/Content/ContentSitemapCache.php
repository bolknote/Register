<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Content;

use Register\Core\Framework\StatefulServiceInterface;
use Register\Core\Pdo\PDO;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Stores sitemap documents until canonical content actually changes. */
final class ContentSitemapCache implements StatefulServiceInterface
{
    private const string GENERATION_KEY = 'register_content_sitemap_generation_v1';

    private const string VALUE_PREFIX = 'register_content_sitemap_value_v1_';

    private ?string $generation = null;

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly bool           $disabled = false,
        private readonly ?PDO            $pdo = null,
    ) {
    }

    /** @param callable(): string $factory */
    public function rememberString(string $key, callable $factory): string
    {
        $value = $this->remember($key, $factory, 'string');
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('The sitemap cache returned a non-string document.');
        }

        return $value;
    }

    /** @param callable(): int $factory */
    public function rememberInt(string $key, callable $factory): int
    {
        $value = $this->remember($key, $factory, 'int');
        if (!\is_int($value)) {
            throw new \UnexpectedValueException('The sitemap cache returned a non-integer value.');
        }

        return $value;
    }

    /** @param callable(): (int|string) $factory */
    private function remember(string $key, callable $factory, string $expectedType): int|string
    {
        if ($this->disabled) {
            return $factory();
        }

        if (preg_match('/^[a-z0-9_-]{1,80}$/D', $key) !== 1) {
            throw new \InvalidArgumentException('A sitemap cache key contains unsupported characters.');
        }

        $generation = $this->generation();
        $cacheKey   = self::VALUE_PREFIX . $key;
        $build      = static function (ItemInterface $item) use ($factory, $generation): array {
            $item->expiresAfter(null);

            return [
                'generation' => $generation,
                'value'      => $factory(),
            ];
        };
        $value = $this->value($this->cached($cacheKey, $build), $generation, $expectedType);
        if ($value === null) {
            // A content event changes the dependency generation, not the storage key. This
            // keeps one bounded cache slot per sitemap document across the lifetime of a site.
            $this->cache->delete($cacheKey);
            $value = $this->value($this->cached($cacheKey, $build), $generation, $expectedType);
        }

        if ($value === null) {
            throw new \UnexpectedValueException('The sitemap cache returned an invalid value.');
        }

        return $value;
    }

    /** Invalidates before and after COMMIT so an old concurrent snapshot cannot win a race. */
    public function invalidate(): void
    {
        if ($this->disabled) {
            return;
        }

        $clear = function (): void {
            $this->generation = null;
            $this->cache->delete(self::GENERATION_KEY);
        };
        if ($this->pdo instanceof PDO && $this->pdo->inTransaction()) {
            $callbackKey = 'content-sitemap-cache';
            if ($this->pdo->afterCommitOnce($callbackKey, $clear)) {
                $this->pdo->afterRollbackOnce($callbackKey, $clear);
                // A bulk import can dispatch thousands of content events in one transaction.
                // The first event invalidates the visible snapshot immediately; one completion
                // callback closes the concurrent-repopulation race after COMMIT or ROLLBACK.
                $clear();
            }

            return;
        }

        $clear();
    }

    #[\Override]
    public function clearState(): void
    {
        $this->generation = null;
    }

    private function generation(): string
    {
        if ($this->generation !== null) {
            return $this->generation;
        }

        $generation = $this->cached(
            self::GENERATION_KEY,
            static function (ItemInterface $item): string {
                $item->expiresAfter(null);

                return bin2hex(random_bytes(8));
            },
        );
        if (!\is_string($generation) || preg_match('/^[a-f0-9]{16}$/D', $generation) !== 1) {
            throw new \UnexpectedValueException('The sitemap cache generation is invalid.');
        }

        return $this->generation = $generation;
    }

    /** @param callable(ItemInterface): mixed $factory */
    private function cached(string $key, callable $factory): mixed
    {
        // Cache storage is an external boundary; validate its payload instead of trusting the
        // generic return type inferred from the factory.
        return $this->cache->get(
            $key,
            static fn(ItemInterface $item, bool $save): mixed => match ($save) {
                true, false => $factory($item),
            },
            0.0,
        );
    }

    private function value(mixed $cached, string $generation, string $expectedType): int|string|null
    {
        if (!\is_array($cached) || ($cached['generation'] ?? null) !== $generation) {
            return null;
        }

        $value = $cached['value'] ?? null;
        return match ($expectedType) {
            'int' => \is_int($value) ? $value : null,
            'string' => \is_string($value) ? $value : null,
            default => throw new \InvalidArgumentException('Unsupported sitemap cache value type.'),
        };
    }
}
