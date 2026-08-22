<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\AdminYard;

use Codeception\Test\Unit;
use Register\AdminYard\SettingStorage\SettingStorageInterface;
use Register\Core\AdminYard\SavedListViewManager;

final class SavedListViewManagerTest extends Unit
{
    public function testSavesUpdatesMatchesAndDeletesAView(): void
    {
        $manager = new SavedListViewManager(new SavedListViewSettingStorage());
        $state   = $manager->createState([
            'search'    => 'needle',
            'published' => '1',
            'tags'      => ['php', 'register'],
        ], 'create_time', 'desc');

        $views = $manager->save('BlogPost', '  To review  ', $state);
        self::assertCount(1, $views);
        self::assertSame('To review', $views[0]['name']);
        self::assertSame(['php', 'register'], $views[0]['state']['filters']['tags']);
        self::assertSame($views[0]['id'], $manager->findMatchingViewId('BlogPost', $state));

        $updatedState = $manager->createState(['search' => 'updated'], null, null);
        $updatedViews = $manager->save('BlogPost', 'to REVIEW', $updatedState);
        self::assertCount(1, $updatedViews);
        self::assertSame($views[0]['id'], $updatedViews[0]['id']);
        self::assertSame('updated', $updatedViews[0]['state']['filters']['search']);

        self::assertSame([], $manager->delete('BlogPost', $views[0]['id']));
        self::assertSame([], $manager->getViews('BlogPost'));
    }

    public function testUsesStableEntitySpecificCsrfTokens(): void
    {
        $manager = new SavedListViewManager(new SavedListViewSettingStorage());

        $postToken = $manager->csrfToken('BlogPost');
        self::assertSame($postToken, $manager->csrfToken('BlogPost'));
        self::assertNotSame($postToken, $manager->csrfToken('Article'));
        self::assertTrue($manager->csrfTokenMatches('BlogPost', $postToken));
        self::assertFalse($manager->csrfTokenMatches('BlogPost', ''));
    }

    public function testRejectsOversizedNames(): void
    {
        $manager = new SavedListViewManager(new SavedListViewSettingStorage());

        $this->expectException(\InvalidArgumentException::class);
        $manager->save('BlogPost', str_repeat('x', 81), [
            'filters'        => [],
            'sort_field'     => null,
            'sort_direction' => null,
        ]);
    }

    public function testRejectsReservedFilterNames(): void
    {
        $manager = new SavedListViewManager(new SavedListViewSettingStorage());

        $this->expectException(\InvalidArgumentException::class);
        $manager->save('BlogPost', 'Unsafe view', [
            'filters'        => ['entity' => 'User'],
            'sort_field'     => null,
            'sort_direction' => null,
        ]);
    }
}

/** @internal */
final class SavedListViewSettingStorage implements SettingStorageInterface
{
    /** @var array<string, array<mixed>|string|int|float|bool|null> */
    private array $data = [];

    #[\Override]
    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    /** @return array<mixed>|string|int|float|bool|null */
    #[\Override]
    public function get(string $key): array|string|int|float|bool|null
    {
        return $this->data[$key] ?? null;
    }

    /** @param array<mixed>|string|int|float|bool|null $data */
    #[\Override]
    public function set(string $key, array|string|int|float|bool|null $data): void
    {
        $this->data[$key] = $data;
    }

    #[\Override]
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}
