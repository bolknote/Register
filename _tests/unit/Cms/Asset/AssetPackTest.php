<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Asset;

use Codeception\Test\Unit;
use Register\Core\Asset\AssetPack;

final class AssetPackTest extends Unit
{
    public function testColorSchemeIsDeclaredOnceAndCanBeReadByOtherInterfaces(): void
    {
        $assetPack = (new AssetPack('/tmp'))
            ->addMeta('<meta name="viewport" content="width=device-width">')
            ->setColorScheme(AssetPack::COLOR_SCHEME_LIGHT)
            ->setColorScheme(AssetPack::COLOR_SCHEME_DARK);

        self::assertSame(AssetPack::COLOR_SCHEME_DARK, $assetPack->getColorScheme());
        self::assertSame(
            "<meta name=\"viewport\" content=\"width=device-width\">\n"
            . '<meta name="color-scheme" content="dark">',
            $assetPack->getStyles('', null),
        );
    }

    public function testUnspecifiedColorSchemeUsesTheSystemWithoutAddingMarkup(): void
    {
        $assetPack = new AssetPack('/tmp');

        self::assertSame(AssetPack::COLOR_SCHEME_SYSTEM, $assetPack->getColorScheme());
        self::assertSame('', $assetPack->getStyles('', null));
    }

    public function testRejectsUnsupportedColorScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AssetPack('/tmp'))->setColorScheme('sepia');
    }

    public function testBuiltInStylesExposeTheirColorScheme(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';
        $register = require $rootDir . '_styles/register/register.php';
        $oldschool = require $rootDir . '_styles/oldschool/oldschool.php';
        $pixelForest = require $rootDir . '_styles/pixel-forest/pixel-forest.php';
        $systemOne = require $rootDir . '_styles/system-1/system-1.php';

        self::assertInstanceOf(AssetPack::class, $register);
        self::assertInstanceOf(AssetPack::class, $oldschool);
        self::assertInstanceOf(AssetPack::class, $pixelForest);
        self::assertInstanceOf(AssetPack::class, $systemOne);
        self::assertSame(AssetPack::COLOR_SCHEME_SYSTEM, $register->getColorScheme());
        self::assertSame(AssetPack::COLOR_SCHEME_LIGHT, $oldschool->getColorScheme());
        self::assertSame(AssetPack::COLOR_SCHEME_DARK, $pixelForest->getColorScheme());
        self::assertSame(AssetPack::COLOR_SCHEME_LIGHT, $systemOne->getColorScheme());
    }

    public function testBuiltInStylesLocalizeTimesBeforeRenderingTheBody(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';

        foreach (['register', 'oldschool', 'pixel-forest', 'system-1'] as $style) {
            /** @var AssetPack $assetPack */
            $assetPack = require $rootDir . '_styles/' . $style . '/' . $style . '.php';
            $markup    = $assetPack->getStyles('/_styles/' . $style . '/', null);
            $cssPath   = '/_styles/' . $style . '/../../_assets/register/local-time.css?v='
                . (string)\filemtime($rootDir . '_assets/register/local-time.css');
            $jsPath    = '/_styles/' . $style . '/../../_assets/register/local-time.js?v='
                . (string)\filemtime($rootDir . '_assets/register/local-time.js');

            self::assertStringContainsString('<link rel="stylesheet" href="' . $cssPath . '">', $markup);
            self::assertStringContainsString('<script src="' . $jsPath . '"></script>', $markup);
            self::assertStringNotContainsString('<script src="' . $jsPath . '" defer></script>', $markup);
        }
    }

    public function testDynamicFragmentsLocalizeTimesBeforeTheyAreInserted(): void
    {
        $assetRoot = \dirname(__DIR__, 4) . '/_assets/register/';

        foreach (['partial-navigation.js', 'live-updates.js'] as $filename) {
            $script = file_get_contents($assetRoot . $filename);

            self::assertIsString($script);
            $localizePosition = strpos($script, 'localizeTimesBeforeInsertion(replacement);');
            $replacePosition  = strpos($script, 'current.replaceWith(replacement);');
            self::assertIsInt($localizePosition);
            self::assertIsInt($replacePosition);
            self::assertLessThan($replacePosition, $localizePosition);
        }
    }

    public function testStableCommentAnchorStartsAtTheTopOfAGridComment(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';
        $publicAuth = file_get_contents($rootDir . '_assets/register/public-auth.css');
        $site = file_get_contents($rootDir . '_styles/register/site.css');

        self::assertIsString($publicAuth);
        self::assertMatchesRegularExpression(
            '/\.comment-stable-anchor\s*\{[^}]*position:\s*absolute;[^}]*top:\s*-1rem;[^}]*left:\s*0;/s',
            $publicAuth,
        );
        self::assertIsString($site);
        self::assertStringContainsString('.comment-stable-anchor:target ~ .comment-meta', $site);
        self::assertStringContainsString('.comment-stable-anchor:target ~ .comment-tombstone', $site);

        foreach (['oldschool', 'pixel-forest'] as $style) {
            $css = file_get_contents($rootDir . '_styles/' . $style . '/' . $style . '.css');

            self::assertIsString($css);
            self::assertStringContainsString('.comment-stable-anchor:target ~ .comment-meta', $css);
        }
    }

    public function testMobileHeaderControlsShareTheTopRailSymmetrically(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';
        $site = file_get_contents($rootDir . '_styles/register/site.css');
        $publicAuth = file_get_contents($rootDir . '_assets/register/public-auth.css');

        self::assertIsString($site);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 760px\).*?\.site-header-tools\s*'
                . '\{[^}]*top:\s*calc\(-2\.4rem \+ 0\.2rem\);[^}]*left:\s*-0\.4rem;'
                . '[^}]*width:\s*2\.1rem;/s',
            $site,
        );
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 760px\).*?\.site-header-tools \.post-create-start\s*'
                . '\{[^}]*width:\s*2\.1rem;[^}]*min-height:\s*2\.1rem;/s',
            $site,
        );
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 760px\).*?'
                . '\.site-header-shell:has\(> \.site-header-tools\) \.site-title\s*'
                . '\{[^}]*text-indent:\s*0;/s',
            $site,
        );

        self::assertIsString($publicAuth);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 760px\).*?\.public-auth-user-menu > summary\s*'
                . '\{[^}]*width:\s*2\.1rem;[^}]*justify-content:\s*center;[^}]*padding:\s*0;/s',
            $publicAuth,
        );
    }

    public function testMobilePostToolsUseAnOverflowMenuWithoutNarrowingThePost(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';
        $site = file_get_contents($rootDir . '_styles/register/site.css');
        $script = file_get_contents($rootDir . '_assets/register/post-inplace.js');

        self::assertIsString($site);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 760px\).*?\.post-card\.is-manageable\s*>\s*\.post\.body\s*'
                . '\{[^}]*padding-left:\s*0;/s',
            $site,
        );
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 760px\).*?\.post-inplace-button\.post-tools-menu-toggle\s*'
                . '\{[^}]*display:\s*grid;/s',
            $site,
        );
        self::assertMatchesRegularExpression(
            '/\.post-inplace-tools\.is-menu-open\s*>\s*\.post-tools-overflow\s*'
                . '\{[^}]*display:\s*flex;/s',
            $site,
        );
        self::assertMatchesRegularExpression(
            '/\.post-card\.is-editing\s*>\s*\.post\.time\s*>\s*\.post-inplace-date-button\s*'
                . '\{[^}]*position:\s*static;[^}]*transform:\s*none;/s',
            $site,
        );
        self::assertMatchesRegularExpression(
            '/@media \(hover: none\) and \(min-width: 761px\)\s*'
                . '\{\s*\.post-inplace-tools\s*\{[^}]*transform:\s*none;/s',
            $site,
        );

        self::assertIsString($script);
        self::assertStringContainsString('function closePostToolsMenu(', $script);
        self::assertStringContainsString("target?.closest('.post-tools-menu-toggle')", $script);
        self::assertStringContainsString("toolsToggle.setAttribute('aria-expanded', String(opening))", $script);
    }

    public function testTagPostListUsesTheSameLeftEdgeAsThePageContent(): void
    {
        $site = file_get_contents(\dirname(__DIR__, 4) . '/_styles/register/site.css');

        self::assertIsString($site);
        self::assertMatchesRegularExpression(
            '/\.tag-post-list\s*\{[^}]*width:\s*100%;[^}]*margin-inline-start:\s*0;/s',
            $site,
        );
        self::assertStringNotContainsString('--tag-post-list-inset', $site);
    }

    public function testTagPageCreationPrependsTheDraftToItsPostList(): void
    {
        $script = file_get_contents(\dirname(__DIR__, 4) . '/_assets/register/post-inplace.js');

        self::assertIsString($script);
        self::assertMatchesRegularExpression(
            "/document\.querySelector\('\.live-post-feed'\)\s*"
                . "\|\|\s*document\.querySelector\('\.tag-post-list'\)/s",
            $script,
        );
    }

    public function testPostEditorShowsTheCaretBeforeALeadingBlockWithoutChangingContent(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';
        $site = file_get_contents($rootDir . '_styles/register/site.css');
        $script = file_get_contents($rootDir . '_assets/register/post-inplace.js');

        self::assertIsString($site);
        self::assertMatchesRegularExpression(
            '/\.post\.body\[data-post-inplace-body\]\.has-leading-boundary-caret::before\s*,\s*'
                . '\.post-card\.is-editing\s*>\s*\.post\.body\[data-post-inplace-body\]\s*'
                . ':where\(\.post-picture, \.post-media-picture, figure\)'
                . '\.has-leading-boundary-caret::before\s*'
                . '\{[^}]*position:\s*absolute;[^}]*background:\s*var\(--accent-color\);/s',
            $site,
        );

        self::assertIsString($script);
        self::assertStringContainsString(
            'range.startContainer === active && range.startOffset === 0',
            $script,
        );
        self::assertStringContainsString(
            "boundary.matches('.post-picture, .post-media-picture, figure')",
            $script,
        );
        self::assertStringContainsString('node instanceof HTMLBRElement', $script);
        self::assertStringContainsString("boundary.querySelector('img, video, audio')", $script);
        self::assertStringContainsString("document.addEventListener('selectionchange', syncBoundaryCaret", $script);
        self::assertStringNotContainsString('\\u200b', strtolower($script));
    }

    public function testPublishedPostDoesNotRenderTheEditableTailAfterItsLastMediaBlock(): void
    {
        $site = file_get_contents(\dirname(__DIR__, 4) . '/_styles/register/site.css');

        self::assertIsString($site);
        self::assertMatchesRegularExpression(
            '/\.post-card:not\(\.is-editing\)\s*>\s*\.post\.body\s*>\s*'
                . ':where\(p:empty, p:has\(> br:only-child\)\):last-child[^\{]*\{[^}]*display:\s*none;/s',
            $site,
        );
        self::assertStringContainsString(
            '#content .post-card:not(.is-editing) > .post.body > .post-media-picture:where(',
            $site,
        );
        self::assertMatchesRegularExpression(
            '/\.post-media-picture:where\([^\{]+\)\s*\{[^}]*margin-bottom:\s*1\.35em;/s',
            $site,
        );
    }

    public function testPostEditorHighlightsAiChangesWithoutPersistingTheMarks(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';
        $site = file_get_contents($rootDir . '_styles/register/site.css');
        $script = file_get_contents($rootDir . '_assets/register/post-inplace.js');

        self::assertIsString($site);
        self::assertMatchesRegularExpression(
            '/\.post-editor-ai-change\s*\{[^}]*background-image:[^}]*font-weight:\s*700;/s',
            $site,
        );

        self::assertIsString($script);
        self::assertStringContainsString('findAiCorrectionRanges(sourceText, correctedText)', $script);
        self::assertStringContainsString("mark.className = 'post-editor-ai-change';", $script);
        self::assertStringContainsString('clearAiChangeMarks(clone);', $script);
        self::assertStringContainsString('clearAiChangeMarks(container);', $script);
        self::assertStringContainsString('clearAiChangeMarks(state.body);', $script);
        self::assertStringContainsString('markAiChanges(insertedNodes, sourceText);', $script);
        self::assertStringContainsString('markAiChanges(Array.from(state.body.childNodes), sourceText);', $script);
    }

    public function testCommentConfirmationUsesTheCommentContentColumn(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';
        $site = file_get_contents($rootDir . '_styles/register/site.css');
        $script = file_get_contents($rootDir . '_styles/register/script.js');

        self::assertIsString($site);
        self::assertMatchesRegularExpression(
            '/\.has-userpic\s*>\s*\.comment-action-confirmation\s*'
                . '\{[^}]*grid-row:\s*2;[^}]*justify-self:\s*start;/s',
            $site,
        );
        self::assertMatchesRegularExpression(
            '/\.comment-action-confirmation\s*'
                . '\{[^}]*position:\s*relative;[^}]*max-width:\s*min\(34rem,\s*100%\);/s',
            $site,
        );
        self::assertStringNotContainsString('left: calc(100% + 0.5rem)', $site);

        self::assertIsString($script);
        self::assertStringContainsString(
            'item.insertBefore(confirmationElement, commentBody);',
            $script,
        );
        self::assertStringNotContainsString(
            'form.appendChild(confirmationElement);',
            $script,
        );
        self::assertStringContainsString("payload.action === 'spam'", $script);
        self::assertStringContainsString('removeCommentFromThread(item);', $script);
    }

    public function testSystemOneThemeUsesGlobalGrayscaleAndMacOsArtwork(): void
    {
        $themeDir = \dirname(__DIR__, 4) . '/_styles/system-1/';
        $css = file_get_contents($themeDir . 'system-1.css');

        self::assertIsString($css);
        self::assertMatchesRegularExpression('/html\s*\{[^}]*filter:\s*grayscale\(1\);/s', $css);

        foreach (['finder.png', 'folder.png', 'document.png', 'trash.png'] as $asset) {
            self::assertFileExists($themeDir . $asset);
        }
    }

    public function testAlternativeThemesStyleWrappedPostCards(): void
    {
        $rootDir = \dirname(__DIR__, 4) . '/';

        foreach (['oldschool', 'pixel-forest', 'system-1'] as $style) {
            $css = file_get_contents($rootDir . '_styles/' . $style . '/' . $style . '.css');

            self::assertIsString($css);
            self::assertStringContainsString('#content .post-card > .post.head', $css);
        }
    }

    public function testSystemOneChromeFollowsPartialNavigation(): void
    {
        $script = file_get_contents(\dirname(__DIR__, 4) . '/_styles/system-1/system-1.js');

        self::assertIsString($script);
        self::assertStringContainsString('.post-card > .post.head', $script);
        self::assertStringContainsString("'register:navigation-updated'", $script);
    }
}
