<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * Makes corrupt or unavailable encrypted APCu entries behave as ordinary cache misses.
 *
 * @psalm-suppress PropertyNotSetInConstructor Vendor parent state is initialized by its constructor.
 */
final class ResilientApcuAdapter extends ApcuAdapter
{
    public function __construct(
        string $namespace,
        int $defaultLifetime,
        ?string $version,
        ?MarshallerInterface $marshaller = null,
        private readonly ?LoggerInterface $failureLogger = null,
    ) {
        parent::__construct($namespace, $defaultLifetime, $version, $marshaller);
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return iterable<array-key, mixed>
     */
    #[\Override]
    protected function doFetch(array $ids): iterable
    {
        try {
            return parent::doFetch($ids);
        } catch (\Throwable $throwable) {
            parent::doDelete($ids);
            $this->logFailure('read', $throwable);

            return [];
        }
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>|bool
     */
    #[\Override]
    protected function doSave(array $values, int $lifetime): array|bool
    {
        try {
            return parent::doSave($values, $lifetime);
        } catch (\Throwable $throwable) {
            $this->logFailure('write', $throwable);

            return array_keys($values);
        }
    }

    private function logFailure(string $operation, \Throwable $throwable): void
    {
        $this->failureLogger?->warning('An encrypted APCu cache entry failed; continuing with the next tier.', [
            'operation' => $operation,
            'exception' => $throwable,
        ]);
    }
}
