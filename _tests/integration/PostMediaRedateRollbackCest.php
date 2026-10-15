<?php

declare(strict_types = 1);

namespace integration;

use Register\Admin\Picture\PictureFileNameHelper;
use Register\Admin\Picture\PictureStorageQuota;
use Register\AdminYard\Translator;
use Register\Module\Blog\Inplace\PostInplaceController;
use Register\Module\Blog\Inplace\PostInplaceMediaStorage;
use Register\Module\Blog\Inplace\PostMediaCleaner;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PostMediaRedateRollbackCest
{
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';

    public function nestedRollbacksRestoreNamesAndUndoAnInterveningUpload(\IntegrationTester $I): void
    {
        $this->withMediaDirectory($I, function (string $directory, string $token) use ($I): void {
            $pdo = $I->grabService(\PDO::class);
            $repository = $I->grabService(PostMediaRepository::class);
            $original = $this->upload($I, $token);
            $id = (int)$original['media_id'];
            $originalFile = $directory . '/' . $original['name'];
            $originalBytes = file_get_contents($originalFile);
            $pdo->exec('SAVEPOINT first_media_date');
            $first = $this->redate($I, $token, [$id], '2001-01-02');
            $pdo->exec('SAVEPOINT second_media_date');
            $second = $this->redate($I, $token, [$id], '2001-01-03');
            $I->assertFileExists($directory . '/' . $second['name']);
            $pdo->exec('ROLLBACK TO SAVEPOINT second_media_date');
            $pdo->exec('RELEASE SAVEPOINT second_media_date');

            $I->assertSame($first['name'], basename($repository->find($id)['storage_path']));
            $I->assertFileExists($directory . '/' . $first['name']);
            $I->assertSame($originalBytes, file_get_contents($directory . '/' . $first['name']));
            $I->assertFileDoesNotExist($directory . '/' . $second['name']);

            // In the same transaction a fresh upload can reuse the old name.
            // Undoing it must precede restoring the first file to that name.
            $intervening = $this->upload($I, $token);
            $I->assertSame($original['name'], $intervening['name']);
            $pdo->exec('ROLLBACK TO SAVEPOINT first_media_date');
            $pdo->exec('RELEASE SAVEPOINT first_media_date');

            $I->assertNull($repository->find((int)$intervening['media_id']));
            $I->assertSame($original['name'], basename($repository->find($id)['storage_path']));
            $I->assertFileExists($originalFile);
            $I->assertSame($originalBytes, file_get_contents($originalFile));
            $I->assertFileDoesNotExist($directory . '/' . $first['name']);

            // The rolled-back lock/callbacks must allow an ordinary retry.
            $retried = $this->redate($I, $token, [$id], '2001-01-02');
            $I->assertSame($first['name'], $retried['name']);
            $I->assertFileExists($directory . '/' . $retried['name']);
        });
    }

    public function aFailedBatchRestoresFilesAlreadyMovedByThatRequest(\IntegrationTester $I): void
    {
        $this->withMediaDirectory($I, function (string $directory, string $token) use ($I): void {
            $first = $this->upload($I, $token);
            $second = $this->upload($I, $token);
            $I->assertTrue(unlink($directory . '/' . $second['name']));
            $this->redate($I, $token, [(int)$first['media_id'], (int)$second['media_id']], '2001-01-02', 503);

            $repository = $I->grabService(PostMediaRepository::class);
            $I->assertSame($first['name'], basename($repository->find((int)$first['media_id'])['storage_path']));
            $I->assertFileExists($directory . '/' . $first['name']);
            $I->assertFileDoesNotExist($directory . '/2001.01.02.png');

            $retry = $this->redate($I, $token, [(int)$first['media_id']], '2001-01-02');
            $I->assertSame('2001.01.02.png', $retry['name']);
        });
    }

    public function rollingBackAnUploadRemovesItsUnregisteredFile(\IntegrationTester $I): void
    {
        $this->withMediaDirectory($I, function (string $directory, string $token) use ($I): void {
            $pdo = $I->grabService(\PDO::class);
            $pdo->exec('SAVEPOINT upload_rollback');

            $upload = $this->upload($I, $token);
            $I->assertFileExists($directory . '/' . $upload['name']);
            $pdo->exec('ROLLBACK TO SAVEPOINT upload_rollback');
            $pdo->exec('RELEASE SAVEPOINT upload_rollback');

            $I->assertNull($I->grabService(PostMediaRepository::class)->find((int)$upload['media_id']));
            $I->assertFileDoesNotExist($directory . '/' . $upload['name']);
            $I->assertSame($upload['name'], $this->upload($I, $token)['name']);
        });
    }

    /** @param \Closure(string, string): void $test */
    private function withMediaDirectory(\IntegrationTester $I, \Closure $test): void
    {
        $root = __DIR__ . '/../_output/images';
        $subdirectory = '/redate-' . bin2hex(random_bytes(6));
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
        $pdo = $I->grabService(\PDO::class);
        $pdo->exec('SAVEPOINT media_redate_fixture');
        try {
            $I->login('author', 'author');
            $I->amOnPage('https://localhost/');
            $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
            $test($directory, $token);
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT media_redate_fixture');
            $pdo->exec('RELEASE SAVEPOINT media_redate_fixture');
            $I->replaceService(PostInplaceMediaStorage::class, $originalStorage, [PostInplaceController::class, PostMediaCleaner::class]);
            foreach (new \DirectoryIterator($directory) as $entry) {
                if (!$entry->isDot()) {
                    unlink($entry->getPathname());
                }
            }

            rmdir($directory);
        }
    }

    /** @return array<string, mixed> */
    private function upload(\IntegrationTester $I, string $token): array
    {
        $file = tempnam(sys_get_temp_dir(), 'register-redate-upload-');
        if ($file === false) {
            throw new \RuntimeException('Unable to create an upload fixture.');
        }

        try {
            file_put_contents($file, (string)base64_decode(self::PNG, true));
            $I->sendPost('https://localhost/_inplace/post/new', [
                'inplace_action' => 'media', 'inplace_token' => $token, 'published_at' => (string)strtotime('2001-01-01 12:00:00'),
                'media_width' => '1', 'media_height' => '1', 'media_display_width' => '1', 'media_display_height' => '1',
            ], ['media' => new UploadedFile($file, 'image.png', 'image/png', null, true)]);
            $I->seeResponseCodeIs(200);
            $upload = $I->grabJson();
            $I->assertIsArray($upload);

            return $upload;
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * @param list<int> $ids
     * @return array<string, mixed>
     */
    private function redate(\IntegrationTester $I, string $token, array $ids, string $date, int $status = 200): array
    {
        $I->sendAjaxPostRequest('https://localhost/_inplace/post/new', [
            'inplace_action' => 'media_redate', 'inplace_token' => $token,
            'media_ids' => implode(',', $ids), 'published_at' => (string)strtotime($date . ' 12:00:00'),
        ]);
        $I->seeResponseCodeIs($status);

        $response = $I->grabJson();
        $I->assertIsArray($response);

        return $response['media'][0] ?? [];
    }
}
