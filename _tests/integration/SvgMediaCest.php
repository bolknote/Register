<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Admin\Picture\PictureManager;
use Register\Module\Blog\Inplace\PostMediaRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class SvgMediaCest
{
    public function authorUploadsRetainVectorBytesAndDimensions(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        $repository = $I->grabService(PostMediaRepository::class);
        $library = $I->grabAdminService(PictureManager::class);
        foreach ([['width="640" height="320"', 640, 320], ['viewBox="0 0 2400 1200"', 2400, 1200],
            ['width="2in" height="1in"', 192, 96], ['viewBox="0 0 320 160"', 320, 160]] as $index => [$attributes, $width, $height]) {
            $bytes = '<svg xmlns="http://www.w3.org/2000/svg" ' . $attributes . ' onload="authored()">'
                . '<style>text{font:20px monospace;fill:#c30}</style><script>function authored(){}</script>'
                . '<text x="10" y="30">Vector illustration</text></svg>';
            if ($index === 3) {
                $bytes = "\xff\xfe" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?>' . $bytes, 'UTF-16LE', 'UTF-8');
            }

            $file = tempnam(sys_get_temp_dir(), 'register-svg-upload-');
            $I->assertIsString($file);
            file_put_contents($file, $bytes);
            $stored = null;
            try {
                $I->sendPost('https://localhost/_inplace/post/new', [
                    ...$this->parameters($token, $width, $height), 'published_at' => (string)(time() - $index * 86400),
                ], [
                    'media' => new UploadedFile($file, 'drawing.svg', 'image/svg+xml', null, true),
                ]);
                $I->seeResponseCodeIs(200);
                $data = $I->grabJson();
                $I->assertIsArray($data);
                $I->assertSame('image', $data['kind']);
                $I->assertSame($width, $data['width']);
                $I->assertSame($height, $data['height']);
                $I->assertFalse($data['retina']);
                $I->assertStringEndsWith('.svg', (string)parse_url($data['url'], PHP_URL_PATH));
                $media = $repository->find((int)$data['media_id']);
                $I->assertNotNull($media);
                $I->assertSame('image/svg+xml', $media['mime_type']);
                $stored = dirname(__DIR__) . '/_output/images' . $media['storage_path'];
                $I->assertSame($bytes, file_get_contents($stored));
                $entries = $library->getFiles(dirname($media['storage_path']));
                $entry = array_values(array_filter($entries, static fn(array $entry): bool => ($entry['attr']['data-fname'] ?? '') === basename($media['storage_path'])))[0];
                $I->assertSame($width . '*' . $height, $entry['attr']['data-dim']);
                $preview = $library->getThumbnailResponse($media['storage_path']);
                $I->assertSame('image/svg+xml', $preview->headers->get('Content-Type'));
                ob_start();
                try {
                    $preview->sendContent();
                    $previewBytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                }

                $I->assertSame($bytes, $previewBytes);
            } finally {
                foreach ([$file, $stored] as $path) {
                    if ($path !== null && is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }
    }

    public function uploadsValidateSvgFormatAndGeometryWithoutTrustingClientMetadata(\IntegrationTester $I): void
    {
        $I->login('author', 'author');
        $I->amOnPage('https://localhost/');

        $token = (string)$I->grabAttributeFrom('.post-create-template input[name="inplace_token"]', 'value');
        foreach ([
            ['<svg xmlns="http://www.w3.org/2000/svg" width="640" height="320"/>', ['media_width' => '639'], 422],
            ['<svg xmlns="http://www.w3.org/2000/svg" width="640" height="320"/>', ['media_retina' => '1'], 422],
            ['<svg xmlns="http://www.w3.org/2000/svg"><g></svg>', [], 415],
            ['<html><body>Not SVG</body></html>', [], 415],
        ] as [$bytes, $overrides, $status]) {
            $file = tempnam(sys_get_temp_dir(), 'register-invalid-svg-');
            $I->assertIsString($file);
            file_put_contents($file, $bytes);
            try {
                $I->sendPost('https://localhost/_inplace/post/new', array_replace($this->parameters($token, 640, 320), $overrides), [
                    'media' => new UploadedFile($file, 'drawing.svg', 'image/svg+xml', null, true),
                ]);
                $I->seeResponseCodeIs($status);
            } finally {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    /** @return array<string, string> */
    private function parameters(string $token, int $width, int $height): array
    {
        return ['inplace_action' => 'media', 'inplace_token' => $token, 'media_retina' => '0',
            'media_width' => (string)$width, 'media_height' => (string)$height,
            'media_display_width' => (string)$width, 'media_display_height' => (string)$height];
    }
}
