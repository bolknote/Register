<?php

declare(strict_types = 1);

namespace integration;

use Register\Module\Blog\Inplace\PostMediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class HdrMediaCest
{
    public function optimizedHdrJpegKeepsItsGainMapAndNormalRetinaDimensions(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $repository = $I->grabService(PostMediaRepository::class);
        foreach ([['hdr-retina.jpg', 2000, 1125, true], ['hdr-portrait.jpg', 18, 32, false]] as [$fixture, $width, $height, $retina]) {
            $file = tempnam(sys_get_temp_dir(), 'register-hdr-upload-');
            $I->assertIsString($file);
            $bytes = file_get_contents(dirname(__DIR__) . '/_resources/image-optimizer/' . $fixture);
            $I->assertIsString($bytes);
            $I->assertStringContainsString("urn:iso:std:iso:ts:21496:-1\0", $bytes);
            $I->assertStringNotContainsString("Exif\0\0", $bytes);
            file_put_contents($file, $bytes);
            $storedFile = null;
            try {
                $I->sendPost('https://localhost/_inplace/post/new', [
                    'inplace_action' => 'media', 'inplace_token' => $token, 'published_at' => (string)(time() - 60),
                    'media_retina' => $retina ? '1' : '0', 'media_width' => (string)$width, 'media_height' => (string)$height,
                    'media_display_width' => (string)($retina ? (int)floor($width / 2) : $width),
                    'media_display_height' => (string)($retina ? (int)floor($height / 2) : $height),
                ], ['media' => new UploadedFile($file, 'image.jpg', 'image/jpeg', null, true)]);
                $I->seeResponseCodeIs(200);
                $data = json_decode($I->grabResponse(), true, 512, JSON_THROW_ON_ERROR);
                $media = $repository->find((int)$data['media_id']);
                $I->assertNotNull($media);
                $I->assertSame($width, $data['pixel_width']);
                $I->assertSame($height, $data['pixel_height']);
                $I->assertSame($retina ? (int)floor($width / 2) : $width, $data['width']);
                $I->assertSame($retina ? (int)floor($height / 2) : $height, $data['height']);
                $storedFile = dirname(__DIR__) . '/_output/images' . $media['storage_path'];
                $I->assertSame($bytes, file_get_contents($storedFile));
                $I->assertSame($retina, $data['retina']);
            } finally {
                if (is_file($file)) {
                    unlink($file);
                }

                if ($storedFile !== null && is_file($storedFile)) {
                    unlink($storedFile);
                }
            }
        }
    }
}
