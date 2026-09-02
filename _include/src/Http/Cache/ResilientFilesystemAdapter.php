<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Http\Cache;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * Makes an optional tmpfs adapter fail closed and fall through to the durable cache tier.
 *
 * @psalm-suppress PropertyNotSetInConstructor Vendor trait state is initialized by the parent constructor.
 */
final class ResilientFilesystemAdapter extends FilesystemAdapter
{
    public function __construct(
        string $namespace,
        int $defaultLifetime,
        private readonly SecureVolatileCacheDirectory $secureDirectory,
        ?MarshallerInterface $marshaller = null,
        private readonly ?LoggerInterface $failureLogger = null,
    ) {
        if (!$this->secureDirectory->ensure()) {
            throw new \RuntimeException('The private tmpfs cache boundary is unavailable.');
        }

        parent::__construct($namespace, $defaultLifetime, $this->secureDirectory->path, $marshaller);
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return iterable<array-key, mixed>
     */
    #[\Override]
    protected function doFetch(array $ids): iterable
    {
        if (!$this->secureDirectory->ensure()) {
            return [];
        }

        try {
            return parent::doFetch($ids);
        } catch (\Throwable $throwable) {
            if ($this->secureDirectory->ensure()) {
                parent::doDelete($ids);
            }

            $this->logFailure('read', $throwable);

            return [];
        }
    }

    #[\Override]
    protected function doHave(string $id): bool
    {
        if (!$this->secureDirectory->ensure()) {
            return false;
        }

        try {
            return parent::doHave($id);
        } catch (\Throwable $throwable) {
            if ($this->secureDirectory->ensure()) {
                parent::doDelete([$id]);
            }

            $this->logFailure('inspect', $throwable);

            return false;
        }
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>|bool
     */
    #[\Override]
    protected function doSave(array $values, int $lifetime): array|bool
    {
        if (!$this->secureDirectory->ensure()) {
            return array_keys($values);
        }

        try {
            return parent::doSave($values, $lifetime);
        } catch (\Throwable $throwable) {
            $this->logFailure('write', $throwable);

            return array_keys($values);
        }
    }

    /** @param array<array-key, mixed> $ids */
    #[\Override]
    protected function doDelete(array $ids): bool
    {
        return !$this->secureDirectory->ensure() || parent::doDelete($ids);
    }

    #[\Override]
    protected function doClear(string $namespace): bool
    {
        return !$this->secureDirectory->ensure() || parent::doClear($namespace);
    }

    #[\Override]
    public function prune(): bool
    {
        return !$this->secureDirectory->ensure() || parent::prune();
    }

    private function logFailure(string $operation, \Throwable $throwable): void
    {
        $this->failureLogger?->warning('An encrypted tmpfs cache entry failed; continuing with the durable tier.', [
            'operation' => $operation,
            'exception' => $throwable,
        ]);
    }
}
