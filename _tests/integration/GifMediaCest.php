<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Module\Blog\Inplace\PostMediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class GifMediaCest
{
    public function gifUploadPreservesTheCompleteOriginalAndItsNaturalDimensions(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $repository = $I->grabService(PostMediaRepository::class);
        foreach ([['animated.gif', 96], ['static.gif', 96], ['animated-wide.gif', 2400]] as $index => [$fixture, $width]) {
            $bytes = file_get_contents(dirname(__DIR__) . '/_resources/image-optimizer/' . $fixture);
            $I->assertIsString($bytes);
            $file = tempnam(sys_get_temp_dir(), 'register-gif-upload-');
            $I->assertIsString($file);
            file_put_contents($file, $bytes);
            $storedFile = null;
            try {
                $I->sendPost('https://localhost/_inplace/post/new', [
                    'inplace_action' => 'media', 'inplace_token' => $token,
                    'published_at' => (string)(time() - $index * 86400),
                    'media_retina' => '0', 'media_width' => (string)$width, 'media_height' => '48',
                    'media_display_width' => (string)$width, 'media_display_height' => '48',
                ], ['media' => new UploadedFile($file, 'image.gif', 'image/gif', null, true)]);
                $I->seeResponseCodeIs(200);
                $data = $I->grabJson();
                $I->assertIsArray($data);
                $media = $repository->find((int)$data['media_id']);
                $I->assertNotNull($media);
                $I->assertSame('image/gif', $media['mime_type']);
                $I->assertSame($width, $data['width']);
                $I->assertSame(48, $data['height']);
                $I->assertSame($width, $data['pixel_width']);
                $I->assertFalse($data['retina']);
                $storedFile = dirname(__DIR__) . '/_output/images' . $media['storage_path'];
                $I->assertSame($bytes, file_get_contents($storedFile));
            } finally {
                foreach ([$file, $storedFile] as $path) {
                    if ($path !== null && is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }
    }

    public function gifUploadStillRejectsMismatchedDimensionsAndAnIncorrectRetinaFlag(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $bytes = file_get_contents(dirname(__DIR__) . '/_resources/image-optimizer/animated.gif');
        $I->assertIsString($bytes);
        foreach ([['media_width' => '95'], ['media_display_width' => '95'], ['media_height' => '47'],
            ['media_display_height' => '47'], ['media_retina' => '1']] as $overrides) {
            $file = tempnam(sys_get_temp_dir(), 'register-gif-upload-');
            $I->assertIsString($file);
            file_put_contents($file, $bytes);
            try {
                $I->sendPost('https://localhost/_inplace/post/new', array_replace([
                    'inplace_action' => 'media', 'inplace_token' => $token,
                    'media_retina' => '0', 'media_width' => '96', 'media_height' => '48',
                    'media_display_width' => '96', 'media_display_height' => '48',
                ], $overrides), ['media' => new UploadedFile($file, 'image.gif', 'image/gif', null, true)]);
                $I->seeResponseCodeIs(422);
            } finally {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
