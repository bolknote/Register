<?php
/**
 * @copyright 2011-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use S2\Cms\Comment\Antispam\AntispamSchema;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;
use S2\Cms\Pdo\DbLayerException;

class MigrationManager
{
    private const int S2_DB_LAST_REVISION = 25;

    public function __construct(
        private readonly DbLayer $dbLayer,
        private readonly string  $dbType,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function migrate(int $currentRevision, int $lastRevision): void
    {
        if ($lastRevision !== self::S2_DB_LAST_REVISION) {
            throw new \LogicException('Invalid last revision.');
        }

        if ($currentRevision < 2) {
            $this->dbLayer
                ->insert('config')
                ->values(['name' => "'S2_MAX_ITEMS'", 'value' => "'0'"])
                ->execute()
            ;
        }

        if ($currentRevision < 3) {
            $this->dbLayer
                ->insert('config')
                ->values(['name' => "'S2_ADMIN_COLOR'", 'value' => "'#eeeeee'"])
                ->execute()
            ;
        }

        if ($currentRevision < 4) {
            $this->dbLayer->addIndex('articles', 'children_idx', ['parent_id', 'published']);
            $this->dbLayer->addIndex('art_comments', 'sort_idx', ['article_id', 'time', 'shown']);
            $this->dbLayer->addIndex('tags', 'url_idx', ['url']);
        }

        if ($currentRevision < 5) {
            $this->dbLayer->addField('articles', 'user_id', SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER, null, false, '0', 'template');
            $this->dbLayer->addField('articles', 'revision', SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER, null, false, '1', 'modify_time');
            $this->dbLayer->addField('users', 'create_articles', SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER, null, false, '0', 'edit_comments');

            $this->dbLayer
                ->update('users')
                ->set('create_articles', 'edit_site')
                ->execute()
            ;
        }

        if ($currentRevision < 6) {
            $this->dbLayer->addField('users', 'name', SchemaBuilderInterface::TYPE_STRING, 80, false, '', 'password');
        }

        if ($currentRevision < 7) {
            $this->dbLayer->dropField('articles', 'children_preview');
        }

        if ($currentRevision < 8) {
            $this->dbLayer->addField('users_online', 'ua', SchemaBuilderInterface::TYPE_STRING, 200, false, '', 'login');
            $this->dbLayer->addField('users_online', 'ip', SchemaBuilderInterface::TYPE_STRING, 39, false, '', 'login');
            $this->dbLayer->addIndex('users_online', 'login_idx', ['login']);
        }

        if ($currentRevision < 9) {
            $this->dbLayer
                ->insert('config')
                ->values(['name' => "'S2_ADMIN_NEW_ARTICLES'", 'value' => "'0'"])
                ->execute()
            ;
        }

        if ($currentRevision < 10) {
            $allowUrlFopen = \ini_get('allow_url_fopen');
            $check_for_updates = \function_exists('curl_init')
                || \function_exists('fsockopen')
                || (\is_string($allowUrlFopen) && \in_array(strtolower($allowUrlFopen), ['on', 'true', '1'], true))
                ? '1'
                : '0';

            $this->dbLayer
                ->insert('config')
                ->values(['name' => "'S2_ADMIN_UPDATES'", 'value' => ':param'])
                ->setParameter(':param', $check_for_updates)
                ->execute()
            ;
        }

        if ($currentRevision < 11) {
            $this->dbLayer->addField('extensions', 'admin_affected', SchemaBuilderInterface::TYPE_BOOLEAN, null, false, '0', 'author');
        }

        if ($currentRevision < 12) {
            $this->dbLayer
                ->insert('config')
                ->values(['name' => "'S2_ADMIN_CUT'", 'value' => "'0'"])
                ->execute()
            ;
        }

        if ($currentRevision < 13) {
            $this->dbLayer->addField('users_online', 'comment_cookie', SchemaBuilderInterface::TYPE_STRING, 32, false, '', 'ua');
        }

        if ($currentRevision < 14) {
            $this->dbLayer
                ->insert('config')
                ->values(['name' => "'S2_USE_HIERARCHY'", 'value' => "'1'"])
                ->execute()
            ;
        }

        if ($currentRevision < 15) {
            $this->dbLayer->createTable('queue', function (SchemaBuilderInterface $table): void {
                $table
                    ->addString('id', 80, default: null)
                    ->addString('code', 80, default: null)
                    ->addText('payload', nullable: false)
                    ->setPrimaryKey(['id', 'code'])
                ;
            });
        }

        if ($currentRevision < 16 && $this->dbType === 'mysql') {
            foreach ([
                         'art_comments',
                         'article_tag',
                         'articles',
                         'config',
                         'extension_hooks',
                         'extensions',
                         'queue',
                         'tags',
                         'users',
                         'users_online',
                     ] as $table) {
                $this->dbLayer->query(\sprintf('ALTER TABLE `%s` ENGINE=InnoDB;', $this->dbLayer->getPrefix() . $table));
                $this->dbLayer->query(\sprintf('ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4;', $this->dbLayer->getPrefix() . $table));
            }

            foreach ([
                         's2_blog_comments',
                         's2_blog_post_tag',
                         's2_blog_posts',
                     ] as $table) {
                if ($this->dbLayer->tableExists($table)) {
                    $this->dbLayer->query(\sprintf('ALTER TABLE `%s` ENGINE=InnoDB;', $this->dbLayer->getPrefix() . $table));
                    $this->dbLayer->query(\sprintf('ALTER TABLE `%s` CONVERT TO CHARACTER SET utf8mb4;', $this->dbLayer->getPrefix() . $table));
                }
            }
        }

        if ($currentRevision < 17) {
            $this->dbLayer
                ->delete('config')
                ->where('name = :name')->setParameter(':name', 'S2_ADMIN_UPDATES')
                ->execute()
            ;
        }

        if ($currentRevision < 18) {
            $this->dbLayer->dropTable('extension_hooks');
            $this->dbLayer->dropField('extensions', 'uninstall');
        }

        if ($currentRevision < 19) {
            $this->dbLayer->alterField('articles', 'user_id', SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER, null, true);
            $this->dbLayer
                ->update('articles')
                ->set('user_id', 'NULL')
                ->where('user_id = 0')
                ->execute()
            ;
        }

        if ($currentRevision < 20) {
            if ($this->dbLayer->fieldExists('tags', 'tag_id')) {
                $this->dbLayer->renameField('tags', 'tag_id', 'id');
            }

            $this->dbLayer->dropIndex('tags', 'url_idx');
            $this->dbLayer->addIndex('tags', 'url_idx', ['url'], true);

            $this->dbLayer->addIndex('articles', 'template_idx', ['template']);
            $this->dbLayer->dropIndex('articles', 'parent_id_idx');
            $existingUsers = $this->dbLayer
                ->select('id')
                ->from('users')
                ->execute()
                ->fetchColumn()
            ;
            $this->dbLayer
                ->update('articles')
                ->set('user_id', 'NULL')
                ->where('user_id NOT IN (' . implode(',', array_fill(0, \count($existingUsers), '?')) . ')')
                ->execute($existingUsers)
            ;
            $this->dbLayer->addForeignKey('articles', 'fk_user', ['user_id'], 'users', ['id'], 'SET NULL');

            $this->dbLayer->dropIndex('art_comments', 'article_id_idx');
            $this->dbLayer->alterField('art_comments', 'article_id', SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER, null, false);
            $this->dbLayer->query('DELETE FROM ' . $this->dbLayer->getPrefix() . 'art_comments WHERE article_id NOT IN (SELECT id FROM ' . $this->dbLayer->getPrefix() . 'articles)');
            $this->dbLayer->addForeignKey('art_comments', 'fk_article', ['article_id'], 'articles', ['id'], 'CASCADE');

            $this->dbLayer->query('DELETE FROM ' . $this->dbLayer->getPrefix() . 'article_tag WHERE article_id NOT IN (SELECT id FROM ' . $this->dbLayer->getPrefix() . 'articles)');
            $this->dbLayer->query('DELETE FROM ' . $this->dbLayer->getPrefix() . 'article_tag WHERE tag_id NOT IN (SELECT id FROM ' . $this->dbLayer->getPrefix() . 'tags)');

            $this->dbLayer->alterField('article_tag', 'article_id', SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER, null, false);
            $this->dbLayer->alterField('article_tag', 'tag_id', SchemaBuilderInterface::TYPE_UNSIGNED_INTEGER, null, false);
            $this->dbLayer->addForeignKey('article_tag', 'fk_article', ['article_id'], 'articles', ['id'], 'CASCADE');
            $this->dbLayer->addForeignKey('article_tag', 'fk_tag', ['tag_id'], 'tags', ['id'], 'CASCADE');

            $existingLogins = $this->dbLayer->select('login')
                ->from('users')
                ->execute()
                ->fetchColumn()
            ;
            $this->dbLayer->delete('users_online')
                ->where('login NOT IN (' . implode(',', array_fill(0, \count($existingLogins), '?')) . ')')
                ->andWhere('login IS NOT NULL')
                ->execute($existingLogins)
            ;
            $this->dbLayer->addForeignKey('users_online', 'fk_user', ['login'], 'users', ['login'], 'CASCADE');
            $this->dbLayer->dropIndex('users_online', 'challenge_idx');
            $this->dbLayer->addIndex('users_online', 'challenge_idx', ['challenge'], true);
        }

        if ($currentRevision < 21) {
            $this->dbLayer->createTable('user_settings', function (SchemaBuilderInterface $table): void {
                $table
                    ->addInteger('user_id', true, default: null)
                    ->addString('name', 191, default: null)
                    ->addText('value', false)
                    ->setPrimaryKey(['user_id', 'name'])
                    ->addForeignKey(
                        'fk_user',
                        ['user_id'],
                        'users',
                        ['id'],
                        'CASCADE',
                    )
                ;
            });
        }

        if ($currentRevision < 22) {
            $this->dbLayer
                ->insert('config')
                ->values(['name' => "'S2_AKISMET_KEY'", 'value' => "''"])
                ->execute()
            ;
        }

        if ($currentRevision < 23) {
            $this->dbLayer->dropField('extensions', 'admin_affected');
        }

        if ($currentRevision < 24) {
            $this->dbLayer->alterField(
                'users',
                'password',
                SchemaBuilderInterface::TYPE_STRING,
                255,
                false
            );

            $this->dbLayer->dropField('users_online', 'salt');
        }

        if ($currentRevision < 25) {
            AntispamSchema::create($this->dbLayer);

            $akismetKey = $this->dbLayer
                ->select('value')
                ->from('config')
                ->where('name = :name')->setParameter('name', 'S2_AKISMET_KEY')
                ->execute()
                ->result()
            ;

            foreach ([
                         'S2_ANTISPAM_MODE'           => \is_string($akismetKey) && trim($akismetKey) !== '' ? 'shadow' : 'local',
                         'S2_ANTISPAM_SECRET'         => bin2hex(random_bytes(32)),
                         'S2_ANTISPAM_SPAM_SCORE'     => '35',
                         'S2_ANTISPAM_BLATANT_SCORE' => '80',
                     ] as $name => $value) {
                $this->dbLayer
                    ->insert('config')
                    ->setValue('name', ':name')->setParameter('name', $name)
                    ->setValue('value', ':value')->setParameter('value', $value)
                    ->onConflictDoNothing('name')
                    ->execute()
                ;
            }

            $antispamSecret = $this->dbLayer
                ->select('value')
                ->from('config')
                ->where('name = :name')->setParameter('name', 'S2_ANTISPAM_SECRET')
                ->execute()
                ->result()
            ;
            if (!\is_string($antispamSecret) || \strlen($antispamSecret) < 32) {
                $this->dbLayer
                    ->update('config')
                    ->set('value', ':value')->setParameter('value', bin2hex(random_bytes(32)))
                    ->where('name = :name')->setParameter('name', 'S2_ANTISPAM_SECRET')
                    ->execute()
                ;
            }
        }

        $this->dbLayer->update('config')
            ->set('value', ':revision')->setParameter('revision', self::S2_DB_LAST_REVISION)
            ->where('name = :name')->setParameter('name', 'S2_DB_REVISION')
            ->execute()
        ;
    }
}
