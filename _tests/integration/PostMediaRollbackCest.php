<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentMediaSchema;
use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PostMediaRollbackCest
{
    public function enclosingRollbackRestoresEditedAndDeletedPostsWithTheirFiles(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $temporary = tempnam(sys_get_temp_dir(), 'register-media-rollback-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create an upload fixture.');
        }

        $file = null;
        $pdo = $I->grabService(\PDO::class);
        $savepointOpen = false;
        try {
            file_put_contents($temporary, (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', true));
            $I->sendPost('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media', 'inplace_token' => $token, 'published_at' => (string)(time() - 60),
                'media_width' => '1', 'media_height' => '1', 'media_display_width' => '1', 'media_display_height' => '1',
            ], ['media' => new UploadedFile($temporary, 'image.png', 'image/png', null, true)]);
            $I->seeResponseCodeIs(200);
            $upload = $I->grabJson();
            $I->assertIsArray($upload);
            $mediaId = (int)$upload['media_id'];
            $repository = $I->grabService(PostMediaRepository::class);
            $file = __DIR__ . '/../_output/images' . $repository->find($mediaId)['storage_path'];
            $body = '<img src="' . $upload['url'] . '" data-post-media-id="' . $mediaId
                . '" data-post-media-identity="1" width="1" height="1" alt="Original image">';
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                'inplace_action' => 'create', 'inplace_token' => $token,
                'request_id' => 'media-rollback-' . bin2hex(random_bytes(6)),
                'title' => 'Rollback attachment fixture', 'body' => $body, 'tags' => '',
                'published_at' => (string)(time() - 60), 'uploaded_media_ids' => (string)$mediaId,
            ]);
            $I->seeResponseCodeIs(200);
            $post = $I->grabJson();
            $I->assertIsArray($post);
            $db = $I->grabService(DbLayer::class);
            foreach (['edit', 'delete'] as $action) {
                // The enclosing unit of work can fail after a successful route.
                $pdo->exec('SAVEPOINT register_media_rollback_test');
                $savepointOpen = true;
                $I->sendAjaxPostRequest('https://localhost' . $post['action_url'], [
                    'inplace_action' => $action, 'inplace_token' => $post['token'],
                    'revision' => (string)$post['revision'], 'title' => 'Rollback attachment fixture',
                    'body' => '<p>The image was removed.</p>', 'tags' => '',
                ]);
                $I->seeResponseCodeIs(200);
                $I->assertFileExists($file, 'A successful savepoint must not destroy a file owned before the outer transaction.');
                $I->assertSame(0, (int)$repository->find($mediaId)['usage_count']);
                $pdo->exec('ROLLBACK TO SAVEPOINT register_media_rollback_test');
                $pdo->exec('RELEASE SAVEPOINT register_media_rollback_test');
                $savepointOpen = false;

                $I->assertSame($body, $db->select('body')->from(ContentSchema::TABLE_NAME)
                    ->where('id = :id')->setParameter('id', $post['id'])->execute()->result());
                $I->assertSame(1, (int)$repository->find($mediaId)['usage_count']);
                $I->assertSame(1, (int)$db->select('COUNT(*)')->from(ContentMediaSchema::USAGE_TABLE)
                    ->where('post_id = :post')->setParameter('post', $post['id'])
                    ->andWhere('media_id = :media')->setParameter('media', $mediaId)->execute()->result());
                $I->assertFileExists($file);
            }
        } finally {
            if ($savepointOpen && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK TO SAVEPOINT register_media_rollback_test');
                $pdo->exec('RELEASE SAVEPOINT register_media_rollback_test');
            }

            foreach ([$temporary, $file] as $path) {
                if ($path !== null && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
