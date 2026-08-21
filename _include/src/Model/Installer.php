<?php
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Model;

use Register\Ai\AiSettings;
use S2\Cms\AdminYard\UserSettingStorage;
use S2\Cms\Comment\Antispam\AntispamSchema;
use Register\Comment\CommentSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Content\ContentTagSchema;
use Register\Live\LiveUpdateSchema;
use Register\Url\ContentUrlAliasSchema;
use Register\Schema\SchemaManager;
use S2\Cms\Security\WebAuthn\WebAuthnSchema;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\SchemaBuilderInterface;
use S2\Cms\Pdo\DbLayerException;
use S2\Cms\Queue\QueueSchema;

readonly class Installer
{
    public function __construct(private DbLayer $dbLayer)
    {
    }

    /**
     * @throws DbLayerException
     */
    public function createTables(): void
    {
        // Create all tables
        $this->dbLayer->createTable('config', function (SchemaBuilderInterface $table): void {
            $table
                ->addString('name', 191)
                ->addText('value', nullable: false)
                ->setPrimaryKey(['name'])
            ;
        });

        $this->dbLayer->createTable('extensions', function (SchemaBuilderInterface $table): void {
            $table
                ->addString('id', 150)
                ->addString('title', 255)
                ->addString('version', 25)
                ->addText('description')
                ->addString('author', 50)
                ->addText('uninstall_note')
                ->addBoolean('disabled')
                ->addString('dependencies', 255)
                ->setPrimaryKey(['id'])
            ;
        });

        $this->dbLayer->createTable('users', function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('login', 191)
                ->addString('password', 255)
                ->addString('email', 80)
                ->addString('name', 80)
                ->addBoolean('view')
                ->addBoolean('view_hidden')
                ->addBoolean('hide_comments')
                ->addBoolean('edit_comments')
                ->addBoolean('create_articles')
                ->addBoolean('edit_site')
                ->addBoolean('edit_users')
                ->addUniqueIndex('login_idx', ['login'])
            ;
        });

        WebAuthnSchema::create($this->dbLayer);

        ContentSchema::create($this->dbLayer);
        ContentUrlAliasSchema::create($this->dbLayer);
        UserpicSchema::create($this->dbLayer);

        CommentSchema::create($this->dbLayer);
        LiveUpdateSchema::create($this->dbLayer);

        $this->dbLayer->createTable('tags', function (SchemaBuilderInterface $table): void {
            $table
                ->addIdColumn()
                ->addString('name', 191)
                ->addText('description', nullable: false)
                ->addInteger('modify_time', true)
                ->addString('url', 191)
                ->addUniqueIndex('name_idx', ['name'])
                ->addUniqueIndex('url_idx', ['url'])
            ;
        });

        ContentTagSchema::create($this->dbLayer);

        $this->dbLayer->createTable('users_online', function (SchemaBuilderInterface $table): void {
            $table->addString('challenge', 32)
                ->addInteger('time', true)
                ->addString('login', 191, true, null)
                ->addString('ip', 39)
                ->addString('ua', 200)
                ->addString('comment_cookie', 32)
                ->addForeignKey(
                    'fk_user',
                    ['login'],
                    'users',
                    ['login'],
                    'CASCADE',
                    'CASCADE',
                )
                ->addIndex('login_idx', ['login'])
                ->addUniqueIndex('challenge_idx', ['challenge'])
            ;
        });

        $this->dbLayer->createTable(UserSettingStorage::TABLE_NAME, function (SchemaBuilderInterface $table): void {
            $table
                ->addInteger('user_id', true, default: null)
                ->addString('name', 191, default: null)
                ->addText('value', nullable: false)
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

        $this->dbLayer->createTable('queue', function (SchemaBuilderInterface $table): void {
            $table
                ->addString('id', 80, default: null)
                ->addString('code', 80, default: null)
                ->addText('payload', nullable: false)
                ->addInteger('generation', true, default: 1)
                ->addInteger('created_at', true)
                ->addInteger('updated_at', true)
                ->addInteger('available_at', true)
                ->addInteger('attempts', true)
                ->addText('last_error')
                ->addInteger('failed_at', true, true, null)
                ->setPrimaryKey(['id', 'code'])
                ->addIndex('due_idx', ['failed_at', 'available_at', 'created_at'])
            ;
        });

        QueueSchema::createRunnerLeaseStorage($this->dbLayer);

        AntispamSchema::create($this->dbLayer);
    }

    /**
     * @throws DbLayerException
     */
    public function dropTables(): void
    {
        AntispamSchema::drop($this->dbLayer);
        $this->dbLayer->dropTable(QueueSchema::LEASE_TABLE);
        $this->dbLayer->dropTable('queue');
        ContentTagSchema::drop($this->dbLayer);
        $this->dbLayer->dropTable('tags');
        LiveUpdateSchema::drop($this->dbLayer);
        CommentSchema::drop($this->dbLayer);
        ContentUrlAliasSchema::drop($this->dbLayer);
        ContentSchema::drop($this->dbLayer);
        UserpicSchema::drop($this->dbLayer);
        $this->dbLayer->dropTable('extensions');
        $this->dbLayer->dropTable('config');
        $this->dbLayer->dropTable(UserSettingStorage::TABLE_NAME);
        $this->dbLayer->dropTable('users_online');
        WebAuthnSchema::drop($this->dbLayer);
        $this->dbLayer->dropTable('users');
    }

    /**
     * @throws DbLayerException
     */
    public function insertConfigData(
        string $siteName,
        string $email,
        string $defaultLanguage,
    ): void
    {
        $antispamFallbackSecret = bin2hex(random_bytes(32));

        // Insert config data
        $config = [
            'S2_SITE_NAME'        => $siteName,
            'S2_WEBMASTER'        => '',
            'S2_WEBMASTER_EMAIL'  => $email,
            'S2_START_YEAR'       => date('Y'),
            'S2_USE_HIERARCHY'    => '1',
            'S2_MAX_ITEMS'        => '0',
            'S2_FAVORITE_URL'     => 'favorite',
            'S2_TAGS_URL'         => 'tags',
            'S2_STYLE'            => 'register',
            'S2_LANGUAGE'         => $defaultLanguage,
            'S2_SHOW_COMMENTS'    => '1',
            'S2_ENABLED_COMMENTS' => '1',
            'S2_PREMODERATION'    => '0',
            'S2_ANTISPAM_MODE'    => 'local',
            'S2_ANTISPAM_SECRET'  => $antispamFallbackSecret,
            'S2_ANTISPAM_SPAM_SCORE' => '35',
            'S2_ANTISPAM_BLATANT_SCORE' => '80',
            'S2_AKISMET_KEY'      => '',
            'S2_ADMIN_COLOR'      => '#eeeeee',
            'S2_ADMIN_NEW_POS'    => '0',
            'S2_ADMIN_CUT'        => '0',
            'S2_LOGIN_TIMEOUT'    => '60',
            'S2_LAST_MAINTENANCE' => '0',
            AiSettings::PROVIDER_CONFIG_KEY => AiSettings::PROVIDER_DISABLED,
            AiSettings::API_KEY_CONFIG_KEY  => '',
            AiSettings::MODEL_CONFIG_KEY    => '',
            AiSettings::FOLDER_ID_CONFIG_KEY => '',
            AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY => '',
            AiSettings::GIGACHAT_SCOPE_CONFIG_KEY => AiSettings::GIGACHAT_SCOPE_PERSONAL,
            SchemaManager::CONFIG_KEY => '0',
        ];

        foreach ($config as $conf_name => $conf_value) {
            $this->dbLayer
                ->insert('config')
                ->setValue('name', ':name')->setParameter('name', $conf_name)
                ->setValue('value', ':value')->setParameter('value', $conf_value)
                ->execute()
            ;
        }
    }

    /**
     * @throws DbLayerException
     */
    public function insertMainPage(string $title, int $time, string $pageText = ''): int
    {
        $this->dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('parent_id', 'NULL')
            ->setValue('slug_scope', "'main'")
            ->setValue('slug', "''")
            ->setValue('title', ':title')->setParameter('title', $title)
            ->setValue('created_at', ':created_at')->setParameter('created_at', $time)
            ->setValue('published_at', '0')
            ->setValue('updated_at', ':updated_at')->setParameter('updated_at', $time)
            ->setValue('published', '1')
            ->setValue('template', ':template')->setParameter('template', 'mainpage.php')
            ->setValue('excerpt', ':excerpt')->setParameter('excerpt', $pageText)
            ->setValue('body', ':body')->setParameter('body', $pageText)
            ->execute()
        ;

        return (int)$this->dbLayer->insertId();
    }
}
