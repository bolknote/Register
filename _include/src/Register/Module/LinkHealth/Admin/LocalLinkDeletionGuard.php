<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\LinkHealth\Admin;

use Register\Content\ContentDeletionGuardInterface;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Module\LinkHealth\ContentPathResolver;
use Register\Module\LinkHealth\LinkKind;
use Register\Module\LinkHealth\Manifest;
use S2\Cms\Pdo\DbLayer;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class LocalLinkDeletionGuard implements ContentDeletionGuardInterface
{
    public function __construct(
        private DbLayer             $dbLayer,
        private ContentPathResolver $pathResolver,
        private TranslatorInterface $translator,
    ) {
    }

    /** @return list<string> */
    #[\Override]
    public function violations(ContentId ...$contentIds): array
    {
        if ($contentIds === []) {
            return [];
        }

        if (!$this->inventoryIsReady()) {
            return [$this->trans('Link inventory deletion wait')];
        }

        $deletingIds = [];
        foreach ($contentIds as $contentId) {
            $deletingIds[$contentId->value] = true;
        }

        $this->pathResolver->refresh();
        $matchingTargetIds = [];
        $targets = $this->dbLayer
            ->select('id, normalized_url, local_content_id')
            ->from(Manifest::TARGET_TABLE)
            ->where('kind = :kind')->setParameter('kind', LinkKind::LOCAL->value)
            ->execute()
            ->fetchAssocAll()
        ;
        foreach ($targets as $target) {
            $resolved = $this->pathResolver->resolve((string)$target['normalized_url']);
            $resolvedId = $resolved?->value;
            if (($target['local_content_id'] === null ? null : (int)$target['local_content_id']) !== $resolvedId) {
                $this->dbLayer->update(Manifest::TARGET_TABLE)
                    ->set('local_content_id', ':local_content_id')->setParameter('local_content_id', $resolvedId)
                    ->where('id = :id')->setParameter('id', (int)$target['id'])
                    ->execute()
                ;
            }

            if ($resolvedId !== null && isset($deletingIds[$resolvedId])) {
                $matchingTargetIds[] = (int)$target['id'];
            }
        }

        if ($matchingTargetIds === []) {
            return [];
        }

        $parameters         = [];
        $targetPlaceholders = $this->placeholders('target', $matchingTargetIds, $parameters);
        $sourcePlaceholders = $this->placeholders('source', array_keys($deletingIds), $parameters);
        $rows = $this->dbLayer
            ->select('cl.target_id, cl.source_content_id, source.title AS source_title')
            ->addSelect('target_content.id AS target_content_id, target_content.title AS target_title')
            ->from(Manifest::CONTENT_LINK_TABLE . ' AS cl')
            ->innerJoin(Manifest::TARGET_TABLE . ' AS target', 'target.id = cl.target_id')
            ->innerJoin(ContentSchema::TABLE_NAME . ' AS source', 'source.id = cl.source_content_id')
            ->innerJoin(ContentSchema::TABLE_NAME . ' AS target_content', 'target_content.id = target.local_content_id')
            ->where('cl.target_id IN (' . implode(', ', $targetPlaceholders) . ')')
            ->andWhere('cl.source_content_id NOT IN (' . implode(', ', $sourcePlaceholders) . ')')
            ->andWhere('source.published = 1')
            ->orderBy('target_content.title, source.title')
        ;
        foreach ($parameters as $name => $value) {
            $rows->setParameter($name, $value);
        }

        /** @var array<int, array{title: string, sources: list<string>}> $blocking */
        $blocking = [];
        foreach ($rows->execute()->fetchAssocAll() as $row) {
            $targetId = (int)$row['target_id'];
            $targetTitle = trim((string)$row['target_title']);
            if ($targetTitle === '') {
                $targetTitle = '#' . (int)$row['target_content_id'];
            }

            $blocking[$targetId] ??= [
                'title'   => $targetTitle,
                'sources' => [],
            ];
            $sourceTitle = trim((string)$row['source_title']);
            if ($sourceTitle === '') {
                $sourceTitle = '#' . (int)$row['source_content_id'];
            }

            if (!\in_array($sourceTitle, $blocking[$targetId]['sources'], true)) {
                $blocking[$targetId]['sources'][] = $sourceTitle;
            }
        }

        $violations = [];
        foreach ($blocking as $item) {
            $sources = array_slice($item['sources'], 0, 3);
            if (\count($item['sources']) > \count($sources)) {
                $sources[] = $this->trans('Link deletion more sources', [
                    '{{ count }}' => \count($item['sources']) - \count($sources),
                ]);
            }

            $violations[] = $this->trans('Cannot delete linked content', [
                '{{ target }}'  => $item['title'],
                '{{ sources }}' => implode(', ', $sources),
            ]);
        }

        return $violations;
    }

    /** @param array<string, int|string> $parameters */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters);
    }

    private function inventoryIsReady(): bool
    {
        $generation = $this->dbLayer->select('value')->from('config')
            ->where('name = :name')->setParameter('name', Manifest::INVENTORY_GENERATION_CONFIG_KEY)
            ->execute()->result();

        return (\is_int($generation) || (\is_string($generation) && ctype_digit($generation)))
            && (int)$generation >= Manifest::INVENTORY_GENERATION;
    }

    /**
     * @param list<int> $values
     * @param array<string, int> $parameters
     * @return list<string>
     */
    private function placeholders(string $prefix, array $values, array &$parameters): array
    {
        $placeholders = [];
        foreach ($values as $index => $value) {
            $name                = $prefix . '_' . $index;
            $parameters[$name]   = $value;
            $placeholders[]      = ':' . $name;
        }

        return $placeholders;
    }
}
