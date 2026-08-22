<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Presentation;

use Register\Content\ContentRenderedEvent;
use s2_extensions\activitypub\Application\PublicFederationAccess;
use s2_extensions\activitypub\Domain\FederationUrlGeneratorFactory;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\StoredObjectRepresentation;

/** Adds machine discovery to content and an h-card to the configured human actor profile. */
final readonly class HtmlFederationLinker
{
    public function __construct(
        private PublicFederationAccess        $access,
        private FederationStateRepository     $stateRepository,
        private LocalActorRepository           $actorRepository,
        private LocalFederationRepository      $federationRepository,
        private FederationUrlGeneratorFactory $urlGeneratorFactory,
    ) {
    }

    public function enrich(ContentRenderedEvent $event): void
    {
        if (!$this->access->installationIsPublic()) {
            return;
        }

        $object = $this->federationRepository->findLiveObject($event->contentId);
        if (!$object instanceof StoredObjectRepresentation) {
            return;
        }

        $actor = $this->actorRepository->findById($object->ownerActorId);
        if (!$actor instanceof LocalActor || !$this->access->actorIsPublic($actor)) {
            return;
        }

        $urls      = $this->urlGeneratorFactory->create();
        $objectUrl = $urls->object($object->publicId);
        $actorUrl  = $urls->actor($actor->publicId);
        $event->template
            ->addMetaTag('<link rel="alternate" type="application/activity+json" href="' . $this->escape($objectUrl) . '">')
            ->addMetaTag('<link rel="author" type="application/activity+json" href="' . $this->escape($actorUrl) . '">')
        ;

        if (!$this->sameUrl($object->canonicalUrl, $actor->profileUrl)) {
            return;
        }

        $event->template->addMetaTag('<link rel="me" href="' . $this->escape($actorUrl) . '">');

        $text = $event->template->getFromPlaceholder('text');
        if (!\is_string($text) || str_contains($text, 'data-register-activitypub-h-card')) {
            return;
        }

        $origin = $this->stateRepository->state()->canonicalOrigin;
        if (!$origin instanceof \s2_extensions\activitypub\Domain\CanonicalOrigin) {
            return;
        }

        $account = '@' . $actor->handle . '@' . $origin->host;
        $photo   = $this->photo($actor);
        $note    = trim(strip_tags($actor->summaryHtml));
        $card    = '<aside class="h-card register-activitypub-profile" data-register-activitypub-h-card>'
            . $photo
            . '<a class="u-url p-name" href="' . $this->escape($actor->profileUrl) . '">'
            . $this->escape($actor->displayName) . '</a> '
            . '<a class="u-uid" rel="me" type="application/activity+json" href="' . $this->escape($actorUrl) . '">'
            . $this->escape($account) . '</a>'
            . ($note === '' ? '' : '<p class="p-note">' . $this->escape($note) . '</p>')
            . '</aside>';
        $event->template->putInPlaceholder('text', $text . "\n" . $card);
    }

    private function photo(LocalActor $actor): string
    {
        $url = $actor->avatar['url'] ?? null;
        if (!\is_string($url)) {
            return '';
        }

        if (!str_starts_with($url, 'https://')) {
            return '';
        }

        return '<img class="u-photo" src="' . $this->escape($url) . '" alt=""> ';
    }

    private function sameUrl(string $left, string $right): bool
    {
        return rtrim($left, '/') === rtrim($right, '/');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
