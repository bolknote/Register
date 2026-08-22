<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Presentation;

use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Domain\LocalActorKey;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;

final readonly class ActorKeyDocumentBuilder
{
    public function __construct(
        private LocalActorRepository          $actorRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(LocalActorKey $key): array
    {
        $actor = $this->actorRepository->findById($key->actorId);
        if (!$actor instanceof LocalActor) {
            throw new \RuntimeException('The ActivityPub key owner is missing.');
        }

        $urls = $this->urlGeneratorFactory->create();

        return [
            '@context'     => ActivityStreamsContext::SECURITY_V1,
            'id'           => $urls->key($key->publicId),
            'type'         => 'CryptographicKey',
            'owner'        => $urls->actor($actor->publicId),
            'publicKeyPem' => $key->publicKeyPem,
        ];
    }

}
