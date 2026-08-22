<?php

declare(strict_types = 1);

use Register\Author\AuthorProfile;
use Register\Extension\activitypub\Domain\FederationLifecycleState;
use Register\Extension\activitypub\Domain\FederationPolicy;
use Register\Extension\activitypub\Domain\LocalActor;
use Register\Extension\activitypub\Domain\ReaderEntry;
use Register\Extension\activitypub\Application\IdentityHealthReport;
use Register\Extension\activitypub\Application\ActivationReadinessAttempt;
use Register\Extension\activitypub\Application\ActivationReadinessCheck;
use Register\Extension\activitypub\Application\ActivationReadinessState;
use Register\Extension\activitypub\Infrastructure\StoredLocalNoteRepresentation;
use Register\Extension\activitypub\Infrastructure\ContentBackfillJob;

/** @var callable $trans */
/** @var FederationLifecycleState $lifecycle */
/** @var FederationPolicy $policy */
/** @var ?LocalActor $draftActor */
/** @var ?ActivationReadinessAttempt $activationAttempt */
/** @var list<ActivationReadinessCheck> $activationChecks */
/** @var string $configuredOrigin */
/** @var string $configuredBasePath */
/** @var string $wellKnownRewrite */
/** @var array<string, int> $summary */
/** @var ?IdentityHealthReport $identityHealth */
/** @var array{total:int, ready:int, delayed:int, failed:int, oldest_ready_age:int|null, runner_active:bool, runner_lease_expires_at:int}|null $queueStatus */
/** @var array{last_runner_at:int|null, last_runner_code:string}|null $runnerStatus */
/** @var list<array{direction:string, origin:string, failure_count:int, last_failure_at:int}> $failureDomains */
/** @var list<array{id:int, scope:string, match_value:string, action:string, priority:int, updated_at:int}> $moderationRules */
/** @var list<LocalActor> $actors */
/** @var list<AuthorProfile> $publishers */
/** @var array<int, LocalActor> $authorActors */
/** @var ?LocalActor $selectedActor */
/** @var list<array<string, int|string|null>> $follows */
/** @var string $readerView */
/** @var list<ReaderEntry> $readerEntries */
/** @var array<string, list<StoredLocalNoteRepresentation>> $localNotes */
/** @var list<ContentBackfillJob> $backfillJobs */
/** @var int $readerCount */
/** @var ?string $nextCursor */
/** @var bool $cursorError */
/** @var string $csrfToken */
/** @var bool $canManageSite */
/** @var bool $canManageActors */
/** @var bool $canManageActor */
/** @var string $basePath */

$html = static fn(string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
    'UTF-8',
);
$scalar = static fn(mixed $value): string => \is_string($value) || \is_int($value) || \is_float($value)
    ? (string)$value
    : '';
$formatBytes = static function (int $bytes): string {
    if ($bytes < 1_024) {
        return $bytes . ' B';
    }
    $units = ['KiB', 'MiB', 'GiB'];
    $value = $bytes / 1_024;
    foreach ($units as $unit) {
        if ($value < 1_024 || $unit === 'GiB') {
            return number_format($value, $value >= 100 ? 0 : 1, '.', ' ') . ' ' . $unit;
        }
        $value /= 1_024;
    }

    throw new \LogicException('Unreachable byte formatting state.');
};
$localNoteText = static function (string $snapshotJson): string {
    $document = json_decode($snapshotJson, true, 32, JSON_THROW_ON_ERROR);
    if (!\is_array($document) || array_is_list($document) || !\is_string($document['content'] ?? null)) {
        throw new \RuntimeException('A local ActivityPub Note snapshot is invalid.');
    }
    $withBreaks = str_ireplace(['<br>', '<br/>', '<br />', '</p>'], ["\n", "\n", "\n", "\n\n"], $document['content']);

    return trim(html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
};
$lifecycleLabel = $trans('Federation state ' . $lifecycle->value);
$actorNames = [];
foreach ($actors as $actor) {
    $actorNames[$actor->id] = $actor->displayName . ' (@' . $actor->handle . ')';
}
$readerUrl = static function (
    LocalActor $actor,
    string     $view,
    ?string    $cursor = null,
): string {
    return '?entity=ActivityPub&actor_id=' . $actor->id
        . '&view=' . rawurlencode($view)
        . ($cursor === null ? '' : '&cursor=' . rawurlencode($cursor));
};
$draftProfileUrl = $draftActor?->profileUrl
    ?? rtrim($configuredOrigin . $configuredBasePath, '/') . '/';
$draftAvatar = $draftActor?->avatar;
$draftAvatarUrl = \is_array($draftAvatar) && \is_string($draftAvatar['url'] ?? null)
    ? $draftAvatar['url']
    : '';
$draftHeader = $draftActor?->header;
$draftHeaderUrl = \is_array($draftHeader) && \is_string($draftHeader['url'] ?? null)
    ? $draftHeader['url']
    : '';
?>
<section class="activitypub-admin"
         data-activitypub-admin
         data-action-endpoint="<?= $html($basePath . '/_admin/ajax.php?action=register_activitypub_action') ?>"
         data-csrf-token="<?= $html($csrfToken) ?>"
         data-working-message="<?= $html($trans('Federation action working')) ?>"
         data-failure-message="<?= $html($trans('Federation action failed')) ?>"
         data-success-message="<?= $html($trans('Federation operation succeeded')) ?>"
         data-activation-pending="<?= $activationAttempt?->state === ActivationReadinessState::CHECKING ? '1' : '0' ?>">
    <header class="activitypub-hero">
        <div>
            <p class="activitypub-kicker">ActivityPub / Fediverse</p>
            <h1><?= $trans('ActivityPub federation') ?></h1>
            <p><?= $trans('Federation private reader description') ?></p>
        </div>
        <div class="activitypub-lifecycle state-<?= $html($lifecycle->value) ?>">
            <span><?= $trans('Federation state') ?></span>
            <strong><?= $html($lifecycleLabel) ?></strong>
        </div>
    </header>

    <p class="activitypub-action-status" data-action-status role="status" aria-live="polite"></p>

    <?php if ($lifecycle === FederationLifecycleState::INSTALLED): ?>
        <section class="activitypub-panel activitypub-setup" aria-labelledby="activitypub-setup-title">
            <div class="activitypub-panel-heading">
                <div>
                    <p class="activitypub-eyebrow">Two-phase activation</p>
                    <h2 id="activitypub-setup-title"><?= $trans('Federation setup title') ?></h2>
                </div>
                <?php if ($activationAttempt instanceof ActivationReadinessAttempt): ?>
                    <span class="activitypub-setup-state state-<?= $html($activationAttempt->state->value) ?>">
                        <?= $trans('Federation setup state ' . $activationAttempt->state->value) ?>
                    </span>
                <?php endif; ?>
            </div>
            <p><?= $trans('Federation setup help') ?></p>
            <?php if ($wellKnownRewrite !== ''): ?>
                <div class="activitypub-rewrite-help">
                    <strong><?= $trans('Federation root rewrite') ?></strong>
                    <p><?= $trans('Federation root rewrite help') ?></p>
                    <pre><code><?= $html($wellKnownRewrite) ?></code></pre>
                </div>
            <?php endif; ?>

            <?php if ($canManageSite): ?>
                <form data-activitypub-form class="activitypub-setup-form">
                    <input type="hidden" name="operation" value="setup_start">
                    <fieldset>
                        <legend><?= $trans('Federation setup identity') ?></legend>
                        <label>
                            <span><?= $trans('Federation actor type') ?></span>
                            <select name="actor_type" required>
                                <?php foreach (['Service', 'Organization'] as $actorType): ?>
                                    <option value="<?= $actorType ?>"
                                        <?= ($draftActor?->type->value ?? 'Service') === $actorType ? ' selected' : '' ?>>
                                        <?= $actorType ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?= $trans('Federation setup handle') ?></span>
                            <input type="text" name="handle" required maxlength="32"
                                   pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,31}"
                                   value="<?= $html($draftActor?->handle ?? 'blog') ?>"
                                   autocomplete="off" spellcheck="false">
                        </label>
                        <label class="activitypub-wide-field">
                            <span><?= $trans('Federation setup display name') ?></span>
                            <input type="text" name="display_name" required maxlength="255"
                                   value="<?= $html($draftActor?->displayName ?? '') ?>">
                        </label>
                        <label class="activitypub-wide-field">
                            <span><?= $trans('Federation setup summary') ?></span>
                            <textarea name="summary_html" rows="4"><?= $html($draftActor?->summaryHtml ?? '') ?></textarea>
                        </label>
                        <label class="activitypub-wide-field">
                            <span><?= $trans('Federation setup profile URL') ?></span>
                            <input type="url" name="profile_url" required maxlength="2048"
                                   value="<?= $html($draftProfileUrl) ?>">
                        </label>
                        <label class="activitypub-wide-field">
                            <span><?= $trans('Federation setup avatar URL') ?></span>
                            <input type="url" name="avatar_url" maxlength="2048"
                                   value="<?= $html($draftAvatarUrl) ?>">
                        </label>
                        <label class="activitypub-wide-field">
                            <span><?= $trans('Federation setup header URL') ?></span>
                            <input type="url" name="header_url" maxlength="2048"
                                   value="<?= $html($draftHeaderUrl) ?>">
                        </label>
                        <label class="activitypub-checkbox activitypub-wide-field">
                            <input type="checkbox" name="discoverable" value="1"
                                <?= ($draftActor?->discoverable ?? true) ? ' checked' : '' ?>>
                            <span><?= $trans('Federation setup discoverable') ?></span>
                        </label>
                    </fieldset>
                    <fieldset>
                        <legend><?= $trans('Federation setup canonical address') ?></legend>
                        <label class="activitypub-wide-field">
                            <span><?= $trans('Federation canonical origin') ?></span>
                            <input type="url" name="canonical_origin" required
                                   value="<?= $html($activationAttempt?->canonicalOrigin->value ?? $configuredOrigin) ?>">
                        </label>
                        <label class="activitypub-wide-field">
                            <span><?= $trans('Federation base path') ?></span>
                            <input type="text" name="base_path"
                                   value="<?= $html($activationAttempt?->basePath->value ?? $configuredBasePath) ?>"
                                   placeholder="/register">
                        </label>
                        <p class="activitypub-setup-warning activitypub-wide-field">
                            <?= $trans('Federation identity freeze warning') ?>
                        </p>
                    </fieldset>
                    <div class="activitypub-setup-submit">
                        <button type="submit" class="primary">
                            <?= $activationAttempt instanceof ActivationReadinessAttempt
                                ? $trans('Federation rerun checks')
                                : $trans('Federation prepare identity') ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <?php if ($activationAttempt instanceof ActivationReadinessAttempt): ?>
                <ol class="activitypub-check-list">
                    <?php foreach ($activationChecks as $check):
                        $result = $activationAttempt->result($check);
                        $checkState = $result === null ? 'pending' : ($result->passed ? 'passed' : 'failed');
                        ?>
                        <li class="state-<?= $checkState ?>">
                            <span aria-hidden="true"></span>
                            <div>
                                <strong><?= $trans('Federation check ' . $check->value) ?></strong>
                                <small><?= $html($result?->detail ?? $trans('Federation check pending')) ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
                <?php if ($activationAttempt->state === ActivationReadinessState::CHECKING): ?>
                    <p class="activitypub-setup-progress"><?= $trans('Federation checks progress') ?></p>
                <?php elseif ($activationAttempt->state === ActivationReadinessState::READY && $canManageSite): ?>
                    <form data-activitypub-form class="activitypub-activation-confirm">
                        <input type="hidden" name="operation" value="setup_activate">
                        <input type="hidden" name="attempt_id" value="<?= $html($activationAttempt->id) ?>">
                        <label>
                            <span><?= $trans('Federation activation phrase') ?></span>
                            <input type="text" name="confirmation" required autocomplete="off"
                                   pattern="ACTIVATE" placeholder="ACTIVATE">
                        </label>
                        <button type="submit" class="primary"><?= $trans('Federation activate') ?></button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canManageSite && \in_array($lifecycle, [
        FederationLifecycleState::INSTALLED,
        FederationLifecycleState::ACTIVE,
        FederationLifecycleState::PAUSED,
    ], true)): ?>
        <section class="activitypub-panel activitypub-policy" aria-labelledby="activitypub-policy-title">
            <div class="activitypub-panel-heading">
                <div>
                    <p class="activitypub-eyebrow"><?= $trans('Federation policy eyebrow') ?></p>
                    <h2 id="activitypub-policy-title"><?= $trans('Federation policy title') ?></h2>
                </div>
            </div>
            <p><?= $trans('Federation policy help') ?></p>
            <form data-activitypub-form class="activitypub-setup-form activitypub-policy-form">
                <input type="hidden" name="operation" value="policy_save">
                <fieldset class="activitypub-wide-field">
                    <legend><?= $trans('Federation policy defaults') ?></legend>
                    <label class="activitypub-checkbox">
                        <input type="checkbox" name="posts_enabled" value="1"<?= $policy->postsEnabled ? ' checked' : '' ?>>
                        <span><?= $trans('Federation policy posts enabled') ?></span>
                    </label>
                    <label class="activitypub-checkbox">
                        <input type="checkbox" name="pages_enabled" value="1"<?= $policy->pagesEnabled ? ' checked' : '' ?>>
                        <span><?= $trans('Federation policy pages enabled') ?></span>
                    </label>
                    <label>
                        <span><?= $trans('Federation policy post object type') ?></span>
                        <select name="post_object_type" required>
                            <?php foreach (['Article', 'Note'] as $objectType): ?>
                                <option value="<?= $objectType ?>"<?= $policy->postObjectType->value === $objectType ? ' selected' : '' ?>>
                                    <?= $objectType ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span><?= $trans('Federation policy content mode') ?></span>
                        <select name="content_mode" required>
                            <option value="full"<?= $policy->contentMode->value === 'full' ? ' selected' : '' ?>>
                                <?= $trans('Full federated text') ?>
                            </option>
                            <option value="excerpt"<?= $policy->contentMode->value === 'excerpt' ? ' selected' : '' ?>>
                                <?= $trans('Excerpt and canonical link') ?>
                            </option>
                        </select>
                    </label>
                    <label>
                        <span><?= $trans('Federation policy visibility') ?></span>
                        <select name="default_visibility" required>
                            <option value="public"<?= $policy->defaultVisibility === 'public' ? ' selected' : '' ?>><?= $trans('Public') ?></option>
                            <option value="unlisted"<?= $policy->defaultVisibility === 'unlisted' ? ' selected' : '' ?>><?= $trans('Unlisted') ?></option>
                        </select>
                    </label>
                    <p class="activitypub-setup-warning activitypub-wide-field">
                        <?= $trans('Federation policy safety') ?>
                    </p>
                </fieldset>
                <div class="activitypub-setup-submit">
                    <button type="submit" class="primary"><?= $trans('Federation policy save') ?></button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <section class="activitypub-panel activitypub-actors" aria-labelledby="activitypub-actors-title">
        <div class="activitypub-panel-heading">
            <div>
                <p class="activitypub-eyebrow">Identity</p>
                <h2 id="activitypub-actors-title"><?= $trans('Federation actors') ?></h2>
            </div>
        </div>
        <?php if ($actors === []): ?>
            <div class="info-box"><p><?= $trans('Federation no active actors') ?></p></div>
        <?php else: ?>
            <nav class="activitypub-actor-tabs" aria-label="<?= $html($trans('Federation actor')) ?>">
                <?php foreach ($actors as $actor): ?>
                    <a href="<?= $readerUrl($actor, $readerView) ?>"
                       <?= $selectedActor?->id === $actor->id ? 'aria-current="page"' : '' ?>>
                        <strong><?= $html($actor->displayName) ?></strong>
                        <span>@<?= $html($actor->handle) ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    </section>

    <?php if ($canManageActors
        && $publishers !== []
        && \in_array($lifecycle, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED], true)): ?>
        <section class="activitypub-panel activitypub-authors" aria-labelledby="activitypub-authors-title">
            <div class="activitypub-panel-heading">
                <div>
                    <p class="activitypub-eyebrow">Multi-author federation</p>
                    <h2 id="activitypub-authors-title"><?= $trans('Federation author identities') ?></h2>
                </div>
            </div>
            <p><?= $trans('Federation author identities help') ?></p>
            <div class="activitypub-author-grid">
                <?php foreach ($publishers as $publisher):
                    $authorActor = $authorActors[$publisher->id] ?? null;
                    $authorAvatar = $authorActor?->avatar;
                    $authorAvatarUrl = \is_array($authorAvatar) && \is_string($authorAvatar['url'] ?? null)
                        ? $authorAvatar['url']
                        : '';
                    $authorHeader = $authorActor?->header;
                    $authorHeaderUrl = \is_array($authorHeader) && \is_string($authorHeader['url'] ?? null)
                        ? $authorHeader['url']
                        : '';
                    $authorProfileUrl = $authorActor?->profileUrl
                        ?? rtrim($configuredOrigin . $configuredBasePath, '/') . '/';
                    $authorEditable = !$authorActor instanceof LocalActor || $authorActor->state->value === 'active';
                    ?>
                    <details class="activitypub-author-card"<?= $authorActor instanceof LocalActor ? '' : ' open' ?>>
                        <summary>
                            <span>
                                <strong><?= $html($authorActor?->displayName ?? $publisher->displayName) ?></strong>
                                <small><?= $authorActor instanceof LocalActor
                                    ? '@' . $html($authorActor->handle)
                                    : $trans('Federation author disabled') ?></small>
                            </span>
                            <span class="activitypub-setup-state state-<?= $html($authorActor?->state->value ?? 'draft') ?>">
                                <?= $authorActor instanceof LocalActor
                                    ? $trans('Federation actor state ' . $authorActor->state->value)
                                    : $trans('Federation author opt in') ?>
                            </span>
                        </summary>
                        <?php if (!$authorEditable): ?>
                            <div class="warning-box"><p><?= $trans('Federation retired author warning') ?></p></div>
                        <?php else: ?>
                            <form data-activitypub-form class="activitypub-author-form">
                                <input type="hidden" name="operation" value="author_save">
                                <input type="hidden" name="author_id" value="<?= $publisher->id ?>">
                                <label>
                                    <span><?= $trans('Federation setup handle') ?></span>
                                    <input type="text" name="handle" required maxlength="32"
                                           pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,31}"
                                           value="<?= $html($authorActor?->handle ?? '') ?>"
                                           placeholder="writer" autocomplete="off" spellcheck="false">
                                </label>
                                <label>
                                    <span><?= $trans('Federation setup display name') ?></span>
                                    <input type="text" name="display_name" required maxlength="255"
                                           value="<?= $html($authorActor?->displayName ?? $publisher->displayName) ?>">
                                </label>
                                <label class="activitypub-wide-field">
                                    <span><?= $trans('Federation setup summary') ?></span>
                                    <textarea name="summary_html" rows="3"><?= $html($authorActor?->summaryHtml ?? '') ?></textarea>
                                </label>
                                <label class="activitypub-wide-field">
                                    <span><?= $trans('Federation setup profile URL') ?></span>
                                    <input type="url" name="profile_url" required maxlength="2048"
                                           value="<?= $html($authorProfileUrl) ?>">
                                </label>
                                <label class="activitypub-wide-field">
                                    <span><?= $trans('Federation setup avatar URL') ?></span>
                                    <input type="url" name="avatar_url" maxlength="2048"
                                           value="<?= $html($authorAvatarUrl) ?>">
                                </label>
                                <label class="activitypub-wide-field">
                                    <span><?= $trans('Federation setup header URL') ?></span>
                                    <input type="url" name="header_url" maxlength="2048"
                                           value="<?= $html($authorHeaderUrl) ?>">
                                </label>
                                <label class="activitypub-checkbox activitypub-wide-field">
                                    <input type="checkbox" name="discoverable" value="1"
                                        <?= ($authorActor?->discoverable ?? true) ? ' checked' : '' ?>>
                                    <span><?= $trans('Federation setup discoverable') ?></span>
                                </label>
                                <p class="activitypub-author-warning activitypub-wide-field">
                                    <?= $trans('Federation author publish warning') ?>
                                </p>
                                <button type="submit" class="primary activitypub-wide-field">
                                    <?= $authorActor instanceof LocalActor
                                        ? $trans('Federation save author')
                                        : $trans('Federation enable author') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (($canManageSite || $canManageActor) && $lifecycle !== FederationLifecycleState::INSTALLED
        && $lifecycle !== FederationLifecycleState::DECOMMISSIONED): ?>
        <section class="activitypub-panel activitypub-lifecycle-panel"
                 aria-labelledby="activitypub-lifecycle-title">
            <div class="activitypub-panel-heading">
                <div>
                    <p class="activitypub-eyebrow">Identity safety</p>
                    <h2 id="activitypub-lifecycle-title"><?= $trans('Federation lifecycle controls') ?></h2>
                </div>
                <div class="activitypub-lifecycle-actions">
                    <?php if ($canManageSite && $lifecycle === FederationLifecycleState::ACTIVE): ?>
                        <button type="button" data-operation="pause"><?= $trans('Federation pause') ?></button>
                    <?php elseif ($canManageSite && $lifecycle === FederationLifecycleState::PAUSED): ?>
                        <button type="button" data-operation="resume" class="primary"><?= $trans('Federation resume') ?></button>
                    <?php endif; ?>
                    <?php if ($canManageActor && $selectedActor instanceof LocalActor
                        && \in_array($lifecycle, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED], true)): ?>
                        <button type="button" data-operation="rotate_key"
                                data-local-actor-id="<?= $selectedActor->id ?>"
                                title="<?= $html($trans('Federation rotate key help')) ?>">
                            <?= $trans('Federation rotate key') ?>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($canManageActor && $selectedActor instanceof LocalActor
                && \in_array($lifecycle, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED], true)): ?>
                <details class="activitypub-identity-change">
                    <summary><?= $trans('Federation change handle') ?></summary>
                    <p><?= $trans('Federation change handle help') ?></p>
                    <form data-activitypub-form>
                        <input type="hidden" name="operation" value="change_handle">
                        <input type="hidden" name="local_actor_id" value="<?= $selectedActor->id ?>">
                        <label>
                            <span><?= $trans('Federation new handle') ?></span>
                            <input type="text" name="new_handle" required maxlength="32"
                                   pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,31}"
                                   value="<?= $html($selectedActor->handle) ?>"
                                   autocomplete="off" spellcheck="false">
                        </label>
                        <button type="submit"><?= $trans('Federation save handle') ?></button>
                    </form>
                </details>
            <?php endif; ?>
            <?php if ($canManageSite
                && \in_array($lifecycle, [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED], true)): ?>
                <details class="activitypub-danger-zone">
                    <summary><?= $trans('Federation decommission') ?></summary>
                    <p><?= $trans('Federation decommission help') ?></p>
                    <form data-activitypub-form>
                        <input type="hidden" name="operation" value="decommission">
                        <label>
                            <span><?= $trans('Federation decommission phrase') ?></span>
                            <input type="text" name="confirmation" required autocomplete="off"
                                   pattern="DECOMMISSION" placeholder="DECOMMISSION">
                        </label>
                        <button type="submit" class="activitypub-danger-button">
                            <?= $trans('Federation start decommission') ?>
                        </button>
                    </form>
                </details>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canManageSite && \in_array(
        $lifecycle,
        [FederationLifecycleState::ACTIVE, FederationLifecycleState::PAUSED],
        true,
    )): ?>
        <section class="activitypub-panel activitypub-backfill" aria-labelledby="activitypub-backfill-title">
            <div class="activitypub-panel-heading">
                <div>
                    <p class="activitypub-eyebrow">Migration</p>
                    <h2 id="activitypub-backfill-title"><?= $trans('Federation historical projection') ?></h2>
                </div>
            </div>
            <p><?= $trans('Federation historical projection help') ?></p>
            <p class="activitypub-backfill-default"><?= $trans('Federation only new default') ?></p>
            <div class="activitypub-backfill-actions">
                <form data-activitypub-form>
                    <input type="hidden" name="operation" value="backfill_latest">
                    <label>
                        <span><?= $trans('Federation latest posts count') ?></span>
                        <input type="number" name="limit" required min="1" max="500" value="20">
                    </label>
                    <button type="submit"><?= $trans('Federation project latest posts') ?></button>
                </form>
                <form data-activitypub-form>
                    <input type="hidden" name="operation" value="backfill_selected">
                    <label>
                        <span><?= $trans('Federation selected content IDs') ?></span>
                        <textarea name="content_ids" required rows="3" maxlength="10000"
                                  placeholder="post:42, page:7"></textarea>
                    </label>
                    <button type="submit"><?= $trans('Federation project selected content') ?></button>
                </form>
            </div>
            <?php if ($backfillJobs !== []): ?>
                <div class="activitypub-backfill-history">
                    <h3><?= $trans('Federation projection history') ?></h3>
                    <ul>
                        <?php foreach ($backfillJobs as $job): ?>
                            <li>
                                <div>
                                    <strong><?= $trans('Federation backfill state ' . $job->state->value) ?></strong>
                                    <span class="technical-data"><?= $html($job->id) ?></span>
                                </div>
                                <span>
                                    <?= $job->processedCount ?>/<?= $job->totalCount ?>
                                    · <?= $trans('Federation projected count', ['{{ count }}' => $job->projectedCount]) ?>
                                    · <?= $trans('Federation skipped count', ['{{ count }}' => $job->skippedCount]) ?>
                                    <?php if ($job->failedCount > 0): ?>
                                        · <?= $trans('Federation failed count', ['{{ count }}' => $job->failedCount]) ?>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($selectedActor instanceof LocalActor): ?>
        <div class="activitypub-two-column">
            <section class="activitypub-panel" aria-labelledby="activitypub-discovery-title">
                <div class="activitypub-panel-heading">
                    <div>
                        <p class="activitypub-eyebrow">WebFinger</p>
                        <h2 id="activitypub-discovery-title"><?= $trans('Federation discover actor') ?></h2>
                    </div>
                </div>
                <form class="activitypub-discovery-form" data-activitypub-form data-discovery-form>
                    <input type="hidden" name="operation" value="discover">
                    <input type="hidden" name="local_actor_id" value="<?= $selectedActor->id ?>">
                    <label>
                        <span><?= $trans('Federation remote handle') ?></span>
                        <input type="text" name="handle" required maxlength="511"
                               inputmode="email" autocomplete="off" spellcheck="false"
                               placeholder="@writer@example.org"<?= $canManageActor ? '' : ' disabled' ?>>
                    </label>
                    <button type="submit" class="primary"<?= $canManageActor ? '' : ' disabled' ?>>
                        <?= $trans('Federation verify actor') ?>
                    </button>
                </form>
                <div class="activitypub-actor-preview" data-actor-preview hidden>
                    <p class="activitypub-preview-name" data-preview-name></p>
                    <p><a data-preview-url target="_blank" rel="noreferrer noopener"></a></p>
                    <p class="technical-data" data-preview-inbox></p>
                    <form data-activitypub-form data-follow-form>
                        <input type="hidden" name="operation" value="follow">
                        <input type="hidden" name="local_actor_id" value="<?= $selectedActor->id ?>">
                        <input type="hidden" name="remote_actor_id" value="" data-preview-actor-id>
                        <button type="submit" class="primary"><?= $trans('Federation Follow') ?></button>
                    </form>
                    <?php if ($canManageActor): ?>
                        <details class="activitypub-danger-zone">
                            <summary><?= $trans('Federation move actor') ?></summary>
                            <p><?= $trans('Federation move actor help') ?></p>
                            <form data-activitypub-form>
                                <input type="hidden" name="operation" value="move_actor">
                                <input type="hidden" name="local_actor_id" value="<?= $selectedActor->id ?>">
                                <input type="hidden" name="remote_actor_id" value="" data-preview-actor-id>
                                <label>
                                    <span><?= $trans('Federation move phrase') ?></span>
                                    <input type="text" name="confirmation" required autocomplete="off"
                                           pattern="MOVE" placeholder="MOVE">
                                </label>
                                <button type="submit" class="activitypub-danger-button">
                                    <?= $trans('Federation start move') ?>
                                </button>
                            </form>
                        </details>
                    <?php endif; ?>
                </div>
            </section>

            <section class="activitypub-panel" aria-labelledby="activitypub-following-title">
                <div class="activitypub-panel-heading">
                    <div>
                        <p class="activitypub-eyebrow">Social graph</p>
                        <h2 id="activitypub-following-title"><?= $trans('Federation following') ?></h2>
                    </div>
                    <span class="activitypub-count"><?= \count($follows) ?></span>
                </div>
                <?php if ($follows === []): ?>
                    <p class="activitypub-empty"><?= $trans('Federation no following') ?></p>
                <?php else: ?>
                    <ul class="activitypub-follow-list">
                        <?php foreach ($follows as $follow): ?>
                            <li>
                                <div>
                                    <a href="<?= $html((string)$follow['actor_url']) ?>" target="_blank" rel="noreferrer noopener">
                                        <strong><?= $html((string)$follow['display_name']) ?></strong>
                                        <span>@<?= $html((string)$follow['preferred_username']) ?></span>
                                    </a>
                                    <small>
                                        <?= $html($actorNames[(int)$follow['local_actor_id']] ?? '#' . (string)$follow['local_actor_id']) ?>
                                        · <?= $trans('Federation follow state ' . (string)$follow['state']) ?>
                                    </small>
                                </div>
                                <?php if ($canManageActor): ?>
                                    <button type="button" data-operation="unfollow"
                                            data-local-actor-id="<?= (int)$follow['local_actor_id'] ?>"
                                            data-remote-actor-id="<?= (int)$follow['remote_actor_id'] ?>">
                                        <?= $trans('Federation Unfollow') ?>
                                    </button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>

        <section class="activitypub-panel activitypub-reader" aria-labelledby="activitypub-reader-title">
            <div class="activitypub-panel-heading activitypub-reader-heading">
                <div>
                    <p class="activitypub-eyebrow">Inbox reader</p>
                    <h2 id="activitypub-reader-title"><?= $trans('Federation reader') ?></h2>
                </div>
                <span class="activitypub-count"><?= $readerCount ?></span>
            </div>
            <nav class="activitypub-feed-tabs">
                <a href="<?= $readerUrl($selectedActor, 'feed') ?>"
                   <?= $readerView === 'feed' ? 'aria-current="page"' : '' ?>>
                    <?= $trans('Federation public feed') ?>
                </a>
                <a href="<?= $readerUrl($selectedActor, 'direct') ?>"
                   <?= $readerView === 'direct' ? 'aria-current="page"' : '' ?>>
                    <?= $trans('Federation direct feed') ?>
                </a>
            </nav>
            <?php if ($readerView === 'direct'): ?>
                <p class="activitypub-direct-warning"><?= $trans('Federation direct warning') ?></p>
            <?php endif; ?>
            <?php if ($cursorError): ?>
                <div class="error-box"><p><?= $trans('Federation invalid cursor') ?></p></div>
            <?php elseif ($readerEntries === []): ?>
                <p class="activitypub-empty"><?= $trans('Federation empty feed') ?></p>
            <?php else: ?>
                <div class="activitypub-feed">
                    <?php foreach ($readerEntries as $entry):
                        $documentName = $scalar($entry->document['name'] ?? '');
                        $documentSummary = $scalar($entry->document['summary'] ?? '');
                        $documentContent = $scalar($entry->document['content'] ?? '');
                        $replyVisibility = \in_array($entry->visibility, ['followers', 'direct'], true)
                            ? 'direct'
                            : 'public';
                        ?>
                        <article class="activitypub-entry visibility-<?= $html($entry->visibility) ?>"
                                 id="activitypub-object-<?= $entry->objectId ?>">
                            <header>
                                <div class="activitypub-entry-avatar" aria-hidden="true">
                                    <?= $html(mb_strtoupper(mb_substr($entry->displayName !== ''
                                        ? $entry->displayName
                                        : $entry->preferredUsername, 0, 1))) ?>
                                </div>
                                <div class="activitypub-entry-author">
                                    <a href="<?= $html($entry->actorUrl) ?>" target="_blank" rel="noreferrer noopener">
                                        <strong><?= $html($entry->displayName !== '' ? $entry->displayName : $entry->preferredUsername) ?></strong>
                                        <span>@<?= $html($entry->preferredUsername) ?></span>
                                    </a>
                                    <small>
                                        <?= $trans('Federation received at', ['{{ time }}' => date('Y-m-d H:i', $entry->sortAt)]) ?>
                                        · <?= $trans('Federation visibility ' . $entry->visibility) ?>
                                    </small>
                                </div>
                                <a class="activitypub-original" href="<?= $html($entry->objectUrl) ?>"
                                   target="_blank" rel="noreferrer noopener">
                                    <?= $trans('Federation original') ?> ↗
                                </a>
                            </header>
                            <?php if ($entry->inReplyToUrl !== null): ?>
                                <p class="activitypub-in-reply-to">
                                    <?= $trans('Federation in reply to') ?>:
                                    <a href="<?= $html($entry->inReplyToUrl) ?>" target="_blank" rel="noreferrer noopener">
                                        <?= $html($entry->inReplyToUrl) ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                            <?php if ($documentName !== ''): ?>
                                <h3><?= $html($documentName) ?></h3>
                            <?php endif; ?>
                            <?php if ($documentSummary !== ''): ?>
                                <details class="activitypub-content-warning">
                                    <summary><?= $html(strip_tags($documentSummary)) ?></summary>
                                    <div class="activitypub-entry-content"><?= $documentContent ?></div>
                                </details>
                            <?php else: ?>
                                <!-- content is an ingestion-time allowlist-sanitized snapshot -->
                                <div class="activitypub-entry-content"><?= $documentContent ?></div>
                            <?php endif; ?>

                            <?php foreach ($localNotes[$entry->objectUrl] ?? [] as $localNote): ?>
                                <aside class="activitypub-local-note">
                                    <div>
                                        <strong><?= $trans('Federation your reply') ?></strong>
                                        <span><?= date('Y-m-d H:i', $localNote->updatedAt) ?></span>
                                        <span><?= $trans('Federation visibility ' . $localNote->visibility) ?></span>
                                    </div>
                                    <p><?= nl2br($html($localNoteText($localNote->snapshotJson)), false) ?></p>
                                    <?php if ($canManageActor): ?>
                                        <form data-activitypub-form class="activitypub-local-note-form">
                                            <input type="hidden" name="operation" value="reply_update">
                                            <input type="hidden" name="local_actor_id" value="<?= $selectedActor->id ?>">
                                            <input type="hidden" name="local_note_id" value="<?= $localNote->id ?>">
                                            <label>
                                                <span><?= $trans('Federation edit reply') ?></span>
                                                <textarea name="text" required maxlength="10000" rows="3"><?= $html($localNoteText($localNote->snapshotJson)) ?></textarea>
                                            </label>
                                            <div>
                                                <button type="submit"><?= $trans('Federation save reply') ?></button>
                                                <button type="submit" name="operation" value="reply_delete"
                                                        data-confirm="<?= $html($trans('Federation delete reply confirmation')) ?>"
                                                        class="danger">
                                                    <?= $trans('Federation delete reply') ?>
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </aside>
                            <?php endforeach; ?>

                            <?php if ($canManageActor): ?>
                                <div class="activitypub-entry-actions">
                                    <button type="button" data-operation="like"
                                            data-local-actor-id="<?= $selectedActor->id ?>"
                                            data-remote-object-id="<?= $entry->objectId ?>">
                                        <?= $trans('Federation Like') ?>
                                    </button>
                                    <button type="button" data-operation="unlike"
                                            data-local-actor-id="<?= $selectedActor->id ?>"
                                            data-remote-object-id="<?= $entry->objectId ?>">
                                        <?= $trans('Federation Undo Like') ?>
                                    </button>
                                    <button type="button" data-operation="announce"
                                            data-local-actor-id="<?= $selectedActor->id ?>"
                                            data-remote-object-id="<?= $entry->objectId ?>">
                                        <?= $trans('Federation Announce') ?>
                                    </button>
                                    <button type="button" data-operation="unannounce"
                                            data-local-actor-id="<?= $selectedActor->id ?>"
                                            data-remote-object-id="<?= $entry->objectId ?>">
                                        <?= $trans('Federation Undo Announce') ?>
                                    </button>
                                </div>
                                <div class="activitypub-entry-tools">
                                    <form data-activitypub-form class="activitypub-emoji-form">
                                        <input type="hidden" name="operation" value="emoji">
                                        <input type="hidden" name="local_actor_id" value="<?= $selectedActor->id ?>">
                                        <input type="hidden" name="remote_object_id" value="<?= $entry->objectId ?>">
                                        <label>
                                            <span><?= $trans('Federation EmojiReact') ?></span>
                                            <input type="text" name="emoji" required maxlength="64" size="5" placeholder="✨">
                                        </label>
                                        <button type="submit"><?= $trans('Federation EmojiReact') ?></button>
                                        <button type="submit" name="operation" value="unemoji"><?= $trans('Federation Undo EmojiReact') ?></button>
                                    </form>
                                    <form data-activitypub-form class="activitypub-reply-form">
                                        <input type="hidden" name="operation" value="reply">
                                        <input type="hidden" name="local_actor_id" value="<?= $selectedActor->id ?>">
                                        <input type="hidden" name="remote_object_id" value="<?= $entry->objectId ?>">
                                        <label>
                                            <span><?= $trans('Federation reply') ?></span>
                                            <textarea name="text" required maxlength="10000" rows="3"
                                                      placeholder="<?= $html($trans('Federation reply placeholder')) ?>"></textarea>
                                        </label>
                                        <div>
                                            <select name="visibility">
                                                <?php if ($replyVisibility !== 'direct'): ?>
                                                    <option value="public"><?= $trans('Federation visibility public') ?></option>
                                                    <option value="unlisted"><?= $trans('Federation visibility unlisted') ?></option>
                                                <?php endif; ?>
                                                <option value="direct"<?= $replyVisibility === 'direct' ? ' selected' : '' ?>>
                                                    <?= $trans('Federation visibility direct') ?>
                                                </option>
                                            </select>
                                            <button type="submit" class="primary"><?= $trans('Federation send reply') ?></button>
                                        </div>
                                    </form>
                                    <?php if ($canManageSite): ?>
                                        <form data-activitypub-form class="activitypub-moderation-form">
                                            <input type="hidden" name="operation" value="moderate">
                                            <input type="hidden" name="remote_actor_id" value="<?= $entry->remoteActorId ?>">
                                            <label>
                                                <span><?= $trans('Federation moderation') ?></span>
                                                <select name="moderation_action">
                                                    <?php foreach (['moderate', 'trust', 'silence', 'block'] as $action): ?>
                                                        <option value="<?= $action ?>"><?= $trans('Federation moderation ' . $action) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>
                                            <button type="submit"><?= $trans('Federation save moderation') ?></button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php if ($nextCursor !== null): ?>
                    <p class="activitypub-load-older">
                        <a href="<?= $readerUrl($selectedActor, $readerView, $nextCursor) ?>">
                            <?= $trans('Federation older entries') ?>
                        </a>
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($canManageSite
        && $identityHealth instanceof IdentityHealthReport
        && $queueStatus !== null
        && $runnerStatus !== null): ?>
        <section class="activitypub-panel activitypub-diagnostics" id="activitypub-diagnostics"
                 aria-labelledby="activitypub-diagnostics-title">
        <div class="activitypub-panel-heading">
            <div>
                <p class="activitypub-eyebrow">Operations</p>
                <h2 id="activitypub-diagnostics-title"><?= $trans('Federation dashboard') ?></h2>
            </div>
            <button type="button" data-operation="push_queue" class="primary">
                <?= $trans('Federation push queue') ?>
            </button>
        </div>
        <div class="activitypub-metrics">
            <?php foreach ([
                'inbox'              => 'Federation inbox',
                'inbox_failed'       => 'Federation failed inbox',
                'deliveries_ready'   => 'Federation ready deliveries',
                'deliveries_delayed' => 'Federation delayed deliveries',
                'deliveries_failed'  => 'Federation failed deliveries',
                'followers'          => 'Federation followers',
                'following'          => 'Federation following',
                'remote_objects'     => 'Federation remote objects',
                'direct_notes'       => 'Federation direct notes',
                'notifications'      => 'Federation notifications',
                'avatars_ready'      => 'Federation cached avatars',
                'avatars_pending'    => 'Federation pending avatars',
                'avatars_failed'     => 'Federation failed avatars',
                'moderation_pending' => 'Federation pending moderation',
                'moderation_flags'   => 'Federation active flags',
                'moderation_rules'   => 'Federation moderation rules',
                'remote_cache_bytes' => 'Federation remote cache size',
            ] as $metric => $label): ?>
                <div class="<?= str_contains($metric, 'failed') && $summary[$metric] > 0 ? 'is-error' : '' ?>">
                    <strong><?= $metric === 'remote_cache_bytes'
                            ? $formatBytes($summary[$metric])
                            : $summary[$metric] ?></strong>
                    <span><?= $trans($label) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="activitypub-queue-state">
            <strong><?= $trans('Federation shared queue') ?></strong>
            <span><?= $trans('Federation queue ready', ['{{ count }}' => $queueStatus['ready']]) ?></span>
            <span><?= $trans('Federation queue delayed', ['{{ count }}' => $queueStatus['delayed']]) ?></span>
            <span><?= $trans('Federation queue failed', ['{{ count }}' => $queueStatus['failed']]) ?></span>
            <span><?= $queueStatus['runner_active']
                    ? $trans('Federation queue runner active')
                    : $trans('Federation queue runner idle') ?></span>
            <?php if ($queueStatus['oldest_ready_age'] !== null): ?>
                <span><?= $trans('Federation oldest queue item', ['{{ age }}' => $queueStatus['oldest_ready_age']]) ?></span>
            <?php endif; ?>
        </div>
        <div class="activitypub-runner-state <?= $runnerStatus['last_runner_at'] === null ? 'is-idle' : '' ?>">
            <strong><?= $trans('Federation last shutdown runner') ?></strong>
            <?php if ($runnerStatus['last_runner_at'] === null): ?>
                <span><?= $trans('Federation shutdown runner never') ?></span>
            <?php else: ?>
                <span><?= $trans('Federation shutdown runner at', [
                    '{{ time }}' => date('Y-m-d H:i:s', $runnerStatus['last_runner_at']),
                ]) ?></span>
                <code><?= $html($runnerStatus['last_runner_code']) ?></code>
            <?php endif; ?>
        </div>
        <div class="activitypub-identity-health <?= $identityHealth->isHealthy() ? 'is-healthy' : 'is-error' ?>">
            <div>
                <strong><?= $trans('Federation identity health') ?></strong>
                <span><?= $identityHealth->isHealthy()
                        ? $trans('Federation identity healthy')
                        : $trans('Federation identity broken') ?></span>
            </div>
            <div class="technical-data">
                <span><?= $trans('Federation identity actors', ['{{ count }}' => $identityHealth->actorCount]) ?></span>
                <span><?= $trans('Federation identity keys', ['{{ count }}' => $identityHealth->keyCount]) ?></span>
                <span><?= $trans('Federation identity fingerprint', [
                    '{{ fingerprint }}' => substr($identityHealth->identityFingerprint, 0, 16),
                ]) ?></span>
            </div>
            <?php if (!$identityHealth->isHealthy()): ?>
                <ul>
                    <?php foreach ($identityHealth->errors as $identityError): ?>
                        <li><?= $html($identityError) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <div class="activitypub-operations-grid">
            <section aria-labelledby="activitypub-domain-failures-title">
                <h3 id="activitypub-domain-failures-title"><?= $trans('Federation failures by domain') ?></h3>
                <?php if ($failureDomains === []): ?>
                    <p class="activitypub-empty"><?= $trans('Federation no domain failures') ?></p>
                <?php else: ?>
                    <ul class="activitypub-operations-list">
                        <?php foreach ($failureDomains as $failure): ?>
                            <li>
                                <div>
                                    <strong><?= $html($failure['origin'] !== ''
                                            ? $failure['origin']
                                            : $trans('Federation unknown origin')) ?></strong>
                                    <small><?= $trans('Federation failure direction ' . $failure['direction']) ?></small>
                                </div>
                                <span>
                                    <?= $failure['failure_count'] ?>
                                    · <?= date('Y-m-d H:i', $failure['last_failure_at']) ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <section aria-labelledby="activitypub-rules-title">
                <h3 id="activitypub-rules-title"><?= $trans('Federation active moderation rules') ?></h3>
                <?php if ($moderationRules === []): ?>
                    <p class="activitypub-empty"><?= $trans('Federation no moderation rules') ?></p>
                <?php else: ?>
                    <ul class="activitypub-operations-list">
                        <?php foreach ($moderationRules as $rule): ?>
                            <li>
                                <div>
                                    <strong><?= $html($rule['match_value']) ?></strong>
                                    <small><?= $trans('Federation moderation scope ' . $rule['scope']) ?></small>
                                </div>
                                <span><?= $trans('Federation moderation ' . $rule['action']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
        </section>
    <?php endif; ?>
</section>
