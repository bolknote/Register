<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Blog\Model;

use Register\Core\Model\AuthProvider;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Symfony\Component\HttpFoundation\Request;

/** Selects a deterministic guest-response variant without mixing authenticated page chrome. */
final readonly class BlogResponseCachePolicy
{
    public function __construct(
        private AuthProvider           $authProvider,
        private VisitorIdentityManager $visitorIdentityManager,
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
        $visitor = $this->visitorIdentityManager->visitorIdFromRequest($request) === null
            ? 'new_visitor'
            : 'known_visitor';

        return $representation . '_' . $visitor;
    }
}
