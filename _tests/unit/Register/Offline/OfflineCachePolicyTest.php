<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Offline;

use Codeception\Test\Unit;
use Register\Offline\OfflineCachePolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class OfflineCachePolicyTest extends Unit
{
    public function testAllowsInitialSeedOnlyForAnonymousPlainGetPages(): void
    {
        self::assertTrue(OfflineCachePolicy::allowsInitialSeed(Request::create('/post'), false));
        self::assertFalse(OfflineCachePolicy::allowsInitialSeed(Request::create('/post'), true));
        self::assertFalse(OfflineCachePolicy::allowsInitialSeed(Request::create('/post', Request::METHOD_POST), false));
        self::assertFalse(OfflineCachePolicy::allowsInitialSeed(Request::create('/post?preview=1'), false));
        self::assertFalse(OfflineCachePolicy::allowsInitialSeed(Request::create('/comment_sent'), false));
        self::assertFalse(OfflineCachePolicy::allowsInitialSeed(Request::create('/comment_unsubscribe'), false));
    }

    public function testMarksAnonymousSuccessfulHtmlGetResponse(): void
    {
        $request = Request::create('/post');
        $response = new Response('<!doctype html>', headers: ['Content-Type' => 'text/html; charset=utf-8']);

        OfflineCachePolicy::apply($request, $response, false);

        self::assertSame('public', $response->headers->get(OfflineCachePolicy::HEADER_NAME));
    }

    public function testRejectsAuthenticatedAndSensitiveResponses(): void
    {
        $cases = [
            [Request::create('/post'), new Response('', headers: ['Content-Type' => 'text/html']), true],
            [Request::create('/post', Request::METHOD_POST), new Response('', headers: ['Content-Type' => 'text/html']), false],
            [Request::create('/post'), new Response('', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/html']), false],
            [Request::create('/feed'), new Response('', headers: ['Content-Type' => 'application/json']), false],
            [
                Request::create('/private'),
                new Response('', headers: ['Content-Type' => 'text/html', 'Cache-Control' => 'private, no-store']),
                false,
            ],
        ];

        foreach ($cases as [$request, $response, $authenticated]) {
            $response->headers->set(OfflineCachePolicy::HEADER_NAME, OfflineCachePolicy::HEADER_VALUE);

            OfflineCachePolicy::apply($request, $response, $authenticated);

            self::assertFalse($response->headers->has(OfflineCachePolicy::HEADER_NAME));
        }
    }
}
