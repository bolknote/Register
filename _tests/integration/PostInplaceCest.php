<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Comment\CommentRepository;
use Register\Comment\CommentSchema;
use Register\Content\ContentId;
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Content\ContentType;
use Register\Content\TagRepository;
use Register\Live\LiveUpdateRepository;
use Register\Module\LinkHealth\Manifest;
use S2\Cms\Pdo\DbLayer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class PostInplaceCest
{
    private const string ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';

    public function showsToolsOnlyForAnAuthorizedPostEditor(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer  = $I->grabService(DbLayer::class);
        $authorId = $this->userId($dbLayer, 'author');
        $adminId  = $this->userId($dbLayer, 'admin');
        $ownId    = $this->insertPost($dbLayer, 'author-post', $authorId);
        $otherId  = $this->insertPost($dbLayer, 'admin-post', $adminId);

        $I->amOnPage('https://localhost/author-post');
        $I->dontSeeElement('.post-card[data-post-id="' . $ownId . '"] .post-inplace-tools');
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $ownId, [
            'inplace_action' => 'edit',
            'inplace_token'  => str_repeat('0', 64),
            'revision'       => '1',
            'title'          => 'Forbidden edit',
            'body'           => '<p>Forbidden</p>',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_FORBIDDEN);

        $I->login('author', 'author');
        $I->amOnPage('https://localhost/author-post');
        $I->seeElement('.post-card[data-post-id="' . $ownId . '"] > .post-inplace-tools');
        $I->seeElement('.post-inplace-tools .post-edit-save[hidden]');
        $I->seeElement('.post-inplace-tools .post-edit-cancel[hidden]');
        $I->seeElement('.post-inplace-edit-form[hidden] input[type="hidden"][name="title"]');
        $I->seeElement('.post-inplace-edit-form[hidden] textarea[name="body"][hidden]');
        $I->seeElement('.post-inplace-edit-form[hidden] input[type="hidden"][name="tags"]');
        $I->seeElement('.post.head [data-post-inplace-title]');
        $I->seeElement('.post.body[data-post-inplace-body]');
        $I->seeElement('.post.foot .post-foot-tags.is-empty [data-post-inplace-tags-values]');
        $I->dontSeeElement('.post-inplace-html-editor');
        $I->seeElement('.post-delete-confirmation[hidden]');
        $I->seeElement('template.post-editor-context-menu-template');
        $I->seeElement('.post-editor-context-menu-template [data-context-selection-only]');
        $I->seeElement('.post-editor-context-menu-template [data-context-caret-only]');
        $I->seeElement('.post-editor-context-menu-template [data-context-action="media"]');
        $I->seeElement('.post-editor-context-menu-template [data-context-action="open-link"]');
        $I->dontSeeElement('.post-editor-context-menu-template [data-context-ai-action]');
        $I->seeElement('script[src^="/_assets/register/post-inplace.js?v="]');

        $selector = '.post-card[data-post-id="' . $ownId . '"] > .post-inplace-edit-form';
        $token    = (string)$I->grabAttributeFrom($selector . ' input[name="inplace_token"]', 'value');
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $ownId, [
            'inplace_action' => 'ai',
            'inplace_token'  => $token,
            'ai_action'      => 'proofread',
            'title'          => 'Author post',
            'text'           => '<p>Text to check.</p>',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_CONFLICT);
        $I->assertStringContainsString('AI assistant is not configured', $I->grabResponse());

        $I->amOnPage('https://localhost/admin-post');
        $I->dontSeeElement('.post-card[data-post-id="' . $otherId . '"] .post-inplace-tools');
    }

    public function editsAPostAndRejectsAStaleRevision(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var LiveUpdateRepository $updates */
        $updates = $I->grabService(LiveUpdateRepository::class);
        /** @var TagRepository $tags */
        $tags      = $I->grabService(TagRepository::class);
        $postId    = $this->insertPost($dbLayer, 'editable-post', $this->userId($dbLayer, 'admin'));
        $contentId = ContentId::post($postId);
        $tags->replace($contentId, [$this->insertTag($dbLayer)]);

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/editable-post');

        $selector = '.post-card[data-post-id="' . $postId . '"] > .post-inplace-edit-form';
        $token    = (string)$I->grabAttributeFrom($selector . ' input[name="inplace_token"]', 'value');
        $I->assertSame('Inplace tag', $I->grabAttributeFrom($selector . ' input[name="tags"]', 'value'));
        $cursor   = $updates->currentCursor();

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [
            'inplace_action' => 'edit',
            'inplace_token'  => $token,
            'revision'       => '1',
            'return_to'      => '/editable-post',
            'title'          => 'Edited in place',
            'body'           => '<p>Updated <strong>without a reload</strong>.</p>',
            'tags'           => 'Inplace tag, Fresh tag, fresh TAG',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);
        $I->assertTrue($payload['success']);
        $I->assertSame('edit', $payload['action']);
        $I->assertSame(2, $payload['revision']);
        $I->assertStringContainsString('data-post-inplace-body', $payload['body_html']);
        $I->assertStringContainsString('<strong>without a reload</strong>', $payload['body_html']);
        $I->assertSame(['Inplace tag', 'Fresh tag'], array_column($payload['tags'], 'name'));
        $I->assertStringContainsString('Fresh%20tag', $payload['tags'][1]['url']);

        $stored = $dbLayer
            ->select('title, body, revision')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
            ->fetchAssoc()
        ;
        $I->assertIsArray($stored);
        $I->assertSame('Edited in place', $stored['title']);
        $I->assertSame('<p>Updated <strong>without a reload</strong>.</p>', $stored['body']);
        $I->assertSame(2, (int)$stored['revision']);
        $I->assertSame(
            ['Inplace tag', 'Fresh tag'],
            array_column($tags->findForContent([$contentId])[(string)$contentId], 'name'),
        );
        $I->assertGreaterThan($cursor, $updates->currentCursor());

        $tagCursor = $updates->currentCursor();
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [
            'inplace_action' => 'edit',
            'inplace_token'  => $token,
            'revision'       => '2',
            'title'          => 'Edited in place',
            'body'           => '<p>Updated <strong>without a reload</strong>.</p>',
            'tags'           => '',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $tagPayload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertSame(3, $tagPayload['revision']);
        $I->assertSame([], $tagPayload['tags']);
        $I->assertSame([], $tags->findForContent([$contentId])[(string)$contentId]);
        $I->assertGreaterThan($tagCursor, $updates->currentCursor());

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [
            'inplace_action' => 'edit',
            'inplace_token'  => $token,
            'revision'       => '2',
            'title'          => 'Overwrite from stale tab',
            'body'           => '<p>Stale text</p>',
            'tags'           => 'Orphan stale tag',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_CONFLICT);
        $I->assertSame('Edited in place', $dbLayer
            ->select('title')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
            ->result());
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from('tags')
            ->where('name = :name')->setParameter('name', 'Orphan stale tag')
            ->execute()
            ->result());
    }

    public function uploadsDroppedImagesAndAudioForAnAuthorizedAuthor(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $postId  = $this->insertPost($dbLayer, 'media-post', $this->userId($dbLayer, 'author'));

        $I->login('author', 'author');
        $I->amOnPage('https://localhost/media-post');

        $selector = '.post-card[data-post-id="' . $postId . '"] > .post-inplace-edit-form';
        $token    = (string)$I->grabAttributeFrom($selector . ' input[name="inplace_token"]', 'value');

        $png = $this->temporaryFile((string)base64_decode(self::ONE_PIXEL_PNG, true));
        $wav = $this->temporaryFile($this->wavContents());
        $txt = $this->temporaryFile('Not media.');
        $storedFiles = [];

        try {
            $I->sendPost(
                'https://localhost/_inplace/post/' . $postId,
                ['inplace_action' => 'media', 'inplace_token' => $token],
                ['media' => new UploadedFile($png, 'dropped.png', 'image/png', null, true)],
            );
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $imagePayload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
            $I->assertTrue($imagePayload['success']);
            $I->assertSame('media', $imagePayload['action']);
            $I->assertSame('image', $imagePayload['kind']);
            $I->assertSame('dropped.png', $imagePayload['name']);
            $I->assertSame(1, $imagePayload['width']);
            $I->assertSame(1, $imagePayload['height']);
            $I->assertMatchesRegularExpression(
                '~^/_tests/_output/images/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.png$~D',
                $imagePayload['url'],
            );
            $storedFiles[] = $this->storedMediaPath($imagePayload['url']);
            $I->assertFileExists($storedFiles[array_key_last($storedFiles)]);

            $I->sendPost(
                'https://localhost/_inplace/post/' . $postId,
                ['inplace_action' => 'media', 'inplace_token' => $token],
                ['media' => new UploadedFile($wav, 'dropped.wav', 'audio/wav', null, true)],
            );
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $audioPayload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
            $I->assertTrue($audioPayload['success']);
            $I->assertSame('media', $audioPayload['action']);
            $I->assertSame('audio', $audioPayload['kind']);
            $I->assertSame('dropped.wav', $audioPayload['name']);
            $I->assertMatchesRegularExpression(
                '~^/_tests/_output/images/[0-9]{4}/[0-9]{2}/[a-f0-9]{32}\.wav$~D',
                $audioPayload['url'],
            );
            $storedFiles[] = $this->storedMediaPath($audioPayload['url']);
            $I->assertFileExists($storedFiles[array_key_last($storedFiles)]);

            $I->sendPost(
                'https://localhost/_inplace/post/' . $postId,
                ['inplace_action' => 'media', 'inplace_token' => $token],
                ['media' => new UploadedFile($txt, 'dropped.txt', 'text/plain', null, true)],
            );
            $I->seeResponseCodeIs(Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
            $I->assertStringContainsString('Only image and audio files', $I->grabResponse());
        } finally {
            foreach ([$png, $wav, $txt, ...$storedFiles] as $filename) {
                if (is_file($filename)) {
                    unlink($filename);
                }
            }
        }
    }

    public function deletesAPostTogetherWithCommentsAndTagRelations(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var CommentRepository $comments */
        $comments = $I->grabService(CommentRepository::class);
        /** @var TagRepository $tags */
        $tags = $I->grabService(TagRepository::class);
        /** @var LiveUpdateRepository $updates */
        $updates = $I->grabService(LiveUpdateRepository::class);

        $postId    = $this->insertPost($dbLayer, 'deletable-post', $this->userId($dbLayer, 'admin'));
        $contentId = ContentId::post($postId);
        $commentId = $comments->save(
            $contentId,
            'Reader',
            'reader@example.test',
            false,
            false,
            'This comment is deleted with the post.',
            '127.0.0.1',
            null,
        );
        $tagId = $this->insertTag($dbLayer);
        $tags->replace($contentId, [$tagId]);
        $I->setConfigValue(
            Manifest::INVENTORY_GENERATION_CONFIG_KEY,
            (string)Manifest::INVENTORY_GENERATION,
        );

        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/deletable-post');

        $selector = '.post-card[data-post-id="' . $postId . '"] > .post-delete-confirmation';
        $token    = (string)$I->grabAttributeFrom($selector . ' input[name="inplace_token"]', 'value');
        $revision = (string)$I->grabAttributeFrom($selector . ' input[name="revision"]', 'value');
        $cursor   = $updates->currentCursor();

        $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $postId, [
            'inplace_action' => 'delete',
            'inplace_token'  => $token,
            'revision'       => $revision,
            'return_to'      => '/deletable-post',
        ]);
        $I->seeResponseCodeIs(Response::HTTP_OK);

        $payload = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
        $I->assertIsArray($payload);
        $I->assertTrue($payload['success']);
        $I->assertSame('delete', $payload['action']);

        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(ContentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $postId)
            ->execute()
            ->result());
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $commentId)
            ->execute()
            ->result());
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from(ContentTagSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::POST->value)
            ->andWhere('content_id = :content_id')->setParameter('content_id', $postId)
            ->execute()
            ->result());
        $I->assertGreaterThan($cursor, $updates->currentCursor());
    }

    private function insertPost(DbLayer $dbLayer, string $slug, int $authorId): int
    {
        $timestamp = time();
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->values([
                'content_type'    => ':content_type',
                'slug_scope'      => "'root'",
                'created_at'      => ':time',
                'published_at'    => ':time',
                'updated_at'      => ':time',
                'revision'        => '1',
                'title'           => ':title',
                'excerpt'         => "''",
                'body'            => "'<p>Original body</p>'",
                'published'       => '1',
                'featured'        => '0',
                'comments_enabled' => '1',
                'series'          => "''",
                'slug'            => ':slug',
                'author_id'       => ':author_id',
            ])
            ->execute([
                'content_type' => ContentType::POST->value,
                'time'         => $timestamp,
                'title'        => ucfirst(str_replace('-', ' ', $slug)),
                'slug'         => $slug,
                'author_id'    => $authorId,
            ])
        ;

        return (int)$dbLayer->insertId();
    }

    private function insertTag(DbLayer $dbLayer): int
    {
        $dbLayer->insert('tags')->values([
            'name'        => "'Inplace tag'",
            'description' => "''",
            'modify_time' => ':modify_time',
            'url'         => "'inplace-tag'",
        ])->execute(['modify_time' => time()]);

        return (int)$dbLayer->insertId();
    }

    private function userId(DbLayer $dbLayer, string $login): int
    {
        return (int)$dbLayer
            ->select('id')
            ->from('users')
            ->where('login = :login')->setParameter('login', $login)
            ->execute()
            ->result()
        ;
    }

    private function temporaryFile(string $contents): string
    {
        $filename = tempnam(sys_get_temp_dir(), 'register_inplace_media_');
        if ($filename === false || file_put_contents($filename, $contents) === false) {
            throw new \RuntimeException('Unable to create a temporary inplace media fixture.');
        }

        return $filename;
    }

    private function wavContents(): string
    {
        $sample = "\x80";
        $format = pack('vvVVvv', 1, 1, 8000, 8000, 1, 8);

        return 'RIFF' . pack('V', 36 + \strlen($sample)) . 'WAVE'
            . 'fmt ' . pack('V', 16) . $format
            . 'data' . pack('V', \strlen($sample)) . $sample;
    }

    private function storedMediaPath(string $url): string
    {
        $prefix = '/_tests/_output/images';
        if (!str_starts_with($url, $prefix . '/')) {
            throw new \RuntimeException('Unexpected inplace media URL.');
        }

        return __DIR__ . '/../_output/images' . substr($url, \strlen($prefix));
    }
}
