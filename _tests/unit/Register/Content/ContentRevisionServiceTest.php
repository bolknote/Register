<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Content;

use Codeception\Test\Unit;
use Register\Content\Admin\ContentRevisionService;

final class ContentRevisionServiceTest extends Unit
{
    public function testAdvancesRevisionWhenEditorialContentChanged(): void
    {
        $revision = $this->service()->resolve(
            ['title' => 'New title', 'body' => 'Body', 'revision' => '4'],
            ['column_title' => 'Old title', 'column_body' => 'Body', 'column_revision' => '4'],
            ['title', 'body'],
        );

        self::assertNotNull($revision);
        self::assertTrue($revision->contentChanged);
        self::assertSame('5', $revision->value);
    }

    public function testKeepsStoredRevisionForSecondaryChanges(): void
    {
        $revision = $this->service()->resolve(
            ['title' => 'Title', 'revision' => '1'],
            ['column_title' => 'Title', 'column_revision' => '7'],
            ['title'],
        );

        self::assertNotNull($revision);
        self::assertFalse($revision->contentChanged);
        self::assertSame('7', $revision->value);
    }

    public function testRejectsConcurrentEditorialChange(): void
    {
        self::assertNull($this->service()->resolve(
            ['title' => 'New title', 'revision' => '3'],
            ['column_title' => 'Old title', 'column_revision' => '4'],
            ['title'],
        ));
    }

    public function testRejectsMissingTrackedField(): void
    {
        $this->expectException(\LogicException::class);
        $this->service()->resolve(
            ['revision' => '1'],
            ['column_title' => 'Title', 'column_revision' => '1'],
            ['title'],
        );
    }

    private function service(): ContentRevisionService
    {
        return new ContentRevisionService();
    }
}
