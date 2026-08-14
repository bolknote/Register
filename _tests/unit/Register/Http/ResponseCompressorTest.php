<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http;

use Codeception\Test\Unit;
use Register\Http\ResponseCompressor;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResponseCompressorTest extends Unit
{
    public function testPrefersBrotliWhenBothEncodingsHaveEqualQuality(): void
    {
        $response   = $this->response();
        $compressor = $this->compressor();

        self::assertTrue($compressor->compress($this->request('gzip, br'), $response));
        self::assertSame('br', $response->headers->get('Content-Encoding'));
        self::assertSame('brotli:Compress me', $response->getContent());
        self::assertContains('Accept-Encoding', $response->getVary());
    }

    public function testHonorsQualityAndExplicitlyDisabledEncodings(): void
    {
        $response = $this->response();

        self::assertTrue($this->compressor()->compress($this->request('br;q=0.5, gzip;q=1'), $response));
        self::assertSame('gzip', $response->headers->get('Content-Encoding'));
        self::assertSame('gzip:Compress me', $response->getContent());

        $response = $this->response();
        self::assertFalse($this->compressor()->compress($this->request('br;q=0, gzip;q=0'), $response));
        self::assertFalse($response->headers->has('Content-Encoding'));
        self::assertContains('Accept-Encoding', $response->getVary());
    }

    public function testUsesWildcardWithoutOverridingAnExplicitRejection(): void
    {
        $response = $this->response();

        self::assertTrue($this->compressor()->compress($this->request('*;q=0.5, br;q=0'), $response));
        self::assertSame('gzip', $response->headers->get('Content-Encoding'));
    }

    public function testDoesNotTransformAnEncodedOrBinaryResponse(): void
    {
        $encoded = $this->response();
        $encoded->headers->set('Content-Encoding', 'custom');
        self::assertFalse($this->compressor()->compress($this->request('br, gzip'), $encoded));
        self::assertSame('Compress me', $encoded->getContent());

        $binary = new Response('binary', headers: ['Content-Type' => 'image/jpeg']);
        self::assertFalse($this->compressor()->compress($this->request('br, gzip'), $binary));
        self::assertSame('binary', $binary->getContent());
    }

    public function testLeavesCompressionToAnExistingPhpOutputHandler(): void
    {
        $response   = $this->response();
        $compressor = new ResponseCompressor(
            static fn(string $content): string => 'brotli:' . $content,
            static fn(string $content): string => 'gzip:' . $content,
            true,
        );

        self::assertFalse($compressor->compress($this->request('br, gzip'), $response));
        self::assertFalse($compressor->canSetContentLength());
        self::assertSame('Compress me', $response->getContent());
    }

    private function compressor(): ResponseCompressor
    {
        return new ResponseCompressor(
            static fn(string $content): string => 'brotli:' . $content,
            static fn(string $content): string => 'gzip:' . $content,
        );
    }

    private function request(string $acceptEncoding): Request
    {
        return Request::create('/', server: ['HTTP_ACCEPT_ENCODING' => $acceptEncoding]);
    }

    private function response(): Response
    {
        return new Response('Compress me', headers: ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
