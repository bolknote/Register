<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace s2_extensions\activitypub\Admin;

use Register\Author\AuthorProfileRepository;
use S2\AdminYard\TemplateRenderer;
use S2\AdminYard\Translator;
use S2\Cms\Queue\QueueMonitor;
use s2_extensions\activitypub\Domain\CollectionAnchor;
use s2_extensions\activitypub\Application\ActivityPubIdentityRecoveryService;
use s2_extensions\activitypub\Domain\LocalActor;
use s2_extensions\activitypub\Infrastructure\FederationStateRepository;
use s2_extensions\activitypub\Infrastructure\ActivationReadinessRepository;
use s2_extensions\activitypub\Infrastructure\ActivityPubRunnerTelemetryRepository;
use s2_extensions\activitypub\Infrastructure\LocalActorRepository;
use s2_extensions\activitypub\Infrastructure\LocalFederationRepository;
use s2_extensions\activitypub\Infrastructure\ContentBackfillRepository;
use s2_extensions\activitypub\Infrastructure\ReaderRepository;
use s2_extensions\activitypub\Security\CollectionCursorCodec;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class ActivityPubAdminPage
{
    private const int PAGE_SIZE = 20;

    public function __construct(
        private ActivityPubAdminRepository $adminRepository,
        private ActivityPubIdentityRecoveryService $identityRecovery,
        private FederationStateRepository  $stateRepository,
        private ActivationReadinessRepository $activationRepository,
        private LocalActorRepository       $actorRepository,
        private LocalFederationRepository  $federationRepository,
        private ContentBackfillRepository  $backfillRepository,
        private AuthorProfileRepository    $authorProfileRepository,
        private ReaderRepository           $readerRepository,
        private CollectionCursorCodec      $cursorCodec,
        private ActivityPubToken           $token,
        private QueueMonitor               $queueMonitor,
        private ActivityPubRunnerTelemetryRepository $runnerTelemetry,
        private TemplateRenderer           $templateRenderer,
        private Translator                 $translator,
        private RequestStack               $requestStack,
        private ActivityPubAdminAccess     $access,
        private string                     $basePath,
        private string                     $baseUrl,
    ) {
    }

    public function title(): string
    {
        return $this->translator->trans('ActivityPub');
    }

    public function render(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $state    = $this->stateRepository->state();
        $userId = $this->access->currentAuthorId();
        $canManageSite = $this->access->canManageSite();
        $canPublish = $userId !== null;
        $ownActiveActor = $canPublish
            ? $this->actorRepository->activeAuthorActor($userId)
            : null;
        $ownActor = $canPublish
            ? $this->actorRepository->authorActor($userId)
            : null;
        $ownPublisher = $canPublish
            ? $this->authorProfileRepository->find($userId)
            : null;
        $actors = $canManageSite
            ? $this->actorRepository->activeActors()
            : ($ownActiveActor instanceof LocalActor ? [$ownActiveActor] : []);
        $publishers = $canManageSite
            ? $this->authorProfileRepository->publishers()
            : ($ownPublisher?->canPublish === true ? [$ownPublisher] : []);
        $authorActors = [];
        $allVisibleActors = $canManageSite
            ? $this->actorRepository->allActors()
            : ($ownActor instanceof LocalActor ? [$ownActor] : []);
        foreach ($allVisibleActors as $actor) {
            if ($actor->authorId !== null) {
                $authorActors[$actor->authorId] = $actor;
            }
        }

        $selectedActor = $this->selectedActor($request, $actors);
        $canManageActor = $selectedActor instanceof LocalActor
            && ($canManageSite || ($canPublish && $selectedActor->authorId === $userId));
        $view = 'feed';
        $cursorValue = '';
        if ($request instanceof Request) {
            $view = $request->query->getString('view') === 'direct' ? 'direct' : 'feed';
            $cursorValue = $request->query->getString('cursor');
        }

        $scope = $selectedActor instanceof LocalActor
            ? 'admin-reader:' . $selectedActor->publicId . ':' . $view
            : '';
        $anchor = null;
        $cursorError = false;
        if ($cursorValue !== '' && $scope !== '') {
            try {
                $anchor = $this->cursorCodec->decode($scope, $cursorValue);
            } catch (\InvalidArgumentException) {
                $cursorError = true;
            }
        }

        $entries = $selectedActor instanceof LocalActor && !$cursorError
            ? $this->readerRepository->page($selectedActor->id, $view, $anchor, self::PAGE_SIZE + 1)
            : [];
        $hasMore = \count($entries) > self::PAGE_SIZE;
        $entries = array_slice($entries, 0, self::PAGE_SIZE);
        $nextCursor = null;
        $last = $entries === [] ? null : $entries[\count($entries) - 1];
        if ($hasMore && $last !== null) {
            $nextCursor = $this->cursorCodec->encode($scope, new CollectionAnchor($last->sortAt, $last->objectId));
        }

        $localNotes = $selectedActor instanceof LocalActor && $entries !== []
            ? $this->federationRepository->liveLocalNotesForTargets(
                $selectedActor->id,
                array_map(static fn($entry): string => $entry->objectUrl, $entries),
            )
            : [];
        $follows = $selectedActor instanceof LocalActor
            ? $this->adminRepository->outgoingFollows($selectedActor->id)
            : [];
        $summary = $canManageSite ? $this->adminRepository->summary() : [];
        $identityHealth = $canManageSite ? $this->identityRecovery->audit() : null;
        $queueStatus = $canManageSite ? $this->queueMonitor->status() : null;
        $runnerStatus = $canManageSite ? $this->runnerTelemetry->status() : null;

        return $this->templateRenderer->render(__DIR__ . '/../resources/views/admin.php.inc', [
            'lifecycle'     => $state->lifecycle,
            'draftActor'    => $state->lifecycle->value === 'installed' ? $this->actorRepository->siteActor() : null,
            'activationAttempt' => $state->lifecycle->value === 'installed'
                ? $this->activationRepository->latest()
                : null,
            'activationChecks' => \s2_extensions\activitypub\Application\ActivationReadinessCheck::cases(),
            'configuredOrigin' => $this->configuredOrigin(),
            'configuredBasePath' => $this->basePath,
            'wellKnownRewrite' => $this->wellKnownRewrite(),
            'summary'       => $summary,
            'identityHealth' => $identityHealth,
            'queueStatus'   => $queueStatus,
            'runnerStatus'  => $runnerStatus,
            'failureDomains' => $canManageSite ? $this->adminRepository->failuresByDomain() : [],
            'moderationRules' => $canManageSite ? $this->adminRepository->moderationRules() : [],
            'actors'        => $actors,
            'publishers'     => $publishers,
            'authorActors'   => $authorActors,
            'selectedActor' => $selectedActor,
            'follows'       => $follows,
            'readerView'    => $view,
            'readerEntries' => $entries,
            'localNotes'     => $localNotes,
            'backfillJobs'   => $canManageSite ? $this->backfillRepository->recent() : [],
            'readerCount'   => $selectedActor instanceof LocalActor
                ? $this->readerRepository->count($selectedActor->id, $view)
                : 0,
            'nextCursor'    => $nextCursor,
            'cursorError'   => $cursorError,
            'csrfToken'     => $this->token->value(),
            'canManageSite' => $canManageSite,
            'canManageActors' => $canManageSite || $canPublish,
            'canManageActor' => $canManageActor,
            'basePath'      => $this->basePath,
        ]);
    }

    /** @param list<LocalActor> $actors */
    private function selectedActor(?Request $request, array $actors): ?LocalActor
    {
        $requestedId = $request instanceof Request ? $request->query->getInt('actor_id') : 0;
        foreach ($actors as $actor) {
            if ($requestedId === 0 || $actor->id === $requestedId) {
                return $actor;
            }
        }

        return $actors[0] ?? null;
    }

    private function configuredOrigin(): string
    {
        $parts = parse_url($this->baseUrl);
        if (!\is_array($parts) || !\is_string($parts['host'] ?? null)) {
            return $this->baseUrl;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');

        return $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private function wellKnownRewrite(): string
    {
        if ($this->basePath === '') {
            return '';
        }

        $substitution = rtrim($this->basePath, '/') . '/index.php';

        return "RewriteEngine On\nRewriteRule ^\\.well-known/(webfinger|nodeinfo)$ "
            . $substitution . ' [END,QSA]';
    }
}
