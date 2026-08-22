<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Model\Comment;

use Codeception\Test\Unit;
use Register\Core\Model\Comment\CommentThreadBuilder;

final class CommentThreadBuilderTest extends Unit
{
    public function testBuildsBranchesWithoutLosingChronologicalNumbers(): void
    {
        $tree = (new CommentThreadBuilder())->build([
            $this->comment(10, null, 'First'),
            $this->comment(11, 10, 'Reply'),
            $this->comment(12, null, 'Second'),
            $this->comment(13, 11, 'Nested'),
        ]);

        self::assertSame([10, 12], array_column($tree, 'id'));
        self::assertSame(11, $tree[0]['children'][0]['id']);
        self::assertSame(2, $tree[0]['children'][0]['i']);
        self::assertSame(13, $tree[0]['children'][0]['children'][0]['id']);
        self::assertSame(4, $tree[0]['children'][0]['children'][0]['i']);
        self::assertSame([
            'id'   => 11,
            'i'    => 2,
            'nick' => 'Reply',
        ], $tree[0]['children'][0]['children'][0]['parent']);
    }

    public function testKeepsBrokenImportedRelationshipsReadableAtRoot(): void
    {
        $tree = (new CommentThreadBuilder())->build([
            $this->comment(20, 22, 'Forward reference'),
            $this->comment(21, 999, 'Missing parent'),
            $this->comment(22, 22, 'Self reference'),
        ]);

        self::assertSame([20, 21, 22], array_column($tree, 'id'));
        self::assertSame([null, null, null], array_column($tree, 'parent'));
    }

    /** @return array<string, mixed> */
    private function comment(int $id, ?int $parentId, string $nick): array
    {
        return [
            'id'         => $id,
            'parent_id'  => $parentId,
            'nick'       => $nick,
            'time'       => 1_700_000_000 + $id,
            'email'      => $nick . '@example.test',
            'show_email' => 0,
            'good'       => 0,
            'text'       => $nick . ' text',
            'is_author'  => false,
        ];
    }
}
