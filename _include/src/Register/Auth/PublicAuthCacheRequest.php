<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Auth;

/** Bounded anonymous auth representations, usable without Composer or a database. */
final readonly class PublicAuthCacheRequest
{
    public const string RETURN_ATTRIBUTE = '_register_auth_return_template';

    public const string RETURN_MARKER = '/__register_auth_return_89b6f09a732a4b84__';

    private function __construct(
        public string $path,
        public ?string $mode,
        public string $returnPath,
        public bool $queryFree,
    ) {
    }

    public static function fromUri(string $uri, string $authPath): ?self
    {
        if (strlen($uri) > 2048 || str_contains($authPath, '?')
            || preg_match('/[\x00-\x20\x7f\\\\#]|%(?:00|0a|0d)/i', $uri) === 1) {
            return null;
        }

        [$path, $query] = array_pad(explode('?', $uri, 2), 2, '');
        $page = $path === $authPath;
        if (!$page && preg_match('#^' . preg_quote($authPath, '#') . '/oauth/[a-z][a-z0-9_]*$#D', $path) !== 1) {
            return null;
        }

        parse_str($query, $params);
        foreach ($params as $key => $value) {
            if (!is_string($value) || !in_array($key, $page ? ['return', 'mode'] : ['return'], true)) {
                return null;
            }
        }

        $mode = $params['mode'] ?? 'methods';
        return new self($path, $page ? (in_array($mode, ['methods', 'email', 'password'], true) ? $mode : 'methods') : null,
            PublicReturnPath::normalize($params['return'] ?? '/'), $params === []);
    }

    /** @param array<string,mixed> $server
     *  @return array<string,mixed>
     */
    public function cacheServer(array $server): array
    {
        $server['REQUEST_URI'] = $this->path;
        $server['REGISTER_EARLY_VARIANT'] = (string)($server['REGISTER_EARLY_VARIANT'] ?? 'reader')
            . ':auth:' . ($this->mode ?? 'method') . ($this->mode === 'methods' && $this->queryFree ? ':plain' : '');
        return $server;
    }

    public function expand(string $body): string
    {
        return strtr($body, [
            rawurlencode(self::RETURN_MARKER) => rawurlencode($this->returnPath),
            self::RETURN_MARKER => htmlspecialchars($this->returnPath, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ]);
    }
}
