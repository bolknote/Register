<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final class LinkQueue
{
    private const int OPERATION_TOKEN_BYTES = 16;

    public const string INVENTORY_CODE = 'register_link_inventory';

    public const string CHECK_CODE = 'register_link_check';

    public const string ARCHIVE_CODE = 'register_link_archive';

    public const string REPAIR_CODE = 'register_link_repair';

    public static function targetJobId(int $targetId): string
    {
        if ($targetId < 1) {
            throw new \InvalidArgumentException('A link target identifier must be positive.');
        }

        return 'target-' . $targetId;
    }

    /** @return array<string, mixed> */
    public static function checkPayload(
        int             $targetId,
        bool            $force = false,
        ?LinkProbeState $state = null,
        ?string         $token = null,
    ): array {
        self::targetJobId($targetId);
        $token ??= self::newOperationToken();
        if (!self::isOperationToken($token)) {
            throw new \InvalidArgumentException('A link-check operation token is invalid.');
        }

        $payload = ['target_id' => $targetId, 'token' => $token];
        if ($force) {
            $payload['force'] = true;
        }

        if ($state instanceof LinkProbeState) {
            $payload['probe'] = $state->toPayload();
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public static function archivePayload(int $targetId, bool $force = false, ?string $token = null): array
    {
        self::targetJobId($targetId);
        $token ??= self::newOperationToken();
        if (!self::isOperationToken($token)) {
            throw new \InvalidArgumentException('A link-archive operation token is invalid.');
        }

        $payload = ['target_id' => $targetId, 'token' => $token];
        if ($force) {
            $payload['force'] = true;
        }

        return $payload;
    }

    public static function isOperationToken(mixed $token): bool
    {
        return \is_string($token) && preg_match('/^[0-9a-f]{32}$/D', $token) === 1;
    }

    private static function newOperationToken(): string
    {
        return bin2hex(random_bytes(self::OPERATION_TOKEN_BYTES));
    }
}
