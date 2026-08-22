<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Config;

use Codeception\Test\Unit;
use Register\Core\Config\MediaStorageConfigResolver;

final class MediaStorageConfigResolverTest extends Unit
{
    public function testKeepsTheCompatiblePublicPicturesDefault(): void
    {
        self::assertSame(
            [
                'directory' => '/srv/www/public_html/_pictures',
                'url'       => '/register/_pictures',
            ],
            MediaStorageConfigResolver::resolve('/srv/www/public_html/', null, null, '/register'),
        );
    }

    public function testSeparatesAnAbsoluteStorageDirectoryFromAnExternalUrl(): void
    {
        self::assertSame(
            [
                'directory' => '/home/account/register-media',
                'url'       => 'https://media.example.test/uploads',
            ],
            MediaStorageConfigResolver::resolve(
                '/home/account/public_html',
                '/home/account/register-media/',
                'HTTPS://MEDIA.EXAMPLE.TEST:443/uploads/',
                '',
            ),
        );
    }

    public function testAllowsAnExplicitRootRelativeMediaUrl(): void
    {
        self::assertSame(
            [
                'directory' => '/srv/www/public_html/media/files',
                'url'       => '/served-media',
            ],
            MediaStorageConfigResolver::resolve(
                '/srv/www/public_html',
                'media\\files',
                '/served-media/',
                '/register',
            ),
        );
    }

    /** @dataProvider invalidConfigurationProvider */
    public function testRejectsAmbiguousOrUnsafeConfiguration(
        string $directory,
        ?string $url,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        MediaStorageConfigResolver::resolve('/srv/www/public_html', $directory, $url, '');
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function invalidConfigurationProvider(): iterable
    {
        yield 'absolute directory without URL' => ['/srv/register-media', null];
        yield 'relative traversal' => ['../register-media', '/media'];
        yield 'plain HTTP external origin' => ['/srv/register-media', 'http://media.example.test'];
        yield 'scheme-relative origin' => ['/srv/register-media', '//media.example.test'];
        yield 'URL credentials' => ['/srv/register-media', 'https://user@example.test/media'];
        yield 'URL query' => ['/srv/register-media', 'https://media.example.test/?token=secret'];
        yield 'URL fragment' => ['/srv/register-media', '/media#fragment'];
        yield 'URL backslash' => ['/srv/register-media', 'https://media.example.test\\files'];
        yield 'invalid host' => ['/srv/register-media', 'https://media..example.test'];
        yield 'zero port' => ['/srv/register-media', 'https://media.example.test:0'];
        yield 'control character' => ["media\0files", '/media'];
    }
}
