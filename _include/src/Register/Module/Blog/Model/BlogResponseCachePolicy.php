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
    public const string DECISION_ATTRIBUTE = '_register_page_cache_policy';

    public function __construct(
        private AuthProvider           $authProvider,
        private VisitorIdentityManager $visitorIdentityManager,
        private BotDetector            $botDetector,
    ) {
    }

    public function variant(Request $request): ?string
    {
        if (!$request->isMethod(Request::METHOD_GET)) {
            $this->decision($request, 'method');

            return null;
        }
        if ($request->query->count() !== 0) {
            $this->decision($request, 'query');

            return null;
        }
        if ($request->headers->has('Authorization')) {
            $this->decision($request, 'authorization');

            return null;
        }
        if ($this->authProvider->hasAuthenticatedPublicSession($request)) {
            $this->decision($request, 'authenticated');

            return null;
        }

        $navigation = $request->headers->get('X-Register-Navigation');
        if ($navigation !== null && $navigation !== 'partial') {
            $this->decision($request, 'navigation');

            return null;
        }

        $representation = $navigation === 'partial' ? 'partial' : 'full';
        $nonInteractive = $this->nonInteractiveReason($request);
        if ($nonInteractive !== null) {
            $this->decision($request, $nonInteractive);

            return $representation . '_bot';
        }

        $visitor = $this->visitorIdentityManager->visitorIdFromRequest($request) === null
            ? 'new_visitor'
            : 'known_visitor';
        $this->decision($request, $visitor);

        return $representation . '_' . $visitor;
    }

    /** Browser preloads must not mint a visitor-bound comment form which will never be used. */
    private function nonInteractiveReason(Request $request): ?string
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

    private function decision(Request $request, string $decision): void
    {
        $request->attributes->set(self::DECISION_ATTRIBUTE, $decision);
    }
}
