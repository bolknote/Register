<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Installation;

use S2\Cms\Pdo\DbLayer;

final readonly class WelcomePostInstaller
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    public function create(string $title, string $text, int $authorId, int $timestamp): void
    {
        $this->dbLayer
            ->insert('s2_blog_posts')
            ->values([
                'create_time' => ':create_time',
                'modify_time' => ':modify_time',
                'revision'    => '1',
                'title'       => ':title',
                'text'        => ':text',
                'published'   => '1',
                'favorite'    => '0',
                'commented'   => '1',
                'label'       => "''",
                'url'         => "'welcome-to-register'",
                'user_id'     => ':user_id',
            ])
            ->execute([
                'create_time' => $timestamp,
                'modify_time' => $timestamp,
                'title'       => $title,
                'text'        => $text,
                'user_id'     => $authorId,
            ])
        ;
    }
}
