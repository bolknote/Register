<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Installation;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use S2\Cms\Pdo\DbLayer;

final readonly class WelcomePostInstaller
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function create(string $title, string $text, int $authorId, int $timestamp): void
    {
        $this->dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->values([
                'content_type'     => ':content_type',
                'slug_scope'       => "'root'",
                'slug'             => "'welcome-to-register'",
                'title'            => ':title',
                'excerpt'          => "''",
                'body'             => ':body',
                'created_at'       => ':created_at',
                'published_at'     => ':published_at',
                'updated_at'       => ':updated_at',
                'published'        => '1',
                'comments_enabled' => '1',
                'author_id'        => ':author_id',
            ])
            ->execute([
                'content_type' => ContentType::POST->value,
                'created_at'   => $timestamp,
                'published_at' => $timestamp,
                'updated_at'   => $timestamp,
                'title'        => $title,
                'body'         => $text,
                'author_id'    => $authorId,
            ])
        ;
    }
}
