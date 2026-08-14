<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Extensions;

use Register\Module\BaseModuleRegistry;
use S2\AdminYard\Translator;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Framework\Container;
use S2\Cms\Model\ExtensionCache;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerException;

readonly class ExtensionManager
{
    public function __construct(
        private DbLayer               $dbLayer,
        private ExtensionCache        $extensionCache,
        private DynamicConfigProvider $dynamicConfigProvider,
        private Translator            $translator,
        private Container             $container, // Note: do not use here as a service locator
        private string                $rootDir,
        private BaseModuleRegistry     $baseModuleRegistry,
    ) {
    }

    /**
     * @throws DbLayerException
     * @return array{
     *     extensionNum: int,
     *     baseModules: list<array<string, string>>,
     *     availableExtensions: list<array<string, mixed>>,
     *     failedExtensions: list<array<string, string>>,
     *     installedExtensions: array<array-key, array<mixed>>
     * }
     */
    public function getExtensionList(): array
    {
        $installedExtensions = [];
        $result              = $this->dbLayer
            ->select('e.*')
            ->from('extensions AS e')
            ->orderBy('e.title')
            ->execute()
        ;
        while ($currentExtension = $result->fetchAssoc()) {
            $installedExtensions[$currentExtension['id']] = $currentExtension;
        }

        $baseModules = $this->getBaseModules();
        foreach ($baseModules as $baseModule) {
            unset($installedExtensions[$baseModule['id']]);
        }

        $extensionNum        = 0;
        $availableExtensions = [];
        $failedExtensions    = [];

        $d = dir($this->rootDir . '_extensions');
        if ($d === false) {
            throw new \RuntimeException('Unable to open the extensions directory.');
        }

        while (($entry = $d->read()) !== false) {
            if ($entry[0] === '.' || !is_dir($this->rootDir . '_extensions/' . $entry)) {
                continue;
            }

            if ($this->baseModuleRegistry->contains($entry)) {
                continue;
            }

            if (preg_match('/[^0-9a-z_]/', $entry) === 1) {
                $failedExtensions[] = [
                    'entry'   => $entry,
                    'error'   => $this->translator->trans('Extension loading error', ['{{ extension }}' => $entry]),
                    'message' => $this->translator->trans('Illegal ID')
                ];
                continue;
            }

            if (!file_exists($this->rootDir . '_extensions/' . $entry . '/Manifest.php')) {
                $failedExtensions[] = [
                    'entry'   => $entry,
                    'error'   => $this->translator->trans('Extension loading error', ['{{ extension }}' => $entry]),
                    'message' => $this->translator->trans('Missing manifest')
                ];
                continue;
            }

            $extensionClass = '\\s2_extensions\\' . $entry . '\\Manifest';
            if (!class_exists($extensionClass)) {
                $failedExtensions[] = [
                    'entry'   => $entry,
                    'error'   => $this->translator->trans('Extension loading error', ['{{ extension }}' => $entry]),
                    'message' => $this->translator->trans('Manifest class not found')
                ];
                continue;
            }

            $extensionManifest = new $extensionClass();
            if (!$extensionManifest instanceof ManifestInterface) {
                $failedExtensions[] = [
                    'entry'   => $entry,
                    'error'   => $this->translator->trans('Extension loading error', ['{{ extension }}' => $entry]),
                    'message' => $this->translator->trans('ManifestInterface is not implemented')
                ];
                continue;
            }

            if (!\array_key_exists($entry, $installedExtensions) || version_compare($installedExtensions[$entry]['version'], $extensionManifest->getVersion(), '!=')) {
                $installationNotes = [];
                $installationNote = $extensionManifest->getInstallationNote();
                if ($installationNote !== null && $installationNote !== '') {
                    $installationNotes[] = $installationNote;
                }

                $availableExtensions[] = [
                    'entry'             => $entry,
                    'title'             => $extensionManifest->getTitle(),
                    'version'           => $extensionManifest->getVersion(),
                    'author'            => $extensionManifest->getAuthor(),
                    'description'       => $extensionManifest->getDescription(),
                    'install_notes'     => $installationNotes,
                    'installed_version' => $installedExtensions[$entry]['version'] ?? null,
                ];
                ++$extensionNum;
            }
        }

        $d->close();

        return [
            'extensionNum'        => $extensionNum,
            'baseModules'         => $baseModules,
            'availableExtensions' => $availableExtensions,
            'failedExtensions'    => $failedExtensions,
            'installedExtensions' => $installedExtensions,
        ];
    }

    /** @return list<array{id: string, title: string, version: string, author: string, description: string}> */
    public function getBaseModules(): array
    {
        $baseModules = [];
        foreach ($this->baseModuleRegistry->ids() as $id) {
            $manifestClass = $this->baseModuleRegistry->manifestClass($id);
            $manifest      = new $manifestClass();
            $baseModules[] = [
                'id'          => $id,
                'title'       => $manifest->getTitle(),
                'version'     => $manifest->getVersion(),
                'author'      => $manifest->getAuthor(),
                'description' => $manifest->getDescription(),
            ];
        }

        return $baseModules;
    }

    /**
     * @throws DbLayerException
     */
    public function getUpgradableExtensionNum(): int
    {
        $result = $this->dbLayer
            ->select('e.*')
            ->from('extensions AS e')
            ->execute()
        ;

        $extensionNum = 0;
        while ($currentExtension = $result->fetchAssoc()) {
            if ($this->baseModuleRegistry->contains($currentExtension['id'])) {
                continue;
            }

            $manifestClass = '\\s2_extensions\\' . $currentExtension['id'] . '\\Manifest';
            if (!class_exists($manifestClass)) {
                continue;
            }

            $extensionManifest = new $manifestClass;
            if (!$extensionManifest instanceof ManifestInterface) {
                continue;
            }

            if (version_compare($currentExtension['version'], $extensionManifest->getVersion(), '<')) {
                ++$extensionNum;
            }
        }

        return $extensionNum;
    }

    /**
     * @throws DbLayerException
     */
    public function flipExtension(string $id): ?string
    {
        if ($this->baseModuleRegistry->contains($id)) {
            return $this->baseModuleLifecycleError($id);
        }

        // Fetch the current status of the extension
        $row = $this->fetchExtension($id);
        if ($row === null) {
            return $this->translator->trans('Extension loading error', ['{{ extension }}' => $id]);
        }

        // Are we disabling or enabling?
        $disable = $row['disabled'] === 0;

        // Check dependencies
        if ($disable) {
            $result = $this->dbLayer->select('e.id')
                ->from('extensions AS e')
                ->where('e.disabled = 0') // enabled!
                ->andWhere('e.dependencies LIKE :id')
                ->setParameter('id', '%|' . $id . '|%')
                ->execute()
            ;

            $dependencyIds = $result->fetchColumn();

            if ($dependencyIds !== []) {
                return $this->translator->trans('Disable dependency', [
                    '{{ extension }}'    => $id,
                    '{{ dependencies }}' => implode(', ', $dependencyIds),
                ]);
            }
        } else {
            $result = $this->dbLayer->select('e.dependencies')
                ->from('extensions AS e')
                ->where('e.id = :id')->setParameter('id', $id)
                ->execute()
            ;

            $dependencies = $result->result();
            $dependencies = explode('|', substr($dependencies, 1, -1));
            $dependencies = array_filter($dependencies, static fn(string $dependency): bool => $dependency !== '');

            $enabledExtensions = $this->getEnabledExtensionIds();

            $brokenDependencies = array_diff($dependencies, $enabledExtensions);

            if (\count($brokenDependencies) > 0) {
                return $this->translator->trans('Disabled dependency', [
                    '{{ extension }}'    => $id,
                    '{{ dependencies }}' => implode(', ', $brokenDependencies),
                ]);
            }
        }

        $this->dbLayer
            ->update('extensions')
            ->set('disabled', ':disabled')->setParameter('disabled', $disable ? 1 : 0)
            ->where('id = :id')->setParameter('id', $id)
            ->execute()
        ;

        // Regenerate the extension cache
        $this->extensionCache->clear();

        return null;
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function installExtension(string $id): array
    {
        if ($this->baseModuleRegistry->contains($id)) {
            return [$this->baseModuleLifecycleError($id)];
        }

        if (!file_exists($this->rootDir . '_extensions/' . $id . '/Manifest.php')) {
            return [
                $this->translator->trans('Extension loading error', ['{{ extension }}' => $id]),
                $this->translator->trans('Missing manifest'),
            ];
        }

        $extensionClass = '\\s2_extensions\\' . $id . '\\Manifest';
        if (!class_exists($extensionClass)) {
            return [
                $this->translator->trans('Extension loading error', ['{{ extension }}' => $id]),
                $this->translator->trans('Manifest class not found'),
            ];
        }

        $extensionManifest = new $extensionClass();
        if (!$extensionManifest instanceof ManifestInterface) {
            return [
                $this->translator->trans('Extension loading error', ['{{ extension }}' => $id]),
                $this->translator->trans('ManifestInterface is not implemented'),
            ];
        }

        $missingDependencies = array_diff($extensionManifest->getDependencies(), $this->getEnabledExtensionIds());

        if ($missingDependencies !== []) {
            return [
                $this->translator->trans('Missing dependency', [
                    '{{ extension }}'    => $id,
                    '{{ dependencies }}' => implode(', ', $missingDependencies),
                ]),
            ];
        }

        // Is this a fresh install or an upgrade?
        $currentExtension = $this->fetchExtension($id);

        if ($currentExtension !== null) {
            // Run the author supplied installation code
            $extensionManifest->install($this->dbLayer, $this->container, $currentExtension['version']);

            // Update the existing extension
            $qb = $this->dbLayer
                ->update('extensions')
                ->set('title', ':title')
                ->set('version', ':version')
                ->set('description', ':description')
                ->set('author', ':author')
                ->set('uninstall_note', ':uninstall_note')
                ->set('dependencies', ':dependencies')
                ->where('id = :id')
            ;
        } else {
            // Run the author supplied installation code
            $extensionManifest->install($this->dbLayer, $this->container, null);

            // Add the new extension
            $qb = $this->dbLayer
                ->insert('extensions')
                ->values([
                    'id'             => ':id',
                    'title'          => ':title',
                    'version'        => ':version',
                    'description'    => ':description',
                    'author'         => ':author',
                    'uninstall_note' => ':uninstall_note',
                    'dependencies'   => ':dependencies',
                ])
            ;
        }

        $qb->execute([
            'id'             => $id,
            'title'          => $extensionManifest->getTitle(),
            'version'        => $extensionManifest->getVersion(),
            'description'    => $extensionManifest->getDescription(),
            'author'         => $extensionManifest->getAuthor(),
            'uninstall_note' => $extensionManifest->getUninstallationNote(),
            'dependencies'   => '|' . implode('|', $extensionManifest->getDependencies()) . '|',
        ]);

        // Extensions may add their own config params
        $this->dynamicConfigProvider->regenerate();

        // Regenerate the extension cache
        $this->extensionCache->clear();

        return [];
    }

    /**
     * @throws DbLayerException
     */
    public function uninstallExtension(string $id): ?string
    {
        if ($this->baseModuleRegistry->contains($id)) {
            return $this->baseModuleLifecycleError($id);
        }

        // Fetch info about the extension
        $currentExtension = $this->fetchExtension($id);
        if ($currentExtension === null) {
            return $this->translator->trans('Extension loading error', ['{{ extension }}' => $id]);
        }

        // Check dependencies
        $result       = $this->dbLayer->select('e.id')
            ->from('extensions AS e')
            ->where('e.disabled = 0')
            ->andWhere('e.dependencies LIKE :id')
            ->setParameter('id', '%|' . $id . '|%')
            ->execute()
        ;
        $dependencies = $result->fetchColumn();

        if ($dependencies !== []) {
            return $this->translator->trans('Uninstall dependency', [
                '{{ extension }}'    => $id,
                '{{ dependencies }}' => implode(', ', $dependencies),
            ]);
        }

        // Run uninstall code
        $extensionClass = '\\s2_extensions\\' . $id . '\\Manifest';
        if (!is_a($extensionClass, ManifestInterface::class, true)) {
            throw new \RuntimeException(\sprintf('Extension manifest "%s" is missing or invalid.', $extensionClass));
        }

        $extensionManifest = new $extensionClass();
        $extensionManifest->uninstall($this->dbLayer, $this->container);

        $this->dbLayer
            ->delete('extensions')
            ->where('id = :id')->setParameter('id', $id)
            ->execute()
        ;

        // Regenerate the extension cache
        $this->extensionCache->clear();

        return null;
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>
     */
    public function getEnabledExtensionIds(): array
    {
        $result            = $this->dbLayer
            ->select('e.id')
            ->from('extensions AS e')
            ->where('e.disabled = 0')
            ->execute()
        ;

        $optionalExtensionIds = array_values(array_filter(
            $result->fetchColumn(),
            is_string(...)
        ));

        return array_values(array_unique([
            ...$this->baseModuleRegistry->ids(),
            ...$optionalExtensionIds,
        ]));
    }

    private function baseModuleLifecycleError(string $id): string
    {
        return $this->translator->trans('Base module lifecycle forbidden', ['{{ module }}' => $id]);
    }

    /**
     * @throws DbLayerException
     * @return array<mixed>|null
     */
    private function fetchExtension(string $id): ?array
    {
        $result = $this->dbLayer->select('e.*')
            ->from('extensions AS e')
            ->where('e.id = :id')->setParameter('id', $id)
            ->execute()
        ;

        $extension = $result->fetchAssoc();

        return $extension !== false ? $extension : null;
    }
}
