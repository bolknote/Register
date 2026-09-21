<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

use Symfony\Component\HttpFoundation\Request;

/** Keeps cached crawler and browser-prefetch responses free of browser-only work. */
final readonly class NonInteractiveRequestDetector
{
    public function __construct(private BotDetector $botDetector)
    {
    }

    public function reason(Request $request): ?string
    {
        if ($this->botDetector->isBot($request->headers->get('User-Agent', '') ?? '')) {
            return 'bot';
        }

        $purpose = strtolower(trim(implode(' ', [
            $request->headers->get('Purpose', '') ?? '',
            $request->headers->get('Sec-Purpose', '') ?? '',
        ])));

        return str_contains($purpose, 'prefetch') ? 'prefetch' : null;
    }
}
