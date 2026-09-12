<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Module\Analytics;

use Codeception\Test\Unit;

final class OverviewAnalyticsViewTest extends Unit
{
    public function testExplainsDailyReadersAndShowsZeroTrafficAgainstThePreviousWeek(): void
    {
        $html = $this->render(['previous_views' => 7, 'previous_daily_readers' => 1.0]);

        self::assertStringContainsString('Overview daily readers note', $html);
        self::assertStringContainsString('-100%', $html);
        self::assertStringNotContainsString('Overview audience empty', $html);
        self::assertStringContainsString('entity=Statistics', $html);
        self::assertStringNotContainsString('<script', $html);
    }

    public function testShowsEmptyStateWithoutPretendingThereAreAudienceNumbers(): void
    {
        $html = $this->render([]);
        self::assertStringContainsString('Overview audience empty', $html);
        self::assertStringContainsString('Overview popular posts empty', $html);
        self::assertStringNotContainsString('class="overview-metric"', $html);
    }

    public function testAuthorsCanReadTheSummaryWithoutALinkToRestrictedAnalytics(): void
    {
        $html = $this->render(['views' => 14, 'daily_readers' => 2.0], analyticsPermission: false);

        self::assertStringContainsString('Overview audience', $html);
        self::assertStringContainsString('<strong>14</strong>', $html);
        self::assertStringContainsString('Overview daily readers', $html);
        self::assertStringNotContainsString('entity=Statistics', $html);
        self::assertStringNotContainsString('Overview all analytics', $html);
    }

    public function testEscapesAnalyticsTitlesAndRejectsExternalOrMalformedPaths(): void
    {
        $html = $this->render([
            'views' => 5,
            'daily_readers' => 1.0,
            'posts' => [
                ['path' => '/blog/post?x="y"', 'title' => '<script>bad()</script>', 'views' => 5],
                ['path' => '//evil.example', 'title' => 'Protocol relative', 'views' => 4],
                ['path' => '/\\evil.example', 'title' => 'Backslash', 'views' => 3],
                ['path' => 'javascript:alert(1)', 'title' => 'Script URL', 'views' => 2],
                ['path' => "/\nevil.example", 'title' => 'Newline', 'views' => 1],
            ],
        ]);

        self::assertStringContainsString('href="/blog/post?x=&quot;y&quot;"', $html);
        self::assertStringContainsString('&lt;script&gt;bad()&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('href="//evil.example', $html);
        self::assertStringNotContainsString('href="/\\evil.example', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
        self::assertStringNotContainsString('INF', $html);
    }

    /** @param array<string, mixed> $overrides */
    private function render(array $overrides, bool $analyticsPermission = true): string
    {
        $audience = array_replace([
            'from' => '2026-09-05',
            'to' => '2026-09-11',
            'previous_from' => '2026-08-29',
            'previous_to' => '2026-09-04',
            'views' => 0,
            'previous_views' => 0,
            'daily_readers' => 0.0,
            'previous_daily_readers' => 0.0,
            'posts' => [],
        ], $overrides);

        return $this->renderView(
            \dirname(__DIR__, 5) . '/_include/src/Register/Module/Analytics/resources/views/overview.php.inc',
            [
                'basePath' => '/blog',
                'canViewAnalytics' => $analyticsPermission,
                'trans' => static fn(string $key, array $parameters = []): string => $parameters === [] ? $key : strtr($key, $parameters),
                'audience' => $audience,
            ],
        );
    }

    /** @param array<string, mixed> $parameters */
    private function renderView(string $filename, array $parameters): string
    {
        extract($parameters, EXTR_SKIP);
        ob_start();
        try {
            require $filename;
            $html = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        if (!\is_string($html)) {
            throw new \LogicException('Unable to render the analytics overview.');
        }

        return $html;
    }
}
