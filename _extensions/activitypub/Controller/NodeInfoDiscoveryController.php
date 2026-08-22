<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace Register\Extension\activitypub\Controller;

use Register\Core\Framework\ControllerInterface;
use Register\Extension\activitypub\Application\PublicFederationAccess;
use Register\Extension\activitypub\Domain\FederationUrlGeneratorFactory;
use Register\Extension\activitypub\Http\ActivityPubResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class NodeInfoDiscoveryController implements ControllerInterface
{
    public function __construct(
        private PublicFederationAccess         $access,
        private FederationUrlGeneratorFactory  $urlGeneratorFactory,
        private ActivityPubResponseFactory     $responseFactory,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$this->access->installationIsPublic()) {
            return $this->responseFactory->notFound($request);
        }

        return $this->responseFactory->webFinger($request, [
            'links' => [[
                'rel'  => 'http://nodeinfo.diaspora.software/ns/schema/2.1',
                'href' => $this->urlGeneratorFactory->create()->nodeInfo(),
            ]],
        ]);
    }
}
