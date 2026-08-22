<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Http;

use Codeception\Test\Unit;
use Register\Http\ContentSecurityPolicy;
use Symfony\Component\HttpFoundation\Response;

final class ContentSecurityPolicyTest extends Unit
{
    public function testAppliesEnforcedScriptAndStylePolicies(): void
    {
        $response = new Response();

        ContentSecurityPolicy::apply($response, '/blog/.well-known/csp-report');

        self::assertSame(ContentSecurityPolicy::POLICY, $response->headers->get(ContentSecurityPolicy::HEADER_NAME));
        self::assertSame(
            ContentSecurityPolicy::REPORT_ONLY_POLICY
                . '; report-uri /blog/.well-known/csp-report; report-to register-csp',
            $response->headers->get(ContentSecurityPolicy::REPORT_ONLY_HEADER_NAME),
        );
        self::assertSame(
            'register-csp="/blog/.well-known/csp-report"',
            $response->headers->get('Reporting-Endpoints'),
        );
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertSame('camera=(), microphone=(), geolocation=()', $response->headers->get('Permissions-Policy'));
        self::assertStringContainsString("script-src 'self'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("script-src-attr 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("base-uri 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("object-src 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringNotContainsString("script-src 'self' 'unsafe-inline'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("style-src 'self'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("style-src-attr 'none'", ContentSecurityPolicy::POLICY);
        self::assertStringNotContainsString("'unsafe-inline'", ContentSecurityPolicy::POLICY);
        self::assertStringContainsString("style-src 'self'", ContentSecurityPolicy::REPORT_ONLY_POLICY);
        self::assertStringContainsString("style-src-attr 'none'", ContentSecurityPolicy::REPORT_ONLY_POLICY);
        self::assertStringNotContainsString("'unsafe-inline'", ContentSecurityPolicy::REPORT_ONLY_POLICY);
        self::assertStringNotContainsString('http:', ContentSecurityPolicy::REPORT_ONLY_POLICY);
    }

    public function testAdminPagesCannotBeFramedExceptForExplicitEmbeddedEndpoints(): void
    {
        $adminResponse = new Response();
        ContentSecurityPolicy::applyToAdmin($adminResponse);

        self::assertSame(ContentSecurityPolicy::ADMIN_POLICY, $adminResponse->headers->get(ContentSecurityPolicy::HEADER_NAME));
        self::assertStringContainsString("frame-ancestors 'none'", ContentSecurityPolicy::ADMIN_POLICY);
        self::assertSame('no-store, private', $adminResponse->headers->get('Cache-Control'));

        $embeddedResponse = new Response();
        ContentSecurityPolicy::applyToEmbeddedAdmin($embeddedResponse);

        self::assertSame(ContentSecurityPolicy::POLICY, $embeddedResponse->headers->get(ContentSecurityPolicy::HEADER_NAME));
        self::assertStringContainsString("frame-ancestors 'self'", ContentSecurityPolicy::POLICY);
        self::assertSame('no-store, private', $embeddedResponse->headers->get('Cache-Control'));
    }

    public function testAddsAValidatedNonceToPublicScriptPolicies(): void
    {
        $nonce    = 'AbCdEfGhIjKlMnOpQrStUvWx';
        $response = new Response();

        ContentSecurityPolicy::apply($response, '/csp-report', $nonce);

        $enforced = $response->headers->get(ContentSecurityPolicy::HEADER_NAME);
        self::assertNotNull($enforced);
        self::assertStringContainsString("script-src 'self' 'nonce-" . $nonce . "';", $enforced);
        self::assertStringContainsString("style-src 'self' 'nonce-" . $nonce . "';", $enforced);
        self::assertStringContainsString("script-src-attr 'none';", $enforced);
        self::assertStringNotContainsString("'unsafe-inline'", $enforced);

        $reportOnly = $response->headers->get(ContentSecurityPolicy::REPORT_ONLY_HEADER_NAME);
        self::assertNotNull($reportOnly);
        self::assertStringContainsString("script-src 'self' 'nonce-" . $nonce . "';", $reportOnly);
    }

    public function testRejectsUnsafeScriptNonce(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContentSecurityPolicy::apply(new Response(), '', "bad\r\nnonce");
    }

    public function testGeneratesAHeaderSafeScriptNonce(): void
    {
        self::assertMatchesRegularExpression(
            '~\A[A-Za-z0-9+/]{24}\z~D',
            ContentSecurityPolicy::generateScriptNonce(),
        );
    }

    public function testRejectsUnsafeReportUri(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ContentSecurityPolicy::apply(new Response(), "/report\r\nX-Injected: true");
    }

    public function testServerRenderedSourcesDoNotRequireInlineJavaScript(): void
    {
        $root = dirname(__DIR__, 4);
        $paths = [
            $root . '/_admin/install.php',
            $root . '/_admin/templates',
            $root . '/_include/installation_required.php',
            $root . '/_include/src',
            $root . '/_include/views',
            $root . '/_lang',
        ];

        foreach ($this->sourceFiles($paths) as $filename) {
            $source = file_get_contents($filename);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression(
                '~<script\b(?![^>]*\bsrc\s*=)[^>]*>~i',
                $source,
                $filename . ' contains an inline script.',
            );
            self::assertDoesNotMatchRegularExpression(
                '~\son[a-z]+\s*=~i',
                $source,
                $filename . ' contains an inline event handler.',
            );
            self::assertDoesNotMatchRegularExpression(
                '~javascript\s*:~i',
                $source,
                $filename . ' contains a javascript: URL.',
            );
        }
    }

    public function testStandalonePagesDoNotRequireInlineStyles(): void
    {
        $root = dirname(__DIR__, 4);
        $files = [
            $root . '/_admin/install.php',
            $root . '/_include/installation_required.php',
            $root . '/_include/src/Framework/Application.php',
            $root . '/_include/views/error.php',
        ];

        foreach ($files as $filename) {
            $source = file_get_contents($filename);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('~<style\b~i', $source, $filename);
            self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $source, $filename);
            self::assertStringContainsString('/_assets/register/standalone.css', $source, $filename);
        }

        self::assertFileExists($root . '/_assets/register/standalone.css');
    }

    public function testRecommendationMarkupDoesNotRequireInlineStyles(): void
    {
        $filename = dirname(__DIR__, 4) . '/_include/src/Register/Module/Search/resources/views/recommendations.php';
        $source = file_get_contents($filename);
        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression('~<style\b~i', $source);
        self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $source);
    }

    public function testCommentFormDoesNotRequireInlineStyles(): void
    {
        $filename = dirname(__DIR__, 4) . '/_include/views/comment_form.php';
        $source = file_get_contents($filename);
        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression('~<style\b~i', $source);
        self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $source);
    }

    public function testDebugMarkupDoesNotRequireInlineStyles(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            $root . '/_include/src/Template/Viewer.php',
            $root . '/_include/src/Template/HtmlTemplate.php',
            $root . '/_include/views/debug_queries.php',
        ] as $filename) {
            $source = file_get_contents($filename);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('~<style\b~i', $source, $filename);
            self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $source, $filename);
        }
    }

    public function testMigratedInteractionsDoNotMutateInlineStyles(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            $root . '/_admin/js/ajax.js',
            $root . '/_admin/js/lib.js',
            $root . '/_admin/js/structure.js',
            $root . '/_admin/js/pictman.js',
            $root . '/_admin/js/editor/dialogs.js',
            $root . '/_admin/js/editor/form.js',
            $root . '/_admin/js/editor/preview.js',
            $root . '/_admin/js/editor/images/overlay.js',
            $root . '/_admin/js/editor/images/pipeline.js',
            $root . '/_admin/js/autocomplete.js',
            $root . '/_admin/js/config-secret.js',
            $root . '/_assets/register/audio-player/player.js',
            $root . '/_assets/register/comment-editor.js',
            $root . '/_assets/register/post-inplace.js',
            $root . '/_assets/register/search/autocomplete.js',
            $root . '/_assets/register/visitor/identity.js',
            $root . '/_styles/register/script.js',
        ] as $filename) {
            $source = file_get_contents($filename);
            self::assertIsString($source);
            self::assertDoesNotMatchRegularExpression('~\.style\b|\.css\s*\(~', $source, $filename);
            self::assertDoesNotMatchRegularExpression('~setAttribute\s*\(\s*[\'\"]style[\'\"]~', $source, $filename);
            self::assertDoesNotMatchRegularExpression(
                '~createElement\s*\(\s*[\'\"]style[\'\"]~',
                $source,
                $filename . ' creates an inline stylesheet.',
            );
            self::assertStringNotContainsString('<style', $source, $filename . ' contains inline stylesheet markup.');
            self::assertDoesNotMatchRegularExpression(
                '~\s(?:style|on[a-z]+)\s*=~i',
                $source,
                $filename . ' constructs an inline style or event handler.',
            );
        }
    }

    public function testAdminThemeUsesAnExternalValidatedStylesheet(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            $root . '/_admin/templates/layout.php.inc',
            $root . '/_admin/templates/access-denied.php.inc',
            $root . '/_admin/templates/picture-manager.php.inc',
        ] as $filename) {
            $source = file_get_contents($filename);
            self::assertIsString($source);
            self::assertStringContainsString('action=theme-stylesheet', $source, $filename);
            self::assertDoesNotMatchRegularExpression('~<style\b~i', $source, $filename);
            self::assertDoesNotMatchRegularExpression('~\sstyle\s*=~i', $source, $filename);
        }

        $script = file_get_contents($root . '/_admin/js/config-secret.js');
        self::assertIsString($script);
        self::assertStringContainsString("stylesheetUrl.searchParams.set('color', color)", $script);
        self::assertDoesNotMatchRegularExpression('~\.style\b|\.css\s*\(~', $script);
    }

    public function testAdminErrorsAreRenderedAsTextWithAnExternalStylesheet(): void
    {
        $root = dirname(__DIR__, 4);
        $source = file_get_contents($root . '/_admin/js/lib.js');

        self::assertIsString($source);
        self::assertStringContainsString('errorOutput.textContent = errorText;', $source);
        self::assertStringContainsString("new URL('css/error-frame.css', document.baseURI)", $source);
        self::assertStringContainsString('eMessage.textContent = String(sMessage);', $source);
        self::assertStringNotContainsString('eMessage.innerHTML = sMessage', $source);
        self::assertStringNotContainsString('new Blob([sError]', $source);
        self::assertFileExists($root . '/_admin/css/error-frame.css');
    }

    public function testPictureManagerBuildsFileInformationWithDomNodes(): void
    {
        $filename = dirname(__DIR__, 4) . '/_admin/js/pictman.js';
        $source = file_get_contents($filename);

        self::assertIsString($source);
        self::assertStringContainsString('function renderFileInformation(', $source);
        self::assertStringContainsString('fileLink.textContent =', $source);
        self::assertStringContainsString("retinaCheckbox.addEventListener('change'", $source);
        self::assertStringNotContainsString("$('#finfo').html", $source);
        self::assertStringNotContainsString("$('#fold_name').html", $source);
    }

    public function testEditorPreviewErrorUsesTextAndAnExternalStylesheet(): void
    {
        $root = dirname(__DIR__, 4);
        $source = file_get_contents($root . '/_admin/js/editor/preview.js');
        $template = file_get_contents($root . '/_admin/templates/article/edit.php.inc');

        self::assertIsString($source);
        self::assertIsString($template);
        self::assertStringContainsString('errorMessage.textContent = message;', $source);
        self::assertStringContainsString("stylesheet.rel = 'stylesheet';", $source);
        self::assertStringNotContainsString("doc.write('<div", $source);
        self::assertStringContainsString("'previewErrorStylesheet'", $template);
        self::assertFileExists($root . '/_admin/css/editor-preview-error.css');
    }

    public function testEditorImageOverlayUsesDomNodesAndAnExternalStylesheet(): void
    {
        $root = dirname(__DIR__, 4);
        $source = file_get_contents($root . '/_admin/js/editor/images/overlay.js');
        $template = file_get_contents($root . '/_admin/templates/article/edit.php.inc');

        self::assertIsString($source);
        self::assertIsString($template);
        self::assertStringContainsString("stylesheet.rel = 'stylesheet';", $source);
        self::assertStringContainsString('overlay.dims.textContent = dimText;', $source);
        self::assertStringContainsString('function createFormatRow(', $source);
        self::assertStringNotContainsString("createElement('style')", $source);
        self::assertStringNotContainsString('.innerHTML', $source);
        self::assertStringContainsString("'imageOverlayStylesheet'", $template);
        self::assertFileExists($root . '/_admin/css/editor-image-overlay.css');
    }

    public function testAdminRuntimeHasNoUnusedInlineStyleGenerators(): void
    {
        $root = dirname(__DIR__, 4);
        $ajax = file_get_contents($root . '/_admin/js/ajax.js');
        $dialogs = file_get_contents($root . '/_admin/js/editor/dialogs.js');

        self::assertIsString($ajax);
        self::assertIsString($dialogs);
        self::assertStringNotContainsString('SetBackground', $ajax);
        self::assertStringNotContainsString('PopupWindow', $dialogs);
        self::assertStringNotContainsString("createElement('style')", $ajax . $dialogs);
        self::assertStringNotContainsString('<style', $ajax . $dialogs);
    }

    public function testLegacyAdminTreeUsesExternalStylesheets(): void
    {
        $root = dirname(__DIR__, 4);
        $script = file_get_contents($root . '/_admin/lib/jquery.jstree.js');
        $stylesheet = file_get_contents($root . '/_admin/css/admin-override.css');

        self::assertIsString($script);
        self::assertIsString($stylesheet);
        self::assertDoesNotMatchRegularExpression(
            '~createElement\s*\(\s*[\'\"]style[\'\"]~',
            $script,
        );
        self::assertDoesNotMatchRegularExpression('~\.attr\s*\(\s*[\'\"]style[\'\"]~', $script);
        self::assertStringNotContainsString('add_sheet({str:', $script);
        self::assertStringContainsString('.jstree ul,', $stylesheet);
        self::assertStringContainsString('#jstree-marker-line {', $stylesheet);
    }

    public function testMathErrorsUseTheExternalErrorClass(): void
    {
        $root = dirname(__DIR__, 4);
        $script = file_get_contents($root . '/_assets/register/math/loader.js');
        $stylesheet = file_get_contents($root . '/_assets/register/math/math.css');

        self::assertIsString($script);
        self::assertIsString($stylesheet);
        self::assertStringContainsString('throwOnError: true', $script);
        self::assertStringNotContainsString('throwOnError: false', $script);
        self::assertStringContainsString('.register-math-error {', $stylesheet);
    }

    /**
     * @param list<string> $paths
     * @return \Generator<string>
     */
    private function sourceFiles(array $paths): \Generator
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                yield $path;
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !\in_array($file->getExtension(), ['php', 'inc'], true)) {
                    continue;
                }

                yield $file->getPathname();
            }
        }
    }
}
