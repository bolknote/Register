<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth;

final readonly class LinkProbeState
{
    public const int MAX_REDIRECTS = 5;

    public function __construct(
        public string          $url,
        public LinkProbeMethod $method = LinkProbeMethod::HEAD,
        public int             $redirects = 0,
    ) {
        if ($this->url === '') {
            throw new \InvalidArgumentException('A link-probe URL cannot be empty.');
        }

        if ($this->redirects < 0 || $this->redirects > self::MAX_REDIRECTS) {
            throw new \InvalidArgumentException('A link-probe redirect count is out of range.');
        }
    }

    public static function initial(string $url): self
    {
        return new self($url);
    }

    /**
     * @param array<mixed> $payload
     */
    public static function fromPayload(array $payload): self
    {
        $url         = $payload['url'] ?? null;
        $methodValue = $payload['method'] ?? null;
        $redirects   = $payload['redirects'] ?? null;
        if (!\is_string($url)
            || $url === ''
            || !\is_string($methodValue)
            || !\is_int($redirects)
            || array_diff_key($payload, ['url' => true, 'method' => true, 'redirects' => true]) !== []
        ) {
            throw new \InvalidArgumentException('Invalid link-probe continuation state.');
        }

        $method = LinkProbeMethod::tryFrom($methodValue);
        if (!$method instanceof LinkProbeMethod) {
            throw new \InvalidArgumentException('Invalid link-probe continuation method.');
        }

        return new self($url, $method, $redirects);
    }

    /** @return array{url: string, method: string, redirects: int} */
    public function toPayload(): array
    {
        return [
            'url'       => $this->url,
            'method'    => $this->method->value,
            'redirects' => $this->redirects,
        ];
    }
}
