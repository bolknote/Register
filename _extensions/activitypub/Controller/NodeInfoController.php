<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Controller;

use S2\Cms\Config\StringProxy;
use S2\Cms\Framework\ControllerInterface;
use s2_extensions\activitypub\Application\PublicFederationAccess;
use s2_extensions\activitypub\Http\ActivityPubResponseFactory;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Manifest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class NodeInfoController implements ControllerInterface
{
    public function __construct(
        private PublicFederationAccess     $access,
        private LocalActorRepository       $actorRepository,
        private LocalFederationRepository  $federationRepository,
        private ActivityPubResponseFactory $responseFactory,
        private StringProxy                $siteName,
        private string                     $registerVersion,
    ) {
    }

    #[\Override]
    public function handle(Request $request): Response
    {
        if (!$this->access->installationIsPublic()) {
            return $this->responseFactory->notFound($request);
        }

        return $this->responseFactory->nodeInfo($request, [
            'version' => '2.1',
            'software' => [
                'name'       => 'register',
                'version'    => $this->productVersion(),
                'repository' => 'https://github.com/bolknote/Register',
            ],
            'protocols'         => ['activitypub'],
            'services'          => ['inbound' => [], 'outbound' => []],
            'openRegistrations' => false,
            'usage'             => [
                'users' => [
                    'total'          => $this->actorRepository->publicActorCount(),
                    'activeMonth'    => 0,
                    'activeHalfyear' => 0,
                ],
                'localPosts' => $this->federationRepository->localObjectCount(),
            ],
            'metadata' => [
                'nodeName'                   => $this->siteName->get(),
                'registerActivityPubVersion' => Manifest::VERSION,
            ],
        ]);
    }

    private function productVersion(): string
    {
        $version = $this->registerVersion;

        return preg_match('/^[0-9]+(?:\.[0-9]+){1,2}/D', $version, $match) === 1 ? $match[0] : '0.1.0';
    }
}
