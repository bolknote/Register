<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\VisitorIdentity;

use Codeception\Test\Unit;
use Register\Module\VisitorIdentity\JsonMutationGuard;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class JsonMutationGuardTest extends Unit
{
    public function testRequiresJsonAndRejectsCrossOriginRequests(): void
    {
        $plain = Request::create('https://example.test/_visitor/resolve', Request::METHOD_POST);
        self::assertSame(
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE,
            $this->guard()->violation($plain)?->getStatusCode(),
        );

        $foreign = $this->jsonRequest([
            'HTTP_ORIGIN'         => 'https://attacker.test',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->guard()->violation($foreign)?->getStatusCode());
    }

    public function testBrowserEvidenceCanBeRequiredForPublicIdentityIssuance(): void
    {
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->guard()->violation($this->jsonRequest(), requireBrowserEvidence: true)?->getStatusCode(),
        );

        $origin = $this->jsonRequest(['HTTP_ORIGIN' => 'https://example.test']);
        self::assertNull($this->guard()->violation($origin, requireBrowserEvidence: true));

        $fetchMetadata = $this->jsonRequest(['HTTP_SEC_FETCH_SITE' => 'same-origin']);
        self::assertNull($this->guard()->violation($fetchMetadata, requireBrowserEvidence: true));
    }

    /** @param array<string, string> $server */
    private function jsonRequest(array $server = []): Request
    {
        return Request::create(
            'https://example.test/_visitor/resolve',
            Request::METHOD_POST,
            server: ['CONTENT_TYPE' => 'application/json'] + $server,
            content: '{}',
        );
    }

    private function guard(): JsonMutationGuard
    {
        return new JsonMutationGuard();
    }
}
