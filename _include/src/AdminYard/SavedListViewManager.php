<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace S2\Cms\AdminYard;

use S2\AdminYard\Form\FormParams;
use S2\AdminYard\SettingStorage\SettingStorageInterface;
use S2\Cms\Security\Http\AdminMutationGuard;

final readonly class SavedListViewManager
{
    private const int MAX_VIEWS = 12;

    private const int MAX_FILTERS = 50;

    private const int MAX_VALUES_PER_FILTER = 50;

    private const int MAX_NAME_LENGTH = 80;

    private const int MAX_VALUE_LENGTH = 500;

    private const string SETTING_PREFIX = 'saved_list_views_';

    /** @var list<string> */
    private const array RESERVED_FILTER_NAMES = [
        'action',
        'apply_filter',
        'csrf_token',
        'entity',
        'page',
        'sort_direction',
        'sort_field',
    ];

    public function __construct(private SettingStorageInterface $settingStorage)
    {
    }

    /**
     * @param array<string, mixed> $filterData
     * @return array{filters: array<string, bool|float|int|string|null|list<bool|float|int|string|null>>, sort_field: string|null, sort_direction: string|null}
     */
    public function createState(array $filterData, ?string $sortField, ?string $sortDirection): array
    {
        return $this->normalizeState([
            'filters'        => $filterData,
            'sort_field'     => $sortField,
            'sort_direction' => $sortDirection,
        ]);
    }

    /**
     * @return list<array{id: string, name: string, state: array{filters: array<string, bool|float|int|string|null|list<bool|float|int|string|null>>, sort_field: string|null, sort_direction: string|null}}>
     */
    public function getViews(string $entityName): array
    {
        $this->assertEntityName($entityName);

        $storedViews = $this->settingStorage->get(self::SETTING_PREFIX . $entityName);
        if (!\is_array($storedViews)) {
            return [];
        }

        $views = [];
        foreach ($storedViews as $storedView) {
            if (!\is_array($storedView)
                || !isset($storedView['id'], $storedView['name'], $storedView['state'])
                || !\is_string($storedView['id'])
                || preg_match('/\A[0-9a-f]{16}\z/D', $storedView['id']) !== 1
                || !\is_string($storedView['name'])
                || !\is_array($storedView['state'])
            ) {
                continue;
            }

            try {
                $name  = $this->normalizeName($storedView['name']);
                $state = $this->normalizeState($storedView['state']);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $views[] = [
                'id'    => $storedView['id'],
                'name'  => $name,
                'state' => $state,
            ];
        }

        return $views;
    }

    /**
     * @param array<string, mixed> $state
     * @return list<array{id: string, name: string, state: array{filters: array<string, bool|float|int|string|null|list<bool|float|int|string|null>>, sort_field: string|null, sort_direction: string|null}}>
     */
    public function save(string $entityName, string $name, array $state): array
    {
        $this->assertEntityName($entityName);
        $name  = $this->normalizeName($name);
        $state = $this->normalizeState($state);
        $views = $this->getViews($entityName);

        $existingIndex = null;
        foreach ($views as $index => $view) {
            if (mb_strtolower($view['name']) === mb_strtolower($name)) {
                $existingIndex = $index;
                break;
            }
        }

        if ($existingIndex === null) {
            if (\count($views) >= self::MAX_VIEWS) {
                throw new \LengthException('Too many saved views. Delete one before saving another.');
            }

            $views[] = [
                'id'    => bin2hex(random_bytes(8)),
                'name'  => $name,
                'state' => $state,
            ];
        } else {
            $views[$existingIndex] = [
                'id'    => $views[$existingIndex]['id'],
                'name'  => $name,
                'state' => $state,
            ];
        }

        $this->settingStorage->set(self::SETTING_PREFIX . $entityName, $views);

        return $views;
    }

    /**
     * @return list<array{id: string, name: string, state: array{filters: array<string, bool|float|int|string|null|list<bool|float|int|string|null>>, sort_field: string|null, sort_direction: string|null}}>
     */
    public function delete(string $entityName, string $viewId): array
    {
        $this->assertEntityName($entityName);
        if (preg_match('/\A[0-9a-f]{16}\z/D', $viewId) !== 1) {
            throw new \InvalidArgumentException('Invalid saved view identifier.');
        }

        $views = array_values(array_filter(
            $this->getViews($entityName),
            static fn(array $view): bool => $view['id'] !== $viewId,
        ));

        if ($views === []) {
            $this->settingStorage->remove(self::SETTING_PREFIX . $entityName);
        } else {
            $this->settingStorage->set(self::SETTING_PREFIX . $entityName, $views);
        }

        return $views;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function findMatchingViewId(string $entityName, array $state): ?string
    {
        $state = $this->normalizeState($state);
        foreach ($this->getViews($entityName) as $view) {
            if ($view['state'] === $state) {
                return $view['id'];
            }
        }

        return null;
    }

    public function csrfToken(string $entityName): string
    {
        $this->assertEntityName($entityName);

        return (new FormParams(
            'SavedListView',
            [],
            $this->settingStorage,
            'update',
            ['entity' => $entityName],
        ))->getCsrfToken();
    }

    public function csrfTokenMatches(string $entityName, string $token): bool
    {
        return AdminMutationGuard::tokensMatch($this->csrfToken($entityName), $token);
    }

    /**
     * @param array<string, mixed> $state
     * @return array{filters: array<string, bool|float|int|string|null|list<bool|float|int|string|null>>, sort_field: string|null, sort_direction: string|null}
     */
    private function normalizeState(array $state): array
    {
        $filterData = $state['filters'] ?? null;
        if (!\is_array($filterData) || \count($filterData) > self::MAX_FILTERS) {
            throw new \InvalidArgumentException('Invalid saved view filters.');
        }

        $filters = [];
        foreach ($filterData as $filterName => $filterValue) {
            if (!\is_string($filterName)
                || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/D', $filterName) !== 1
                || \in_array($filterName, self::RESERVED_FILTER_NAMES, true)
            ) {
                throw new \InvalidArgumentException('Invalid saved view filter name.');
            }

            if (\is_array($filterValue)) {
                if (\count($filterValue) > self::MAX_VALUES_PER_FILTER) {
                    throw new \InvalidArgumentException('Too many values in a saved view filter.');
                }

                $normalizedValues = [];
                foreach ($filterValue as $value) {
                    $normalizedValues[] = $this->normalizeScalar($value);
                }

                sort($normalizedValues);
                $filters[$filterName] = $normalizedValues;
            } else {
                $filters[$filterName] = $this->normalizeScalar($filterValue);
            }
        }

        ksort($filters);

        $sortField = $state['sort_field'] ?? null;
        if ($sortField !== null
            && (!\is_string($sortField) || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,63}\z/D', $sortField) !== 1)
        ) {
            throw new \InvalidArgumentException('Invalid saved view sorting field.');
        }

        $sortDirection = $state['sort_direction'] ?? null;
        if (!\in_array($sortDirection, [null, 'asc', 'desc'], true)) {
            throw new \InvalidArgumentException('Invalid saved view sorting direction.');
        }

        return [
            'filters'        => $filters,
            'sort_field'     => $sortField,
            'sort_direction' => $sortDirection,
        ];
    }

    private function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('A saved view name must contain between 1 and 80 characters.');
        }

        return $name;
    }

    private function normalizeScalar(mixed $value): bool|float|int|string|null
    {
        if (!\is_bool($value) && !\is_float($value) && !\is_int($value) && !\is_string($value) && $value !== null) {
            throw new \InvalidArgumentException('Invalid saved view filter value.');
        }

        if (\is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
            throw new \InvalidArgumentException('Saved view filter value is too long.');
        }

        return $value;
    }

    private function assertEntityName(string $entityName): void
    {
        if (preg_match('/\A[A-Z][a-zA-Z0-9]{0,63}\z/D', $entityName) !== 1) {
            throw new \InvalidArgumentException('Invalid saved view entity.');
        }
    }
}
