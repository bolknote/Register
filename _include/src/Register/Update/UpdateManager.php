<?php
/**
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Update;

use Psr\Log\LoggerInterface;
use Register\Backup\BackupManager;
use Register\Schema\SchemaManager;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Model\ExtensionCache;

final readonly class UpdateManager
{
    public function __construct(
        private UpdateStorage         $storage,
        private ReleaseArchiveExtractor $extractor,
        private UpdatePlanner         $planner,
        private UpdateApplier         $applier,
        private BackupManager         $backupManager,
        private SchemaManager         $schemaManager,
        private ExtensionCache        $extensionCache,
        private DynamicConfigProvider $dynamicConfigProvider,
        private GeneratedAssetCacheCleaner $generatedAssetCacheCleaner,
        private MaintenanceMode       $maintenanceMode,
        private LoggerInterface       $logger,
        private string                $applicationRoot,
        private string                $publicRoot,
    ) {
    }

    /** @return array<string, mixed> */
    public function start(string $filename, int $size): array
    {
        if (!$this->installedManifest() instanceof ReleaseManifest) {
            throw new \RuntimeException('This installation has no release manifest and cannot self-update yet.');
        }

        if ($this->maintenanceMode->active()) {
            throw new \RuntimeException('Finish the interrupted update before uploading another release.');
        }

        return $this->storage->start($filename, $size);
    }

    /** @return array<string, mixed> */
    public function append(string $id, int $offset, string $chunkPath): array
    {
        return $this->storage->append($id, $offset, $chunkPath);
    }

    /** @return array<string, mixed> */
    public function prepare(string $id): array
    {
        return $this->storage->exclusive($id, function (array $state) use ($id): array {
            if (($state['status'] ?? null) !== 'uploaded') {
                throw new \RuntimeException('The release archive has not finished uploading.');
            }

            $installed = $this->installedManifest();
            if (!$installed instanceof ReleaseManifest) {
                throw new \RuntimeException('The installed release manifest is missing or invalid.');
            }

            $archive = $this->storage->archivePath($id);
            $incoming = $this->extractor->manifest($archive);
            $this->storage->resetStage($id);
            $freeBytes = disk_free_space(dirname($archive));
            $requiredBytes = $incoming->totalBytes() + 32 * 1024 * 1024;
            if (\is_float($freeBytes) && $freeBytes < $requiredBytes) {
                throw new \RuntimeException('There is not enough free disk space to stage this release.');
            }

            $this->extractor->extract($archive, $this->storage->stageRoot($id), $incoming);
            $plan = $this->planner->plan($installed, $incoming, $this->schemaManager->currentGeneration());

            $state['release_id']  = $incoming->releaseId;
            $state['version']     = $incoming->version;
            $state['built_at']    = $incoming->builtAt;
            $state['schema_from'] = $incoming->schemaFrom;
            $state['schema_to']   = $incoming->schemaTo;
            $state['plan']        = $plan->toArray();
            $state['status']      = $plan->canApply() ? 'ready' : 'blocked';
            unset($state['message']);
            $this->storage->save($id, $state);

            return $this->storage->publicState($state);
        });
    }

    /** @return array<string, mixed> */
    public function apply(string $id): array
    {
        return $this->storage->exclusive($id, function (array $state) use ($id): array {
            if (!\in_array($state['status'] ?? null, ['ready', 'backing_up'], true)) {
                throw new \RuntimeException('This release is not ready to apply.');
            }

            $installed = $this->installedManifest();
            if (!$installed instanceof ReleaseManifest) {
                throw new \RuntimeException('The installed release manifest is missing or invalid.');
            }

            $incoming = ReleaseManifest::fromFile($this->storage->stageRoot($id) . '/app/register-release.json');
            if (($state['release_id'] ?? null) !== $incoming->releaseId) {
                throw new \RuntimeException('The staged release does not match the update session.');
            }

            $plan = $this->planner->plan($installed, $incoming, $this->schemaManager->currentGeneration());
            if (!$plan->canApply()) {
                $state['status']  = 'blocked';
                $state['plan']    = $plan->toArray();
                $state['message'] = 'Installed files changed after the release was checked.';
                $this->storage->save($id, $state);

                return $this->storage->publicState($state);
            }

            $state['status'] = 'backing_up';
            unset($state['message']);
            $this->storage->save($id, $state);
            try {
                $backup = $this->backupManager->createNow();
                $state['backup'] = $backup->name;
                $this->storage->save($id, $state);
            } catch (\Throwable $throwable) {
                $state['status']  = 'ready';
                $state['message'] = 'The pre-update backup failed: ' . $throwable->getMessage();
                $this->storage->save($id, $state);
                throw $throwable;
            }

            $enteredMaintenance = false;
            $filesSwitched      = false;
            try {
                $this->maintenanceMode->enter($incoming->releaseId, $id);
                $enteredMaintenance = true;
                $runtimeLock = RuntimeLock::acquireExclusive($this->applicationRoot);
                $state['status'] = 'applying_files';
                $this->storage->save($id, $state);
                $this->applier->apply(
                    $this->storage->stageRoot($id),
                    $this->storage->rollbackRoot($id),
                    $installed,
                    $incoming,
                    $plan,
                );
                $filesSwitched = true;
                $runtimeLock->release();

                $state['status'] = 'files_switched';
                $state['plan']   = $plan->toArray();
                unset($state['message']);
                $this->storage->save($id, $state);
                $this->logger->info('Register release files switched.', [
                    'release_id' => $incoming->releaseId,
                    'version'    => $incoming->version,
                ]);

                return $this->storage->publicState($state);
            } catch (\Throwable $throwable) {
                if ($filesSwitched) {
                    $state['status']  = 'files_switched';
                    $state['message'] = 'The files were switched, but the update response could not be finalized. Retry finalization.';
                    try {
                        $this->storage->save($id, $state);
                    } catch (\Throwable $stateFailure) {
                        $this->logger->error('Unable to persist the switched update state.', [
                            'exception' => $stateFailure,
                        ]);
                    }

                    throw $throwable;
                }

                $rollbackPending = is_dir($this->storage->rollbackRoot($id))
                    || is_link($this->storage->rollbackRoot($id));
                if ($enteredMaintenance && !$rollbackPending) {
                    try {
                        $this->maintenanceMode->leave($id);
                    } catch (\Throwable $maintenanceFailure) {
                        $this->logger->error('Unable to leave maintenance mode after a failed update.', [
                            'exception' => $maintenanceFailure,
                        ]);
                    }
                }

                $state['status'] = $rollbackPending ? 'rollback_failed' : 'ready';
                $state['message'] = $rollbackPending
                    ? 'The file switch and its automatic rollback failed; retry recovery while maintenance remains active: '
                        . $throwable->getMessage()
                    : 'The file switch failed and was rolled back: ' . $throwable->getMessage();
                $this->storage->save($id, $state);
                throw $throwable;
            }
        });
    }

    /** @return array<string, mixed> */
    public function finish(string $id): array
    {
        return $this->storage->exclusive($id, function (array $state) use ($id): array {
            if (($state['status'] ?? null) === 'complete') {
                $this->maintenanceMode->leave($id);

                return $this->storage->publicState($state);
            }

            if (!\in_array(
                $state['status'] ?? null,
                [
                    'applying_files', 'rollback_failed', 'files_switched',
                    'migrating', 'opening_site', 'migration_failed',
                ],
                true,
            )) {
                throw new \RuntimeException('The release files have not been switched.');
            }

            $incoming = ReleaseManifest::fromFile($this->storage->stageRoot($id) . '/app/register-release.json');
            $installed = $this->installedManifest();
            $installedReleaseId = $installed instanceof ReleaseManifest ? $installed->releaseId : null;
            if ($installedReleaseId !== $incoming->releaseId) {
                if (\in_array($state['status'], ['applying_files', 'rollback_failed'], true)) {
                    $this->applier->rollbackInterrupted($this->storage->rollbackRoot($id));
                    $this->maintenanceMode->leave($id);
                    $state['status']  = 'ready';
                    $state['message'] = 'An interrupted file switch was rolled back. Start the installation again.';
                    $this->storage->save($id, $state);
                }

                throw new \RuntimeException('The running files do not match the staged release.');
            }

            $state['status'] = 'migrating';
            unset($state['message']);
            $this->storage->save($id, $state);
            try {
                $this->schemaManager->migrateTo($incoming->schemaTo);
                $this->extensionCache->clear();
                $this->dynamicConfigProvider->regenerate();
                $this->verifyInstalledFiles($incoming);
                $this->generatedAssetCacheCleaner->clear();
                if (\function_exists('opcache_reset')) {
                    opcache_reset();
                }
            } catch (\Throwable $throwable) {
                $state['status']  = 'migration_failed';
                $state['message'] = 'Finalization failed; maintenance mode remains active: ' . $throwable->getMessage();
                $this->storage->save($id, $state);
                $this->logger->error('Register update finalization failed.', [
                    'release_id' => $incoming->releaseId,
                    'exception'  => $throwable,
                ]);
                throw $throwable;
            }

            $state['status'] = 'opening_site';
            $this->storage->save($id, $state);
            try {
                $this->maintenanceMode->leave($id);
            } catch (\Throwable $throwable) {
                $state['status']  = 'migration_failed';
                $state['message'] = 'Finalization succeeded, but maintenance mode could not be removed: '
                    . $throwable->getMessage();
                $this->storage->save($id, $state);
                $this->logger->error('Register could not leave maintenance mode after an update.', [
                    'release_id' => $incoming->releaseId,
                    'exception'  => $throwable,
                ]);
                throw $throwable;
            }

            $state['status'] = 'complete';
            $this->storage->save($id, $state);
            try {
                $this->storage->cleanupCompleted($id);
            } catch (\Throwable $cleanupFailure) {
                $this->logger->warning('Unable to clean up completed update files.', [
                    'release_id' => $incoming->releaseId,
                    'exception'  => $cleanupFailure,
                ]);
            }

            $this->logger->info('Register update completed.', [
                'release_id' => $incoming->releaseId,
                'version'    => $incoming->version,
            ]);

            return $this->storage->publicState($state);
        });
    }

    /** @return array<string, mixed> */
    public function status(string $id): array
    {
        return $this->storage->publicState($this->storage->load($id));
    }

    /** @return array<string, mixed>|null */
    public function recoverable(): ?array
    {
        return $this->storage->latestRecoverable();
    }

    public function installedManifest(): ?ReleaseManifest
    {
        return BuildInfo::manifest($this->applicationRoot);
    }

    private function verifyInstalledFiles(ReleaseManifest $manifest): void
    {
        foreach ($manifest->files as $file) {
            $root = $file->target === ReleaseFile::TARGET_APPLICATION
                ? rtrim($this->applicationRoot, '/\\')
                : rtrim($this->publicRoot, '/\\');
            $filename = $root . '/' . $file->path;
            if (!is_file($filename) || is_link($filename) || filesize($filename) !== $file->size) {
                throw new \RuntimeException('An installed release file is missing or changed: ' . $file->key());
            }

            $hash = hash_file('sha256', $filename);
            if (!\is_string($hash) || !hash_equals($file->sha256, $hash)) {
                throw new \RuntimeException('An installed release file failed verification: ' . $file->key());
            }

            if (DIRECTORY_SEPARATOR !== '\\') {
                $permissions = fileperms($filename);
                if (!\is_int($permissions)) {
                    throw new \RuntimeException('Unable to inspect an installed file mode: ' . $file->key());
                }

                $mode = ($permissions & 0111) !== 0 ? 0755 : 0644;
                if ($mode !== $file->mode) {
                    throw new \RuntimeException('An installed release file has the wrong mode: ' . $file->key());
                }
            }
        }
    }
}
