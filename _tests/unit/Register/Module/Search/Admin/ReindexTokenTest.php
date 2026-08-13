<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Search\Admin;

use Codeception\Test\Unit;
use Register\Module\Search\Admin\ReindexToken;
use S2\AdminYard\SettingStorage\SettingStorageInterface;

final class ReindexTokenTest extends Unit
{
    public function testValidatesStablePerUserToken(): void
    {
        $storage = new InMemorySettingStorage();
        $token   = new ReindexToken($storage);

        self::assertSame(40, \strlen($token->value()));
        self::assertTrue($token->matches($token->value()));
        self::assertFalse($token->matches(''));
        self::assertFalse($token->matches(str_repeat('0', 40)));
    }
}

/** @internal */
final class InMemorySettingStorage implements SettingStorageInterface
{
    /** @var array<string, array<mixed>|string|int|float|bool|null> */
    private array $data = [];

    #[\Override]
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /** @return array<mixed>|string|int|float|bool|null */
    #[\Override]
    public function get(string $key): array|string|int|float|bool|null
    {
        return $this->data[$key] ?? null;
    }

    /** @param array<mixed>|string|int|float|bool|null $data */
    #[\Override]
    public function set(string $key, array|string|int|float|bool|null $data): void
    {
        $this->data[$key] = $data;
    }

    #[\Override]
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}
