<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Image;

use PHPUnit\Framework\TestCase;
use Register\Core\Image\SvgImageInfo;

final class SvgImageInfoTest extends TestCase
{
    public function testReadsAuthoredDimensionsWithoutChangingTheOriginal(): void
    {
        foreach ([
            ['width="640" height="320"', 640, 320],
            ['viewBox="-10 -20 640 320"', 640, 320],
            ['width="50%" height="100%" viewBox="0,0,640,320"', 640, 320],
            ['width="320" viewBox="0 0 640 320"', 320, 160],
            ['height="160" viewBox="0 0 640 320"', 320, 160],
            ['width="2in" height="25.4mm"', 192, 96],
            ['width="2.54cm" height="72pt"', 96, 96],
            ['width="6pc" height="101.6q"', 96, 96],
            ['width="320.5px" height="160.5px"', 321, 161],
            ['', 300, 150],
        ] as [$attributes, $width, $height]) {
            $svg = '<svg xmlns="http://www.w3.org/2000/svg" ' . $attributes . '><style>text{fill:red}</style>'
                . '<script>window.authored=true;</script><text>Hello</text></svg>';
            $this->withFile($svg, static function (string $path) use ($svg, $width, $height): void {
                self::assertSame([0 => $width, 1 => $height, 'mime' => 'image/svg+xml'], SvgImageInfo::read($path));
                self::assertSame($svg, file_get_contents($path));
            });
        }
    }

    public function testRejectsBrokenXmlWrongRootsAndInvalidGeometry(): void
    {
        foreach (['plain text', '<html/>', '<svg/>', '<svg xmlns="http://www.w3.org/2000/svg"><g></svg>',
            '<svg xmlns="http://www.w3.org/2000/svg" width="0" height="20"/>',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 -10 20"/>'] as $svg) {
            $this->withFile($svg, static function (string $path): void {
                self::assertNull(SvgImageInfo::read($path));
            });
        }
    }

    public function testReadsNamespacedSvgAndDoesNotLoadExternalDtd(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg SYSTEM "file:///nonexistent-register-svg-dtd">'
            . '<s:svg xmlns:s="http://www.w3.org/2000/svg" width="40" height="20"/>';
        $this->withFile($svg, static function (string $path): void {
            self::assertSame([0 => 40, 1 => 20, 'mime' => 'image/svg+xml'], SvgImageInfo::read($path));
        });
    }

    public function testReadsUtf16WithoutConvertingTheStoredFile(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-16"?><svg xmlns="http://www.w3.org/2000/svg" width="320" height="160"/>';
        $encoded = mb_convert_encoding($xml, 'UTF-16LE', 'UTF-8');
        self::assertIsString($encoded);
        $bytes = "\xff\xfe" . $encoded;
        $this->withFile($bytes, static function (string $path) use ($bytes): void {
            self::assertSame([0 => 320, 1 => 160, 'mime' => 'image/svg+xml'], SvgImageInfo::read($path));
            self::assertSame($bytes, file_get_contents($path));
        });
    }

    /** @param \Closure(string): void $assert */
    private function withFile(string $contents, \Closure $assert): void
    {
        $path = tempnam(sys_get_temp_dir(), 'register-svg-info-');
        self::assertIsString($path);
        file_put_contents($path, $contents);
        try {
            $assert($path);
        } finally {
            unlink($path);
        }
    }
}
