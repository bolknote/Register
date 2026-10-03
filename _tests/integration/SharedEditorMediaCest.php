<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentMediaSchema;
use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class SharedEditorMediaCest
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';

    public function removingPostImageKeepsTheFileUsedByAnAdministrativePage(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $file = tempnam(sys_get_temp_dir(), 'register-shared-media-');
        if ($file === false) {
            throw new \RuntimeException('Unable to create an upload fixture.');
        }

        file_put_contents($file, (string)base64_decode(self::PNG, true));
        $storedFile = '';
        try {
            $I->sendPost('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media', 'inplace_token' => $token, 'published_at' => (string)(time() - 60),
                'media_width' => '1', 'media_height' => '1', 'media_display_width' => '1', 'media_display_height' => '1',
            ], ['media' => new UploadedFile($file, 'image.png', 'image/png', null, true)]);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $upload = $this->response($I);
            $image = '<img src="' . $upload['url'] . '" data-post-media-id="' . $upload['media_id']
                . '" data-post-media-identity="1" width="1" height="1" alt="Shared">';
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                'inplace_action' => 'create', 'inplace_token' => $token, 'request_id' => 'shared-page-media-regression',
                'title' => 'Shared media source', 'body' => $image, 'tags' => '',
                'published_at' => (string)(time() - 60), 'uploaded_media_ids' => (string)$upload['media_id'],
            ]);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $post = $this->response($I);
            $db = $I->grabService(DbLayer::class);
            $repository = $I->grabService(PostMediaRepository::class);
            $media = $repository->find((int)$upload['media_id']);
            $storedFile = __DIR__ . '/../_output/images' . $media['storage_path'];

            // Cover copied editor HTML and the ordinary picture-manager form
            // of the same image, which has no editor identity attributes.
            foreach ([$image, '<img src="' . $upload['url'] . '" alt="Shared">'] as $index => $body) {
                $root = (int)$db->select('id')->from(ContentSchema::TABLE_NAME)
                    ->where("content_type = 'page' AND parent_id IS NULL")->execute()->result();
                $db->insert(ContentSchema::TABLE_NAME)->values([
                    'content_type' => "'page'", 'parent_id' => ':parent', 'slug_scope' => ':scope',
                    'slug' => ':slug', 'title' => "'Shared image page'", 'body' => "'<p>Original page.</p>'",
                    'excerpt' => "''", 'published' => '1', 'published_at' => ':published',
                    'created_at' => ':created', 'updated_at' => ':updated', 'revision' => '1',
                ])->execute(['parent' => $root, 'scope' => 'page:' . $root, 'slug' => 'shared-image-' . $index,
                    'published' => time() - 60, 'created' => time() - 60, 'updated' => time() - 60]);
                $pageId = (int)$db->insertId();
                $url = 'https://localhost/_admin/index.php?entity=Article&action=edit&id=' . $pageId;
                $I->amOnPage($url);
                $values = $I->grabFormValues('form[name="article-form"]');
                $I->sendAjaxPostRequest($url, [...$values, 'body' => '<p>A shared image.</p>' . $body]);
                $I->seeResponseCodeIs(Response::HTTP_OK);
                $I->assertTrue($this->response($I)['success']);
            }

            $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $post['id'], [
                'inplace_action' => 'edit', 'inplace_token' => $post['token'], 'revision' => (string)$post['revision'],
                'title' => $post['title'], 'body' => '<p>The source no longer uses the image.</p>',
                'tags' => '', 'published_at' => (string)$post['published_at'],
            ]);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $I->assertTrue($this->response($I)['success']);
            $I->assertNotNull($repository->find((int)$upload['media_id']));
            $I->assertFileExists($storedFile);
            $I->assertFalse($repository->deleteUnused((int)$upload['media_id']));
            foreach ([0, 1] as $index) {
                $I->amOnPage('https://localhost/shared-image-' . $index);
                $I->seeElement('img[src="' . $upload['url'] . '"]');
            }

            // A reference in social metadata also owns its file; removing every
            // reference makes the same unused file eligible for deletion again.
            $db->update(ContentSchema::TABLE_NAME)->set('body', "'<p>No shared image.</p>'")
                ->set('social_image', ':image')->setParameter('image', $upload['url'])
                ->where("slug = 'shared-image-0' OR slug = 'shared-image-1'")->execute();
            $I->assertFalse($repository->deleteUnused((int)$upload['media_id']));
            $db->update(ContentSchema::TABLE_NAME)->set('social_image', "''")
                ->where("slug = 'shared-image-0' OR slug = 'shared-image-1'")->execute();
            $I->assertTrue($repository->deleteUnused((int)$upload['media_id']));
        } finally {
            foreach ([$file, $storedFile] as $path) {
                if ($path !== '' && is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function tooManyAttachmentsRejectTheWriteInsteadOfLosingFiles(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $db = $I->grabService(DbLayer::class);
        $repository = $I->grabService(PostMediaRepository::class);
        $owner = (int)$db->select('id')->from('users')->where("login = 'author'")->execute()->result();
        $ids = [];
        $images = [];
        $files = [];
        try {
            for ($index = 0; $index < 1001; ++$index) {
                $path = '/1901.01.01.' . $index . '.png';
                $file = __DIR__ . '/../_output/images' . $path;
                $files[] = $file;
                file_put_contents($file, (string)base64_decode(self::PNG, true));
                $id = $repository->register(['original_name' => basename($path), 'normalized_name' => basename($path),
                    'storage_path' => $path, 'mime_type' => 'image/png', 'kind' => 'image', 'byte_size' => 67,
                    'width' => 1, 'height' => 1, 'uploaded_by' => $owner]);
                $ids[] = $id;
                $images[] = '<img src="' . $repository->url($path) . '" data-post-media-id="' . $id
                    . '" data-post-media-identity="1" width="1" height="1" alt="Gallery image">';
            }

            $before = (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result();
            $request = ['inplace_action' => 'create', 'inplace_token' => $token,
                'request_id' => 'attachment-limit-regression', 'title' => 'Large gallery',
                'body' => implode('', $images), 'tags' => '', 'published_at' => (string)(time() - 60),
                'uploaded_media_ids' => implode(',', $ids)];
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', $request);
            $I->seeResponseCodeIs(Response::HTTP_CONFLICT);
            $I->assertStringContainsString('1000', $I->grabResponse());
            $I->assertSame($before, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());
            $I->assertFileExists($files[1000]);
            $I->assertSame(0, (int)$repository->find($ids[1000])['usage_count']);

            $request['body'] = implode('', \array_slice($images, 0, 1000));
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', $request);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $post = $this->response($I);
            $I->assertTrue($post['success']);
            $I->assertSame(1000, (int)$db->select('COUNT(*)')->from(ContentMediaSchema::USAGE_TABLE)
                ->where('post_id = :id')->setParameter('id', $post['id'])->execute()->result());
            $I->assertSame(1, (int)$repository->find($ids[999])['usage_count']);
            $I->assertFileExists($files[999]);

            // Extra temporary uploads can be released independently without
            // exposing the thousand attachments accepted in the saved body.
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $post['id'], [
                'inplace_action' => 'media_release', 'inplace_token' => $post['token'],
                'media_ids' => (string)$ids[1000],
            ]);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $I->assertNull($repository->find($ids[1000]));
            $I->assertFileDoesNotExist($files[1000]);
            $I->assertFileExists($files[999]);

            // Validation must continue past the thousandth distinct id, including
            // a duplicate whose URL no longer matches its registered file.
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/' . $post['id'], [
                ...$request, 'inplace_action' => 'edit', 'inplace_token' => $post['token'],
                'revision' => (string)$post['revision'], 'title' => 'Must not commit',
                'body' => $request['body'] . '<img src="/stale-image.png" data-post-media-id="' . $ids[0] . '">',
            ]);
            $I->seeResponseCodeIs(Response::HTTP_CONFLICT);
            $I->assertSame($request['body'], $db->select('body')->from(ContentSchema::TABLE_NAME)
                ->where('id = :id')->setParameter('id', $post['id'])->execute()->result());
            $I->assertSame($post['revision'], (int)$db->select('revision')->from(ContentSchema::TABLE_NAME)
                ->where('id = :id')->setParameter('id', $post['id'])->execute()->result());
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /** @return array<mixed> */
    private function response(\IntegrationTester $I): array
    {
        $data = $I->grabJson();
        if ($data === null) {
            throw new \RuntimeException('The editor did not return a JSON response.');
        }

        return $data;
    }
}
