<?php

declare(strict_types = 1);

namespace integration;

use Register\Admin\Picture\PictureFileNameHelper;
use Register\Admin\Picture\PictureStorageQuota;
use Register\AdminYard\Translator;
use Register\Content\ContentMediaSchema;
use Register\Core\Pdo\DbLayer;
use Register\Module\Blog\Inplace\PostInplaceController;
use Register\Module\Blog\Inplace\PostInplaceMediaStorage;
use Register\Module\Blog\Inplace\PostMediaCleaner;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PostMediaCleanupCest
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';

    public function draftReleaseDefersCleanupWhileAnEnclosingTransactionCanRollBack(\IntegrationTester $I): void
    {
        $this->withMediaDirectory($I, function (string $directory, string $token) use ($I): void {
            $upload = $this->upload($I, $token);
            $repository = $I->grabService(PostMediaRepository::class);
            $id = (int)$upload['media_id'];
            $file = $directory . '/' . basename((string)parse_url($upload['url'], PHP_URL_PATH));
            $release = static fn() => $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media_release', 'inplace_token' => $token, 'media_ids' => (string)$id,
            ]);
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $release();
                $I->seeResponseCodeIs(200);
                $I->assertFileExists($file);
                $I->assertNotNull($repository->find($id));
                $I->assertSame(0, (int)$repository->find($id)['usage_count']);
                $I->assertContains($id, array_column($repository->staleUnusedMedia(time() + 1), 'id'));
            }
        });
    }

    public function staleMediaCleanupWaitsForTheEnclosingTransactionToFinish(\IntegrationTester $I): void
    {
        $this->withMediaDirectory($I, function (string $directory, string $token) use ($I): void {
            $upload = $this->upload($I, $token);
            $repository = $I->grabService(PostMediaRepository::class);
            $db = $I->grabService(DbLayer::class);
            $id = (int)$upload['media_id'];
            $file = $directory . '/' . basename((string)parse_url($upload['url'], PHP_URL_PATH));
            $db->update(ContentMediaSchema::FILE_TABLE)->set('created_at', ':old')
                ->setParameter('old', time() - 8 * 86400)->where('id = :id')->setParameter('id', $id)->execute();
            // Upload-triggered collection must not unlink a file while an
            // enclosing unit of work can still restore its stored references.
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $this->upload($I, $token);
                $I->assertFileExists($file);
                $I->assertNotNull($repository->find($id));
                $I->assertContains($id, array_column($repository->staleUnusedMedia(time()), 'id'));
            }
        });
    }

    /** @param \Closure(string, string): void $test */
    private function withMediaDirectory(\IntegrationTester $I, \Closure $test): void
    {
        $root = __DIR__ . '/../_output/images';
        $subdirectory = '/cleanup-' . bin2hex(random_bytes(6));
        $directory = $root . $subdirectory;
        $I->assertTrue(mkdir($directory, 0700));
        $originalStorage = $I->grabService(PostInplaceMediaStorage::class);
        $translator = new Translator([], 'en');
        $storage = new PostInplaceMediaStorage(
            new PictureFileNameHelper($translator, 'png'),
            new PictureStorageQuota($translator, $root, __DIR__ . '/../../_cache/test/picture-upload-quota.lock', 1_000_000_000),
            $translator, $root, $subdirectory, __DIR__ . '/../../_cache/test',
        );
        $I->replaceService(PostInplaceMediaStorage::class, $storage, [PostInplaceController::class, PostMediaCleaner::class]);
        try {
            $I->login('author', 'author');
            $I->amOnPage('https://localhost/');
            $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
            $test($directory, $token);
        } finally {
            $I->replaceService(PostInplaceMediaStorage::class, $originalStorage, [PostInplaceController::class, PostMediaCleaner::class]);
            chmod($directory, 0700);
            foreach (new \DirectoryIterator($directory) as $entry) {
                if (!$entry->isDot()) {
                    unlink($entry->getPathname());
                }
            }

            rmdir($directory);
        }
    }

    /** @return array<string, mixed> */
    private function upload(\IntegrationTester $I, string $token, int $expectedStatus = 200): array
    {
        $file = tempnam(sys_get_temp_dir(), 'register-cleanup-upload-');
        if ($file === false) {
            throw new \RuntimeException('Unable to create an upload fixture.');
        }

        try {
            file_put_contents($file, (string)base64_decode(self::PNG, true));
            $I->sendPost('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media', 'inplace_token' => $token, 'published_at' => (string)(time() - 60),
                'media_width' => '1', 'media_height' => '1', 'media_display_width' => '1', 'media_display_height' => '1',
            ], ['media' => new UploadedFile($file, 'image.png', 'image/png', null, true)]);
            $I->seeResponseCodeIs($expectedStatus);

            return $expectedStatus === 200 ? ($I->grabJson() ?? []) : [];
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
