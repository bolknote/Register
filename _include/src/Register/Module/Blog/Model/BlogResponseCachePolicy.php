<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Core\Http\Cache\QueryParameterDependencies;
use Register\Core\Model\AuthProvider;
use Register\Module\Analytics\NonInteractiveRequestDetector;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Symfony\Component\HttpFoundation\Request;

/** Selects a deterministic guest-response variant without mixing authenticated page chrome. */
final readonly class BlogResponseCachePolicy
{
    public const string DECISION_ATTRIBUTE = '_register_page_cache_policy';

    public function __construct(
        private AuthProvider           $authProvider,
        private VisitorIdentityManager $visitorIdentityManager,
        private NonInteractiveRequestDetector $nonInteractiveRequestDetector,
    ) {
    }

    public function variant(Request $request, QueryParameterDependencies $queryDependencies): ?string
    {
        if (!$request->isMethod(Request::METHOD_GET)) {
            $this->decision($request, 'method');

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
        if ($queryDependencies->affectResponse($request)) {
            $this->decision($request, 'query');

            return null;
        }

        $nonInteractive = $this->nonInteractiveRequestDetector->reason($request);
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

    private function decision(Request $request, string $decision): void
    {
        $request->attributes->set(self::DECISION_ATTRIBUTE, $decision);
    }
}
