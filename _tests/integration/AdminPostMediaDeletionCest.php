<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentMediaSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentTagSchema;
use Register\Comment\CommentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminPostMediaDeletionCest
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';

    public function singleDeletionRemovesCommentsBeforeSqliteReusesThePostId(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $post = $this->createPost($I, $token, 'Deleted thread source', '<p>Original post.</p>');
        $I->amOnPage('https://localhost' . $post['url']);
        $I->sendPost('https://localhost' . $post['url'], ['text' => '<p>Comment belonging to the deleted thread.</p>']);
        $I->seeResponseCodeIs(302);

        $db = $I->grabService(DbLayer::class);
        $I->assertSame(1, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)
            ->where("content_type = 'post' AND content_id = :id")->setParameter('id', $post['id'])->execute()->result());
        $this->deletePost($I, (int)$post['id']);
        $I->assertSame(0, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)
            ->where("content_type = 'post' AND content_id = :id")->setParameter('id', $post['id'])->execute()->result());
        $replacement = $this->createPost($I, $token, 'Unrelated replacement post', '<p>A different subject.</p>');
        if ($I->grabService(\PDO::class)->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $I->assertSame($post['id'], $replacement['id']);
        }

        $I->amOnPage('https://localhost' . $replacement['url']);
        $I->dontSee('Comment belonging to the deleted thread.');
    }

    public function sharedPageMediaKeepsItsPublishedIdentityDuringRedating(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $file = tempnam(sys_get_temp_dir(), 'register-shared-redate-');
        if ($file === false) {
            throw new \RuntimeException('Unable to create an upload fixture.');
        }

        $files = [$file];
        try {
            $db = $I->grabService(DbLayer::class);
            $repository = $I->grabService(PostMediaRepository::class);
            foreach (['body', 'social_image'] as $reference) {
                $upload = $this->upload($I, $token, $file);
                $image = '<img src="' . $upload['url'] . '" data-post-media-id="' . $upload['media_id']
                    . '" data-post-media-identity="1" width="1" height="1" alt="Shared">';
                $post = $this->createPost($I, $token, 'Shared file source ' . $reference, $image, (int)$upload['media_id']);
                $mediaId = (int)$upload['media_id'];
                $path = (string)$repository->find($mediaId)['storage_path'];
                $files[] = $storedFile = __DIR__ . '/../_output/images' . $path;
                $root = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)
                    ->where("content_type = 'page' AND parent_id IS NULL")->execute()->result();
                $db->insert(ContentSchema::TABLE_NAME)->values([
                    'content_type' => "'page'", 'parent_id' => ':parent', 'slug_scope' => ':scope',
                    'slug' => ':slug', 'title' => "'Page reusing an image'", 'body' => "'<p>Original page.</p>'",
                    'excerpt' => "''", 'published' => '1', 'published_at' => ':time',
                    'created_at' => ':time', 'updated_at' => ':time', 'revision' => '1',
                ])->execute(['parent' => $root, 'scope' => 'page:' . $root, 'slug' => 'shared-media-' . str_replace('_', '-', $reference), 'time' => time() - 60]);
                $pageId = (int)$db->insertId();
                $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $pageId;
                $I->amOnPage($url);
                $values = $I->grabFormValues('form[name="article-form"]');
                $sharedValue = $reference === 'body' ? '<p>Shared image</p><img src="' . $upload['url'] . '" alt="Shared">' : 'https://localhost' . $upload['url'];
                $I->sendAjaxPostRequest($url, [...$values, $reference => $sharedValue]);
                $I->assertTrue($this->response($I)['success'] ?? false, $I->grabResponse());
                $I->seeResponseCodeIs(200);
                $this->deletePost($I, (int)$post['id']);
                $I->assertSame(0, (int)$repository->find($mediaId)['pending']);
                $I->assertSame(0, (int)$repository->find($mediaId)['usage_count']);
                $I->assertFileExists($storedFile);

                // Older releases could already have relabelled a shared file.
                foreach ([0, 1] as $pending) {
                    $db->update(ContentMediaSchema::FILE_TABLE)->set('pending', ':pending')->setParameter('pending', $pending)
                        ->where('id = :id')->setParameter('id', $mediaId)->execute();
                    $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                        'inplace_action' => 'media_redate', 'inplace_token' => $token,
                        'media_ids' => (string)$mediaId, 'published_at' => (string)(time() - 3 * 86400),
                    ]);
                    $I->seeResponseCodeIs(200);
                    $I->assertSame($upload['url'], $this->response($I)['media'][0]['url']);
                    $I->assertSame($path, $repository->find($mediaId)['storage_path']);
                    $I->assertFileExists($storedFile);
                    $I->assertSame($sharedValue, $db->select($reference)->from(ContentSchema::TABLE_NAME)
                        ->where('id = :id')->setParameter('id', $pageId)->execute()->result());
                    $I->assertNotContains($mediaId, array_map(static fn(array $row): int => (int)$row['id'],
                        $repository->staleUnusedMedia(time() + 86400)));
                }
            }
        } finally {
            foreach ($files as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function bulkDeletionRollsBackMediaAndLeavesCommittedOrphansEligibleForCleanup(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $uploadFile = tempnam(sys_get_temp_dir(), 'register-admin-media-');
        if ($uploadFile === false) {
            throw new \RuntimeException('Unable to create an upload fixture.');
        }

        file_put_contents($uploadFile, (string)base64_decode(self::PNG, true));
        $files = [$uploadFile];
        try {
            $upload = $this->upload($I, $token, $uploadFile);
            $image = '<img src="' . $upload['url'] . '" data-post-media-id="' . $upload['media_id']
                . '" data-post-media-identity="1" width="1" height="1" alt="Shared">';
            $posts = [];
            for ($index = 0; $index < 2; ++$index) {
                $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                    'inplace_action' => 'create', 'inplace_token' => $token,
                    'request_id' => 'admin-media-delete-' . bin2hex(random_bytes(6)),
                    'title' => 'Admin deletion media fixture ' . $index, 'body' => $image,
                    'tags' => 'Deletion fixture', 'published_at' => (string)(time() - 60),
                    'uploaded_media_ids' => (string)$upload['media_id'],
                ]);
                $I->seeResponseCodeIs(200);
                $posts[] = $this->response($I);
                $I->amOnPage('https://localhost' . $posts[$index]['url']);
                $I->sendPost('https://localhost' . $posts[$index]['url'], ['text' => '<p>A comment on deletion fixture ' . $index . '.</p>']);
                $I->seeResponseCodeIs(302);
            }

            $db = $I->grabService(DbLayer::class);
            $repository = $I->grabService(PostMediaRepository::class);
            $mediaId = (int)$upload['media_id'];
            $files[] = $storedFile = __DIR__ . '/../_output/images' . $repository->find($mediaId)['storage_path'];
            $I->assertSame(2, (int)$repository->find($mediaId)['usage_count']);

            $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list&search=Admin%20deletion%20media%20fixture&apply_filter=1');
            $items = [];
            foreach ($posts as $post) {
                $items[] = ['primary_key' => ['id' => $post['id']],
                    'csrf_token' => (string)$I->grabAttributeFrom('button[data-admin-delete][data-delete-url*="id=' . $post['id'] . '"]', 'data-csrf-token')];
            }

            $bulkToken = (string)$I->grabAttributeFrom('[data-bulk-list]', 'data-csrf-token');
            $invalidItems = $items;
            $invalidItems[1]['csrf_token'] = 'invalid';
            $request = ['entity' => 'BlogPost', 'bulk_action' => 'delete', 'csrf_token' => $bulkToken];
            $I->sendPost('https://localhost/_admin/ajax.php?action=register_bulk_list_action', [
                ...$request, 'items' => json_encode($invalidItems, JSON_THROW_ON_ERROR),
            ]);
            $I->seeResponseCodeIs(422);
            foreach ($posts as $post) {
                $I->assertSame(1, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)
                    ->where('id = :id')->setParameter('id', $post['id'])->execute()->result());
                $I->assertSame(1, (int)$db->select('COUNT(*)')->from(ContentTagSchema::TABLE_NAME)
                    ->where('content_id = :id')->setParameter('id', $post['id'])->execute()->result());
                $I->assertSame(1, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)
                    ->where("content_type = 'post' AND content_id = :id")->setParameter('id', $post['id'])->execute()->result());
            }

            $I->assertSame(2, (int)$repository->find($mediaId)['usage_count']);
            $I->assertSame(0, (int)$repository->find($mediaId)['pending']);
            $I->assertSame(2, (int)$db->select('COUNT(*)')->from(ContentMediaSchema::USAGE_TABLE)
                ->where('media_id = :id')->setParameter('id', $mediaId)->execute()->result());
            $I->assertFileExists($storedFile);

            $I->sendPost('https://localhost/_admin/ajax.php?action=register_bulk_list_action', [
                ...$request, 'items' => json_encode($items, JSON_THROW_ON_ERROR),
            ]);
            $I->seeResponseCodeIs(200);
            $I->assertSame(2, $this->response($I)['updated']);
            foreach ($posts as $post) {
                $I->assertSame(0, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)
                    ->where('id = :id')->setParameter('id', $post['id'])->execute()->result());
                $I->assertSame(0, (int)$db->select('COUNT(*)')->from(CommentSchema::TABLE_NAME)
                    ->where("content_type = 'post' AND content_id = :id")->setParameter('id', $post['id'])->execute()->result());
            }

            $I->assertSame(0, (int)$repository->find($mediaId)['usage_count']);
            $I->assertSame(0, (int)$repository->find($mediaId)['pending']);
            $I->assertFileExists($storedFile, 'An enclosing transaction can still roll back the deletion');

            $db->update(ContentMediaSchema::FILE_TABLE)->set('created_at', ':old')->setParameter('old', time() - 31 * 86400)
                ->where('id = :id')->setParameter('id', $mediaId)->execute();
            $I->assertContains($mediaId, array_map(static fn(array $row): int => (int)$row['id'], $repository->staleUnusedMedia(time())));
            // The ordinary upload workflow collects old unreferenced files.
            $next = $this->upload($I, $token, $uploadFile, time() - 2 * 86400);
            $files[] = __DIR__ . '/../_output/images' . $repository->find((int)$next['media_id'])['storage_path'];
            $I->assertNull($repository->find($mediaId));
            $I->assertFileDoesNotExist($storedFile);
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /** @return array<mixed> */
    private function createPost(\IntegrationTester $I, string $token, string $title, string $body, ?int $mediaId = null): array
    {
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
            'inplace_action' => 'create', 'inplace_token' => $token, 'request_id' => 'deletion-review-' . bin2hex(random_bytes(6)),
            'title' => $title, 'body' => $body, 'tags' => '', 'published_at' => (string)(time() - 60),
            'uploaded_media_ids' => $mediaId === null ? '' : (string)$mediaId,
        ]);
        $I->seeResponseCodeIs(200);

        return $this->response($I);
    }

    private function deletePost(\IntegrationTester $I, int $id): void
    {
        $I->amOnPage('https://localhost/_admin/index.php?entity=BlogPost&action=list');
        $button = 'button[data-admin-delete][data-delete-url*="id=' . $id . '"]';
        $url = (string)$I->grabAttributeFrom($button, 'data-delete-url');
        $csrf = (string)$I->grabAttributeFrom($button, 'data-csrf-token');
        $I->sendAjaxPostRequest('https://localhost/_admin/index.php' . $url, ['csrf_token' => $csrf]);
        $I->seeResponseCodeIs(200);
        $I->assertTrue($this->response($I)['success']);
    }

    /** @return array<mixed> */
    private function upload(\IntegrationTester $I, string $token, string $file, ?int $publishedAt = null): array
    {
        file_put_contents($file, (string)base64_decode(self::PNG, true));
        $I->sendPost('https://localhost/_inplace/post/new', [
            'inplace_action' => 'media', 'inplace_token' => $token, 'published_at' => (string)($publishedAt ?? time() - 60),
            'media_width' => '1', 'media_height' => '1', 'media_display_width' => '1', 'media_display_height' => '1',
        ], ['media' => new UploadedFile($file, 'image.png', 'image/png', null, true)]);
        $I->seeResponseCodeIs(200);

        return $this->response($I);
    }

    /** @return array<mixed> */
    private function response(\IntegrationTester $I): array
    {
        return $I->grabJson() ?? throw new \RuntimeException('Expected a JSON response.');
    }
}
