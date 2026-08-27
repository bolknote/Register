<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Core\Model\AuthProvider;
use Register\Module\Analytics\BotDetector;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Symfony\Component\HttpFoundation\Request;

/** Selects a deterministic guest-response variant without mixing authenticated page chrome. */
final readonly class BlogResponseCachePolicy
{
    public function __construct(
        private AuthProvider           $authProvider,
        private VisitorIdentityManager $visitorIdentityManager,
        private BotDetector            $botDetector,
    ) {
    }

    public function variant(Request $request): ?string
    {
        if (!$request->isMethod(Request::METHOD_GET)
            || $request->query->count() !== 0
            || $request->headers->has('Authorization')
            || $this->authProvider->hasAuthenticatedPublicSession($request)
        ) {
            return null;
        }

        $navigation = $request->headers->get('X-Register-Navigation');
        if ($navigation !== null && $navigation !== 'partial') {
            return null;
        }

        $representation = $navigation === 'partial' ? 'partial' : 'full';
        if ($this->isNonInteractive($request)) {
            return $representation . '_bot';
        }

        $visitor = $this->visitorIdentityManager->visitorIdFromRequest($request) === null
            ? 'new_visitor'
            : 'known_visitor';

        return $representation . '_' . $visitor;
    }

    /** Browser preloads must not mint a visitor-bound comment form which will never be used. */
    private function isNonInteractive(Request $request): bool
    {
        if ($this->botDetector->isBot($request->headers->get('User-Agent', '') ?? '')) {
            return true;
        }

        $purpose = strtolower(trim(implode(' ', [
            $request->headers->get('Purpose', '') ?? '',
            $request->headers->get('Sec-Purpose', '') ?? '',
        ])));

        return str_contains($purpose, 'prefetch');
    }
}
