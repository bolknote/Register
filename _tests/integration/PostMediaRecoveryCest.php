<?php

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentMediaIdentitySchema;
use Register\Content\ContentMediaSchema;
use Register\Content\ContentSchema;
use Register\Core\Pdo\DbLayer;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

final class PostMediaRecoveryCest
{
    public function aReusedSameDayFilenameGetsANewCacheIdentity(\IntegrationTester $I): void
    {
        $repository = $I->grabService(PostMediaRepository::class);
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $date = time() - 60;
        $files = [];
        $upload = function (bool $replacement) use ($I, $repository, $token, $date, &$files): array {
            $width = $replacement ? 2 : 1;
            $file = tempnam(sys_get_temp_dir(), 'register-cache-identity-');
            if ($file === false) {
                throw new \RuntimeException('Unable to create an upload fixture.');
            }

            $files[] = $file;
            $image = imagecreatetruecolor($width, 1);
            if ($image === false) {
                throw new \RuntimeException('Unable to create an image fixture.');
            }

            imagefilledrectangle($image, 0, 0, $width - 1, 0, $width === 1 ? 0xff0000 : 0x0000ff);
            imagepng($image, $file);
            $I->sendPost('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media', 'inplace_token' => $token, 'published_at' => (string)$date,
                'media_width' => (string)$width, 'media_height' => '1',
                'media_display_width' => (string)$width, 'media_display_height' => '1',
            ], ['media' => new UploadedFile($file, 'image.png', 'image/png', null, true)]);
            $I->seeResponseCodeIs(Response::HTTP_OK);

            $payload = $I->grabJson();
            $I->assertIsArray($payload);
            $files[] = __DIR__ . '/../_output/images' . $repository->find((int)$payload['media_id'])['storage_path'];

            return $payload;
        };
        try {
            $first = $upload(false);
            $firstPath = (string)$repository->find((int)$first['media_id'])['storage_path'];
            // Model a cancelled upload whose cleanup has committed. This suite
            // keeps HTTP requests inside an outer rollback-only transaction.
            $I->assertTrue($repository->deleteUnused((int)$first['media_id']));
            $I->assertTrue(unlink(__DIR__ . '/../_output/images' . $firstPath));
            $second = $upload(true);
            $I->assertSame($firstPath, $repository->find((int)$second['media_id'])['storage_path']);
            $I->assertSame($first['name'], $second['name'], 'The historical date/ordinal filename is retained.');
            $I->assertNotSame($first['media_id'], $second['media_id']);
            $I->assertNotSame($first['url'], $second['url'], 'A browser must never reuse cached pixels from a deleted upload.');

            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media_redate', 'inplace_token' => $token,
                'published_at' => (string)$date, 'media_ids' => (string)$second['media_id'],
            ]);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $reconciled = $I->grabJson();
            $I->assertIsArray($reconciled);
            $I->assertSame($second['url'], $reconciled['media'][0]['url']);

            $body = '<img src="' . $second['url'] . '" data-post-media-id="' . $second['media_id']
                . '" data-post-media-identity="1" alt="New image">';
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                'inplace_action' => 'create', 'inplace_token' => $token,
                'request_id' => 'cache-identity-' . bin2hex(random_bytes(8)),
                'title' => 'Another post on the same day', 'body' => $body, 'tags' => '',
                'published_at' => (string)$date, 'uploaded_media_ids' => (string)$second['media_id'],
            ]);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $post = $I->grabJson();
            $I->assertIsArray($post);
            $I->assertStringContainsString('src="' . $second['url'] . '"', $post['body_html']);
            $I->assertStringContainsString('width="2" height="1"', $post['body_html']);
            $I->amOnPage('https://localhost' . $post['url']);
            $I->seeElement('img[src="' . $second['url'] . '"][width="2"][height="1"]');
        } finally {
            foreach (array_unique($files) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function aPreUpgradeUploadCanBeReconciledAndSavedFromALegacyDraft(\IntegrationTester $I): void
    {
        $db = $I->grabService(DbLayer::class);
        $repository = $I->grabService(PostMediaRepository::class);
        $root = __DIR__ . '/../_output/images';
        $yearDirectory = $root . '/1999';
        $directory = $yearDirectory . '/01';
        $hadYear = is_dir($yearDirectory);
        $hadMonth = is_dir($directory);
        if (!$hadMonth) {
            $I->assertTrue(mkdir($directory, 0700, true));
        }

        $legacy = '/1999/01/' . bin2hex(random_bytes(16)) . '.png';
        $file = $root . $legacy;
        try {
            $bytes = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', true);
            $I->assertNotFalse(file_put_contents($file, $bytes));
            $author = (int)$db->select('id')->from('users')->where('login = :login')
                ->setParameter('login', 'author')->execute()->result();
            $id = $repository->register(['original_name' => 'scan.png', 'normalized_name' => 'scan.png',
                'storage_path' => $legacy, 'mime_type' => 'image/png', 'kind' => 'image', 'byte_size' => \strlen($bytes),
                'width' => 1, 'height' => 1, 'uploaded_by' => $author]);
            // The identity migration retains pre-upgrade ids with a zero stamp.
            $db->update(ContentMediaIdentitySchema::TABLE_NAME)->set('created_at', '0')
                ->where('id = :id')->setParameter('id', $id)->execute();
            $I->login('author', 'author');
            $I->amOnPage('https://localhost/');
            $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
            $publishedAt = (string)strtotime('2001-01-02 12:00:00');
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media_redate', 'inplace_token' => $token,
                'published_at' => $publishedAt, 'media_ids' => (string)$id, 'legacy_media_ids' => (string)$id,
            ]);
            $I->seeResponseCodeIs(Response::HTTP_OK);

            $response = $I->grabJson();
            $I->assertIsArray($response);
            $media = $response['media'][0];
            $I->assertSame($repository->url($legacy, $id), $media['url']);
            $I->assertSame('scan.png', $media['name']);
            $I->assertFalse($media['persistent_identity']);
            $I->assertFileExists($file);
            $I->assertSame($bytes, file_get_contents($file));

            $body = '<p>Restored draft.</p><img src="' . $media['url'] . '" data-post-media-id="' . $id . '" width="1" height="1" alt="Scan">';
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                'inplace_action' => 'create', 'inplace_token' => $token,
                'request_id' => 'legacy-upload-' . bin2hex(random_bytes(8)),
                'title' => 'Restored legacy upload', 'body' => $body, 'tags' => '', 'published_at' => $publishedAt,
                'uploaded_media_ids' => (string)$id, 'legacy_media_ids' => (string)$id,
            ]);
            $I->seeResponseCodeIs(Response::HTTP_OK);

            $saved = $I->grabJson();
            $I->assertIsArray($saved);
            $I->assertSame($body, $saved['body']);
            $I->assertSame($legacy, $repository->find($id)['storage_path']);
            $I->assertSame(1, (int)$repository->find($id)['usage_count']);
            $I->assertSame(0, (int)$repository->find($id)['pending']);
            $I->assertFileExists($file);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }

            if (!$hadMonth) {
                rmdir($directory);
            }

            if (!$hadYear) {
                rmdir($yearDirectory);
            }
        }
    }

    public function expiredDraftCannotAdoptAnotherUploadAndCanBeSavedAfterReplacingItsImage(\IntegrationTester $I): void
    {
        $db = $I->grabService(DbLayer::class);
        $repository = $I->grabService(PostMediaRepository::class);
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $publishedAt = time() - 8 * 86400;
        $temporaryFiles = [];
        $storedFiles = [];
        $upload = function (int $date) use ($I, $token, &$temporaryFiles, &$storedFiles): array {
            $file = tempnam(sys_get_temp_dir(), 'register-media-recovery-');
            if ($file === false) {
                throw new \RuntimeException('Unable to create an upload fixture.');
            }

            $temporaryFiles[] = $file;
            file_put_contents($file, (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==', true));
            $I->sendPost('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media', 'inplace_token' => $token, 'published_at' => (string)$date,
                'media_width' => '1', 'media_height' => '1', 'media_display_width' => '1', 'media_display_height' => '1',
            ], ['media' => new UploadedFile($file, 'image.png', 'image/png', null, true)]);
            $I->seeResponseCodeIs(Response::HTTP_OK);

            $data = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
            $I->assertTrue($data['persistent_identity']);
            $storedFiles[] = $this->storedPath($data['url']);

            return $data;
        };
        try {
            $original = $upload($publishedAt);
            $originalId = (int)$original['media_id'];
            $body = '<p>Keep this draft.</p><img src="' . $original['url']
                . '" data-post-media-id="' . $originalId . '" width="1" height="1" alt="Original">';
            $db->update(ContentMediaSchema::FILE_TABLE)->set('created_at', ':created')
                ->setParameter('created', time() - 8 * 86400)->where('id = :id')->setParameter('id', $originalId)->execute();
            // Model garbage collection already committed before the draft is
            // restored; this test itself runs inside a rollback-only transaction.
            $I->assertTrue($repository->deleteUnused($originalId));
            $I->assertTrue(unlink($this->storedPath($original['url'])));
            $replacement = $upload(time() - 60);
            $replacementId = (int)$replacement['media_id'];
            $I->assertNotSame($originalId, $replacementId);
            $I->assertNull($repository->find($originalId));
            $I->assertFileDoesNotExist($this->storedPath($original['url']));

            // A batch containing both available and expired ids must not rename
            // the available file before reporting the expired attachment.
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media_redate', 'inplace_token' => $token,
                'published_at' => (string)$publishedAt, 'media_ids' => $replacementId . ',' . $originalId,
            ]);
            $I->seeResponseCodeIs(Response::HTTP_CONFLICT);
            $I->assertSame($replacement['url'], $repository->url((string)$repository->find($replacementId)['storage_path'], $replacementId));
            $I->assertFileExists($this->storedPath($replacement['url']));

            // A legacy copy may hold an id that SQLite deleted before the
            // migration. It must not reconcile that id to a new allocation.
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media_redate', 'inplace_token' => $token,
                'published_at' => (string)$publishedAt, 'media_ids' => (string)$replacementId,
                'legacy_media_ids' => (string)$replacementId,
            ]);
            $I->seeResponseCodeIs(Response::HTTP_CONFLICT);
            $I->assertFileExists($this->storedPath($replacement['url']));

            $before = (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result();
            $request = ['inplace_action' => 'create', 'inplace_token' => $token,
                'request_id' => 'expired-media-recovery-request', 'title' => 'Recovered attachment draft',
                'body' => $body, 'tags' => 'Recovered', 'published_at' => (string)$publishedAt,
                'uploaded_media_ids' => (string)$originalId];
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', $request);
            $I->seeResponseCodeIs(Response::HTTP_CONFLICT);
            $I->assertSame($before, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());
            $I->assertSame(0, (int)$repository->find($replacementId)['usage_count']);

            $request['body'] = '<p>Keep this draft.</p><img src="' . $replacement['url']
                . '" data-post-media-id="' . $replacementId . '" width="1" height="1" alt="Replacement">';
            $request['uploaded_media_ids'] = (string)$replacementId;
            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [...$request,
                'legacy_media_ids' => (string)$replacementId]);
            $I->seeResponseCodeIs(Response::HTTP_CONFLICT);
            $I->assertSame($before, (int)$db->select('COUNT(*)')->from(ContentSchema::TABLE_NAME)->execute()->result());

            $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', $request);
            $I->seeResponseCodeIs(Response::HTTP_OK);
            $saved = json_decode($I->grabResponse(), true, flags: JSON_THROW_ON_ERROR);
            $I->assertSame($request['body'], $db->select('body')->from(ContentSchema::TABLE_NAME)
                ->where('id = :id')->setParameter('id', $saved['id'])->execute()->result());
            $I->assertSame(1, (int)$repository->find($replacementId)['usage_count']);
            $I->assertNull($repository->find($originalId));
        } finally {
            foreach ([...$temporaryFiles, ...$storedFiles] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    private function storedPath(string $url): string
    {
        $url = (string)parse_url($url, PHP_URL_PATH);
        $prefix = '/_tests/_output/images';
        if (!str_starts_with($url, $prefix . '/')) {
            throw new \RuntimeException('Unexpected inplace media URL.');
        }

        return __DIR__ . '/../_output/images' . substr($url, \strlen($prefix));
    }
}
