<?php /** @noinspection PhpUnused */
/**
 * @copyright 2024-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace integration;

use Codeception\Example;
use FilesystemIterator;
use S2\Cms\Admin\Picture\PictureManager;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * @group pict
 */
class PicturesCest
{
    private const string ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQAAAAA3bvkkAAAACklEQVR4AWNgAAAAAgABc3UBGAAAAABJRU5ErkJggg==';

    public function _before(\IntegrationTester $I): void
    {
        $imagesDir = __DIR__ . '/../_output/images';
        $reserveDir = __DIR__ . '/../../_cache/test/picture_reserve';
        $this->removeDir($imagesDir);
        $this->removeDir($reserveDir);
        if (!is_dir($imagesDir) && !mkdir($imagesDir, 0700, true) && !is_dir($imagesDir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $imagesDir));
        }
    }

    /**
     * @throws \JsonException
     */
    public function testUploadAndManageFiles(\IntegrationTester $I): void
    {
        $I->login('author', 'author');

        // Upload 2 files, check output
        $file1 = $this->uploadSimplePngFile($I, '', 'test1.png');
        $file2 = $this->uploadSimplePngFile($I, '', 'test2.png');
        $storedFiles = [$file1, $file2];
        sort($storedFiles);

        $I->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/D', $file1);
        $I->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/D', $file2);
        $I->assertNotSame($file1, $file2);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_files&path=');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains($storedFiles[0], [0, 'data', 'title']);
        $I->assertJsonSubResponseEquals($storedFiles[0], [0, 'attr', 'data-fname']);
        $I->assertJsonSubResponseEquals('1*1', [0, 'attr', 'data-dim']);
        $I->assertJsonSubResponseEquals(1, [0, 'attr', 'data-bits']);
        $I->assertJsonSubResponseEquals('67 B', [0, 'attr', 'data-fsize']);

        $I->assertJsonSubResponseContains($storedFiles[1], [1, 'data', 'title']);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=preview');
        $I->seeResponseCodeIs(400);
        $I->assertJsonSubResponseContains('Parameter "file" is required.', ['message']);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=preview&file=/' . $file1);
        $I->seeResponseCodeIs(200);
        $I->see('ftypavif');

        // Author cannot rename files
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=rename_file&path=/' . $file1 . '&name=test1.php', '/');
        $I->seeResponseCodeIs(403);
        $I->assertJsonSubResponseEquals('You do not have enough permissions to perform this action.', ['message']);

        // Author cannot delete files
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=delete_files&path=/&fname[]=' . $file1, '/');
        $I->seeResponseCodeIs(403);
        $I->assertJsonSubResponseEquals('You do not have enough permissions to perform this action.', ['message']);

        // Editor can rename files, but only if extension is allowed
        $I->logout();
        $I->login('editor', 'editor');
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=rename_file&path=/' . $file1 . '&name=test1.php', '/');
        $I->seeResponseCodeIs(403);
        $I->assertJsonSubResponseContains('php', ['message']);

        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=rename_file&path=/' . $file1 . '&name=cest1.png', '/');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals('cest1.png', ['new_name']);

        // Check on renaming if file with same name already exists
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=rename_file&path=/' . $file2 . '&name=cest1.png', '/');
        $I->seeResponseCodeIs(409);
        $I->assertJsonSubResponseEquals('Rename failed: file or folder “cest1.png” already exists.', ['message']);

        // Editor can delete files
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=delete_files&path=/&fname[]=' . $file2, '/');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals(true, ['success']);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_files&path=');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains('cest1.png', [0, 'data', 'title']);
        $I->assertJsonResponseHasNoKey([1]);

        // The allowlist also applies to administrators: executable files never appear by accident.
        $I->logout();
        $I->login('admin', 'admin');
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=rename_file&path=/cest1.png&name=test1.php', \dirname('/cest1.png'));
        $I->seeResponseCodeIs(403);
        $I->assertJsonSubResponseContains('php', ['message']);
    }

    public function testMediaListHidesInfrastructureFiles(\IntegrationTester $I): void
    {
        $imagesDir = __DIR__ . '/../_output/images';
        file_put_contents($imagesDir . '/.htaccess', 'deny');
        file_put_contents($imagesDir . '/index.html', '<!doctype html>');
        file_put_contents($imagesDir . '/note.txt', 'visible');

        /** @var PictureManager $pictureManager */
        $pictureManager = $I->grabAdminService(PictureManager::class);
        $files = $pictureManager->getFiles('');
        $titles = array_map(
            static fn(array $file): string => (string)$file['data']['title'],
            $files,
        );

        $I->assertSame(['note.txt'], $titles);
    }

    public function testModernMediaExtensionsAreAccepted(\IntegrationTester $I): void
    {
        $I->login('author', 'author');

        foreach (['avif', 'webp', 'mov', 'webm'] as $extension) {
            $filename = $this->createTemporaryMedia($extension);

            $I->sendPost(
                'https://localhost/_admin/ajax.php?action=upload',
                [
                    'dir'        => '',
                    'csrf_token' => $this->getFolderCsrfToken($I, ''),
                ],
                ['pictures' => [
                    new UploadedFile($filename, 'modern.' . $extension, null, null, true),
                ]],
            );
            $I->seeResponseCodeIs(200);
            $I->assertJsonSubResponseEquals(true, ['success']);
        }
    }

    public function testUploadRejectsTooManyFiles(\IntegrationTester $I): void
    {
        $I->login('author', 'author');

        $filename = $this->createTemporaryPng();
        $files = [];
        for ($i = 0; $i < 21; ++$i) {
            $files[] = new UploadedFile($filename, 'picture-' . $i . '.png', 'image/png', null, true);
        }

        try {
            $I->sendPost(
                'https://localhost/_admin/ajax.php?action=upload',
                [
                    'dir'        => '',
                    'csrf_token' => $this->getFolderCsrfToken($I, ''),
                ],
                ['pictures' => $files],
            );
            $I->seeResponseCodeIs(413);
            $I->assertJsonSubResponseEquals('Too many files. Upload at most 20 files at once.', ['message']);
        } finally {
            if (is_file($filename)) {
                unlink($filename);
            }
        }
    }

    /**
     * @throws \JsonException
     */
    public function moveFilesAndFolders(\IntegrationTester $I): void
    {
        $I->login('author', 'author');

        // creat folder1/folder11, folder1/folder12
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=&name=folder1', '');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals('folder1', ['name']);

        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=/folder1&name=folder11', '/folder1');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals('folder11', ['name']);

        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=/folder1&name=folder12', '/folder1');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals('folder12', ['name']);

        $file1 = $this->uploadSimplePngFile($I, '/folder1', 'test1.png');
        $file2 = $this->uploadSimplePngFile($I, '/folder1', 'test2.png');
        $file3 = $this->uploadSimplePngFile($I, '/folder1', 'test3.png');
        $movedFiles = [$file1, $file2];
        sort($movedFiles);

        // move files
        $moveUrl = 'https://localhost/_admin/ajax.php?action=move_files&spath=/folder1&dpath=/folder1/folder12&fname[]=' . $file1 . '&fname[]=' . $file2;
        $this->sendMoveRequestWithTokens($I, $moveUrl, '/folder1', '/folder1/folder12');
        $I->seeResponseCodeIs(403);

        $I->logout();
        $I->login('editor', 'editor');
        $this->sendMoveRequestWithTokens($I, $moveUrl, '/folder1', '/folder1/folder12');
        $I->seeResponseCodeIs(200);

        // move folder with files
        $this->sendMoveRequestWithTokens($I, 'https://localhost/_admin/ajax.php?action=move_folder&spath=/folder1/folder12&dpath=/folder1/folder11', '/folder1/folder12', '/folder1/folder11');
        $I->seeResponseCodeIs(200);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_files&path=/folder1/folder11/folder12');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains($movedFiles[0], [0, 'data', 'title']);
        $I->assertJsonSubResponseContains($movedFiles[1], [1, 'data', 'title']);
        $I->assertJsonResponseHasNoKey([2]);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_files&path=/folder1');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains($file3, [0, 'data', 'title']);
        $I->assertJsonResponseHasNoKey([1]);
    }

    public function testNoFilesAndFolders(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->seeResponseCodeIs(200);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_folders');

        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains('Pictures', ['data']);
        $I->assertJsonSubResponseEquals([], ['children']);
    }

    /**
     * @throws \JsonException
     */
    public function testManageFolders(\IntegrationTester $I): void
    {
        $I->login('admin', 'admin');
        $I->seeResponseCodeIs(200);

        // folder1/folder11, folder1/folder12
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=&name=folder1', '');
        $I->seeResponseCodeIs(200);
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=/folder1&name=folder11', '/folder1');
        $I->seeResponseCodeIs(200);
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=/folder1&name=folder12', '/folder1');
        $I->seeResponseCodeIs(200);

        // folder2/folder21, folder2/folder22
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=&name=folder2', '');
        $I->seeResponseCodeIs(200);
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=/folder2&name=folder21', '/folder2');
        $I->seeResponseCodeIs(200);
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=/folder2&name=folder22', '/folder2');
        $I->seeResponseCodeIs(200);


        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_folders');

        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains('Pictures', ['data']);
        $I->assertJsonSubResponseEquals('folder1', ['children', 0, 'data']);
        $I->assertJsonSubResponseEquals('folder11', ['children', 0, 'children', 0, 'data']);
        $I->assertJsonSubResponseEquals('folder12', ['children', 0, 'children', 1, 'data']);

        // folder1/folder11 -> folder2
        $this->sendMoveRequestWithTokens($I, 'https://localhost/_admin/ajax.php?action=move_folder&spath=/folder1/folder11&dpath=/folder2', '/folder1/folder11', '/folder2');
        $I->seeResponseCodeIs(200);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_folders&path=/folder1');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals('folder12', [0, 'data']);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_folders&path=/folder2');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals('folder11', [0, 'data']);
        $I->assertJsonSubResponseEquals('folder21', [1, 'data']);
        $I->assertJsonSubResponseEquals('folder22', [2, 'data']);

        // folder2 exists, creating folder21
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=&name=folder2', '');
        $I->seeResponseCodeIs(200);
        // folder2 exists, creating folder22
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=create_subfolder&path=&name=folder2', '');
        $I->seeResponseCodeIs(200);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_folders');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains('Pictures', ['data']);
        $I->assertJsonSubResponseEquals('folder1', ['children', 0, 'data']);
        $I->assertJsonSubResponseEquals('folder2', ['children', 1, 'data']);
        $I->assertJsonSubResponseEquals('folder21', ['children', 2, 'data']);
        $I->assertJsonSubResponseEquals('folder22', ['children', 3, 'data']);

        // renaming
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=rename_folder&path=/folder21&name=somenewname', '/folder21');
        $I->seeResponseCodeIs(200);
        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_folders');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains('Pictures', ['data']);
        $I->assertJsonSubResponseEquals('somenewname', ['children', 3, 'data']);

        // Remove all
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=delete_folder&path=/folder1', '/folder1');
        $I->seeResponseCodeIs(200);
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=delete_folder&path=/folder2', '/folder2');
        $I->seeResponseCodeIs(200);
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=delete_folder&path=/somenewname', '/somenewname');
        $I->seeResponseCodeIs(200);
        $this->sendRequestWithFolderToken($I, 'https://localhost/_admin/ajax.php?action=delete_folder&path=/folder22', '/folder22');
        $I->seeResponseCodeIs(200);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_folders');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains('Pictures', ['data']);
        $I->assertJsonSubResponseEquals([], ['children']);
    }

    /**
     * @throws \JsonException
     */
    public function testReserveImageUpload(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->seeResponseCodeIs(200);

        $reserve = $this->reserveImage($I, '', 'reserved.png');
        $I->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/D', (string)($reserve['name'] ?? ''));
        $I->assertEquals('', $reserve['dir'] ?? null);
        $I->assertArrayHasKey('token', $reserve);
        $I->assertStringContainsString('/' . $reserve['name'], $reserve['file_path'] ?? '');

        $tempFilename = $this->createTemporaryPng();

        $I->sendPost(
            'https://localhost/_admin/ajax.php?action=upload',
            [
                'dir'        => $reserve['dir'],
                'token'      => 'wrong-token',
                'name'       => $reserve['name'],
                'csrf_token' => $this->getFolderCsrfToken($I, $reserve['dir']),
            ],
            ['pictures' => [
                new UploadedFile($tempFilename, $reserve['name'], 'image/png', null, true),
            ]]
        );
        $I->seeResponseCodeIs(422);
        $I->assertJsonSubResponseEquals('Reserve token mismatch.', ['message']);

        $I->sendPost(
            'https://localhost/_admin/ajax.php?action=upload',
            [
                'dir'        => $reserve['dir'],
                'token'      => $reserve['token'],
                'name'       => $reserve['name'],
                'csrf_token' => $this->getFolderCsrfToken($I, $reserve['dir']),
            ],
            ['pictures' => [
                new UploadedFile($tempFilename, $reserve['name'], 'image/png', null, true),
            ]]
        );
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals(true, ['success']);
        $I->assertJsonSubResponseContains($reserve['name'], ['file_path']);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=load_files&path=');
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseContains($reserve['name'], [0, 'data', 'title']);
    }

    /**
     * @dataProvider reserveImageInvalidProvider
     * @throws \JsonException
     */
    public function testReserveImageInvalidRequests(\IntegrationTester $I, Example $example): void
    {
        $I->login('author', 'author');
        $I->seeResponseCodeIs(200);

        $postData = $example['postData'];
        $pathForToken = $postData['dir'] ?? '';
        if ($pathForToken !== '' && (str_contains($pathForToken, '..') || str_contains($pathForToken, "\0"))) {
            $postData['csrf_token'] = 'dummy';
        } else {
            $postData['csrf_token'] = $this->getFolderCsrfToken($I, $pathForToken);
        }

        $I->sendPost('https://localhost/_admin/ajax.php?action=reserve_image', $postData);
        $I->seeResponseCodeIs($example['statusCode']);
        $I->assertJsonSubResponseEquals($example['message'], ['message']);
    }

    /**
     * @return array<string, array<string, array<string, string>|int|string>>
     */
    public function reserveImageInvalidProvider(): array
    {
        return [
            'missing_dir' => [
                'postData' => ['name' => 'test.png'],
                'statusCode' => 422,
                'message' => 'Parameters "dir" and "name" are required.',
            ],
            'missing_name' => [
                'postData' => ['dir' => '/'],
                'statusCode' => 422,
                'message' => 'Parameters "dir" and "name" are required.',
            ],
            'invalid_dir_traversal' => [
                'postData' => ['dir' => '..', 'name' => 'test.png'],
                'statusCode' => 422,
                'message' => 'Invalid dir.',
            ],
            'invalid_dir_nullbyte' => [
                'postData' => ['dir' => "bad\0dir", 'name' => 'test.png'],
                'statusCode' => 422,
                'message' => 'Invalid dir.',
            ],
            'empty_name' => [
                'postData' => ['dir' => '/', 'name' => ''],
                'statusCode' => 422,
                'message' => 'Invalid file name.',
            ],
        ];
    }

    /**
     * @throws \JsonException
     */
    public function testReserveImageGeneratesUniqueServerNames(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->seeResponseCodeIs(200);

        $firstReserve = $this->reserveImage($I, '', 'sample.png');
        $secondReserve = $this->reserveImage($I, '', 'sample.png');

        $I->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/D', (string)($firstReserve['name'] ?? ''));
        $I->assertMatchesRegularExpression('/^[a-f0-9]{32}\.png$/D', (string)($secondReserve['name'] ?? ''));
        $I->assertNotSame($firstReserve['name'], $secondReserve['name']);
    }

    /**
     * @throws \JsonException
     */
    public function testUploadWithReservedNameMultipleFiles(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->seeResponseCodeIs(200);

        $reserve = $this->reserveImage($I, '', 'multi.png');

        $temp1 = $this->createTemporaryPng();
        $temp2 = $this->createTemporaryPng();

        $I->sendPost(
            'https://localhost/_admin/ajax.php?action=upload',
            [
                'dir'        => $reserve['dir'],
                'token'      => $reserve['token'],
                'name'       => $reserve['name'],
                'csrf_token' => $this->getFolderCsrfToken($I, $reserve['dir']),
            ],
            ['pictures' => [
                new UploadedFile($temp1, 'one.png', 'image/png', null, true),
                new UploadedFile($temp2, 'two.png', 'image/png', null, true),
            ]]
        );
        $I->seeResponseCodeIs(422);
        $I->assertJsonSubResponseEquals('Only one file can be uploaded with a reserved name.', ['message']);
    }

    /**
     * @throws \JsonException
     */
    public function testExpiredReserveIsCleared(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->seeResponseCodeIs(200);

        $path = '';
        $name = 'expired.png';
        $token = 'expired-token';
        $reserveFile = $this->getReserveFilePath($path, $name);
        $reserveDir = \dirname($reserveFile);
        if (!is_dir($reserveDir) && !mkdir($reserveDir, 0700, true) && !is_dir($reserveDir)) {
            throw new \RuntimeException(\sprintf('Directory "%s" was not created', $reserveDir));
        }

        file_put_contents($reserveFile, json_encode([
            'token'      => $token,
            'path'       => $path,
            'name'       => $name,
            'expires_at' => time() - 10,
        ], JSON_THROW_ON_ERROR));

        $tempFilename = $this->createTemporaryPng();

        $I->sendPost(
            'https://localhost/_admin/ajax.php?action=upload',
            [
                'dir'        => $path,
                'token'      => $token,
                'name'       => $name,
                'csrf_token' => $this->getFolderCsrfToken($I, $path),
            ],
            ['pictures' => [
                new UploadedFile($tempFilename, $name, 'image/png', null, true),
            ]]
        );
        $I->seeResponseCodeIs(422);
        $I->assertJsonSubResponseEquals('Reserve token mismatch.', ['message']);
        $I->assertFalse(is_file($reserveFile));
    }

    /**
     * @throws \JsonException
     */
    public function testInvalidCreateFolder(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->seeResponseCodeIs(200);

        $I->sendPost('https://localhost/_admin/ajax.php?action=create_subfolder', ['csrf_token' => $this->getFolderCsrfToken($I, '')]);
        $I->seeResponseCodeIs(400);
        $I->assertJsonSubResponseContains('Parameters "path" and "name" are required.', ['message']);
    }

    public function testInvalidRenameFolder(\IntegrationTester $I): void
    {
        $I->login('editor', 'editor');
        $I->seeResponseCodeIs(200);

        $I->amOnPage('https://localhost/_admin/ajax.php?action=rename_file&path=/test1.png&name=test1.php');
        $I->seeResponseCodeIs(405);
        $I->assertJsonSubResponseEquals('Only POST requests are allowed.', ['message']);

        $I->sendPost('https://localhost/_admin/ajax.php?action=rename_file&path=/test1.png', ['csrf_token' => 'dummy']);
        $I->seeResponseCodeIs(400);
        $I->assertJsonSubResponseEquals('Parameters "path" and "name" are required.', ['message']);

        $I->sendPost('https://localhost/_admin/ajax.php?action=rename_file&path=..&name=test1.php', ['csrf_token' => 'dummy']);
        $I->seeResponseCodeIs(400);
        $I->assertJsonSubResponseEquals('Invalid path.', ['message']);

        $I->sendPost('https://localhost/_admin/ajax.php?action=rename_file&path=/test1&name=./test1.php', ['csrf_token' => 'dummy']);
        $I->seeResponseCodeIs(400);
        $I->assertJsonSubResponseEquals('Invalid name.', ['message']);
    }

    /**
     * @dataProvider pictureCsrfTokenInvalidProvider
     */
    public function testPictureCsrfTokenInvalidRequests(\IntegrationTester $I, Example $example): void
    {
        $I->login('author', 'author');
        $I->seeResponseCodeIs(200);

        $I->sendPost('https://localhost/_admin/ajax.php?action=picture_csrf_token', $example['postData']);
        $I->seeResponseCodeIs($example['statusCode']);
        $I->assertJsonSubResponseEquals($example['message'], ['message']);
    }

    /**
     * @return array<string, array<array<string, mixed>, mixed>>
     */
    public function pictureCsrfTokenInvalidProvider(): array
    {
        return [
            'missing_path' => [
                'postData' => [],
                'statusCode' => 400,
                'message' => 'Parameter "path" is required.',
            ],
            'invalid_path_traversal' => [
                'postData' => ['path' => '..'],
                'statusCode' => 400,
                'message' => 'Invalid path.',
            ],
            'invalid_path_nullbyte' => [
                'postData' => ['path' => "bad\0dir"],
                'statusCode' => 400,
                'message' => 'Invalid path.',
            ],
        ];
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it    = new \RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * @throws \JsonException
     */
    private function uploadSimplePngFile(\IntegrationTester $I, string $path, string $fileName): string
    {
        $tempFilename = $this->createTemporaryPng();

        $I->sendPost(
            'https://localhost/_admin/ajax.php?action=upload',
            [
                'dir'        => $path,
                'csrf_token' => $this->getFolderCsrfToken($I, $path),
            ],
            ['pictures' => [
                new UploadedFile($tempFilename, $fileName, 'image/png', null, true),
            ]]
        );
        $I->seeResponseCodeIs(200);
        $I->assertJsonSubResponseEquals(true, ['success']);

        $response = $I->grabJson();
        $storedPath = (string)($response['file_path'] ?? '');
        $storedName = basename($storedPath);
        if ($storedName === '') {
            $I->fail('The upload response did not contain a stored file name.');
        }

        return $storedName;
    }

    /**
     * @throws \JsonException
     * @return array<string, mixed>
     */
    private function reserveImage(\IntegrationTester $I, string $path, string $name): array
    {
        $I->sendPost(
            'https://localhost/_admin/ajax.php?action=reserve_image',
            [
                'dir'        => $path,
                'name'       => $name,
                'csrf_token' => $this->getFolderCsrfToken($I, $path),
            ]
        );
        $I->seeResponseCodeIs(200);

        $response = $I->grabJson();
        if (!isset($response['success']) || $response['success'] !== true) {
            $I->fail('Reserve image request failed.');
        }

        return $response;
    }

    private function getReserveFilePath(string $path, string $name): string
    {
        $reserveRoot = __DIR__ . '/../../_cache/test/picture_reserve';
        $safePath = ltrim($path, '/');
        $dir = $reserveRoot . ($safePath !== '' ? '/' . $safePath : '');

        return $dir . '/' . $name . '.json';
    }

    /**
     * @throws \JsonException
     * @param array<string, mixed> $data
     * @param array<string, list<UploadedFile>> $files
     */
    private function sendRequestWithFolderToken(\IntegrationTester $I, string $url, string $pathForToken, array $data = [], array $files = []): void
    {
        $data['csrf_token'] = $this->getFolderCsrfToken($I, $pathForToken);
        $I->sendPost($url, $data, $files);
    }

    /**
     * @throws \JsonException
     * @param array<string, mixed> $data
     */
    private function sendMoveRequestWithTokens(\IntegrationTester $I, string $url, string $sourcePath, string $destinationPath, array $data = []): void
    {
        $data['csrf_token'] = $this->getFolderCsrfToken($I, $sourcePath);
        $data['destination_csrf_token'] = $this->getFolderCsrfToken($I, $destinationPath);
        $I->sendPost($url, $data);
    }

    private function createTemporaryPng(): string
    {
        $filename = tempnam(sys_get_temp_dir(), 's2_test_image_');
        if ($filename === false) {
            throw new \RuntimeException('Unable to allocate a temporary image file.');
        }

        $contents = base64_decode(self::ONE_PIXEL_PNG, true);
        if ($contents === false || file_put_contents($filename, $contents) === false) {
            throw new \RuntimeException('Unable to create a temporary image fixture.');
        }

        return $filename;
    }

    private function createTemporaryMedia(string $extension): string
    {
        $filename = tempnam(sys_get_temp_dir(), 'register_media_');
        if ($filename === false) {
            throw new \RuntimeException('Unable to allocate a temporary media fixture.');
        }

        if ($extension === 'avif' || $extension === 'webp') {
            $image = imagecreatetruecolor(1, 1);
            if (!$image instanceof \GdImage) {
                throw new \RuntimeException('Unable to create a temporary image fixture.');
            }

            $written = $extension === 'avif'
                ? imageavif($image, $filename)
                : imagewebp($image, $filename);
        } else {
            $contents = match ($extension) {
                'mov'  => pack('N', 20) . 'ftypqt  ' . pack('N', 0) . 'qt  ',
                'webm' => hex2bin('1A45DFA39F4286810142F7810142F2810442F381084282847765626D4287810442858102'),
                default => false,
            };
            $written = \is_string($contents) && file_put_contents($filename, $contents) !== false;
        }

        if (!$written) {
            unlink($filename);
            throw new \RuntimeException('Unable to create a temporary media fixture.');
        }

        return $filename;
    }

    /**
     * @throws \JsonException
     */
    private function getFolderCsrfToken(\IntegrationTester $I, string $path): string
    {
        $I->sendPost('https://localhost/_admin/ajax.php?action=picture_csrf_token', ['path' => $path]);
        $I->seeResponseCodeIs(200);

        $response = $I->grabJson();
        if (!isset($response['csrf_token'])) {
            $I->fail('CSRF token was not returned.');
        }

        return $response['csrf_token'];
    }
}
