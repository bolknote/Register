<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http;

use Codeception\Test\Unit;
use Register\Http\TrustedScriptNonceInjector;
use Symfony\Component\HttpFoundation\Response;

final class TrustedScriptNonceInjectorTest extends Unit
{
    private const string NONCE = 'AbCdEfGhIjKlMnOpQrStUvWx';

    public function testGrantsNonceOnlyToScriptsAndConverterOwnedStylesInsideTrustedPostBodies(): void
    {
        $trustedHtml = TrustedScriptNonceInjector::markTrustedHtml(<<<'HTML'
<p>Trusted body</p>
<!-- <script>window.commentMustStayInert = true;</script> -->
<script nonce="stale" data-value=">">window.inlineRan = "<script>";</script>
<script nonce="stale" src="/_assets/trusted.js"></script>
<style nonce="stale">.historical-frame { border: 1px dotted #ccc; }</style>
<style nonce="stale" data-register-imported-inline-styles>.register-import-style-a1 { color: red; }</style>
HTML);
        $response = new Response(
            '<script>window.outsideMustStayInert = true;</script>'
                . '<style>.outside-must-stay-inert { color: red; }</style>'
                . '<article>' . $trustedHtml . '</article>',
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );

        $count = (new TrustedScriptNonceInjector())->injectIntoResponse($response, self::NONCE);
        $html  = $response->getContent();

        self::assertSame(2, $count);
        self::assertIsString($html);
        self::assertStringContainsString(
            '<script nonce="' . self::NONCE . '" data-value=">">window.inlineRan = "<script>";</script>',
            $html,
        );
        self::assertStringContainsString(
            '<script src="/_assets/trusted.js"></script>',
            $html,
        );
        self::assertStringContainsString(
            '<style nonce="' . self::NONCE . '" data-register-imported-inline-styles>'
                . '.register-import-style-a1 { color: red; }</style>',
            $html,
        );
        self::assertStringNotContainsString('.historical-frame', $html);
        self::assertStringContainsString(
            '<script>window.outsideMustStayInert = true;</script>',
            $html,
        );
        self::assertStringContainsString(
            '<style>.outside-must-stay-inert { color: red; }</style>',
            $html,
        );
        self::assertStringContainsString(
            '<!-- <script>window.commentMustStayInert = true;</script> -->',
            $html,
        );
        self::assertStringNotContainsString('nonce="stale"', $html);
        self::assertStringNotContainsString('register-trusted-script-region', $html);
    }

    public function testHandlesSeveralTrustedBodiesAndTagNameCase(): void
    {
        $response = new Response(
            TrustedScriptNonceInjector::markTrustedHtml('<SCRIPT>one()</SCRIPT>')
            . TrustedScriptNonceInjector::markTrustedHtml('<scripture>text</scripture><script>two()</script>'),
        );

        $count = (new TrustedScriptNonceInjector())->injectIntoResponse($response, self::NONCE);
        $html  = $response->getContent();

        self::assertSame(2, $count);
        self::assertIsString($html);
        self::assertStringContainsString('<SCRIPT nonce="' . self::NONCE . '">one()</SCRIPT>', $html);
        self::assertStringContainsString('<scripture>text</scripture>', $html);
        self::assertStringContainsString('<script nonce="' . self::NONCE . '">two()</script>', $html);
    }

    public function testOnlyRemovesTrustMarkersInsideJsonResponses(): void
    {
        $marked   = TrustedScriptNonceInjector::markTrustedHtml('<script>run()</script>');
        $response = new Response($marked, Response::HTTP_OK, ['Content-Type' => 'application/json']);

        $count = (new TrustedScriptNonceInjector())->injectIntoResponse($response, self::NONCE);

        self::assertSame(0, $count);
        self::assertSame('<script>run()</script>', $response->getContent());
    }
}
