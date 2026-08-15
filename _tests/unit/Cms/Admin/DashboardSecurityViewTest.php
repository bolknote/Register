<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Admin;

use Codeception\Test\Unit;
use S2\AdminYard\Translator;
use S2\Cms\Security\Monitoring\SecurityAlertSummary;

final class DashboardSecurityViewTest extends Unit
{
    public function testRussianSummaryIsLocalizedAndShowsWarning(): void
    {
        $translator = new Translator(
            require \dirname(__DIR__, 4) . '/_admin/lang/ru/admin.php',
            'ru',
        );
        $summary = new SecurityAlertSummary(
            windowMinutes: 15,
            unauthorizedResponses: 10,
            forbiddenResponses: 4,
            rateLimitedResponses: 3,
            cspViolations: 20,
            uploadFailures: 2,
            unauthorizedSpike: true,
            forbiddenSpike: false,
            rateLimitedSpike: true,
            cspSpike: true,
            uploadSpike: false,
            telemetryNearCapacity: true,
        );

        $html = $this->render([
            'trans'           => $translator->trans(...),
            'securitySummary' => $summary,
        ]);

        self::assertStringContainsString('<h3>Мониторинг безопасности</h3>', $html);
        self::assertStringContainsString('Превышен порог событий безопасности.', $html);
        self::assertStringContainsString('HTTP 401: 10 · HTTP 403: 4 · HTTP 429: 3', $html);
        self::assertStringContainsString('Нарушения CSP: 20 · ошибки загрузки: 2', $html);
        self::assertStringContainsString('журнал событий безопасности почти заполнен', $html);
        self::assertStringContainsString('Скользящее окно: 15 минут.', $html);
        self::assertStringContainsString('icon-warning', $html);
    }

    public function testNormalSummaryDoesNotRenderWarningIcon(): void
    {
        $translator = new Translator(
            require \dirname(__DIR__, 4) . '/_admin/lang/en/admin.php',
            'en',
        );
        $summary = new SecurityAlertSummary(
            windowMinutes: 15,
            unauthorizedResponses: 0,
            forbiddenResponses: 0,
            rateLimitedResponses: 0,
            cspViolations: 0,
            uploadFailures: 0,
            unauthorizedSpike: false,
            forbiddenSpike: false,
            rateLimitedSpike: false,
            cspSpike: false,
            uploadSpike: false,
            telemetryNearCapacity: false,
        );

        $html = $this->render([
            'trans'           => $translator->trans(...),
            'securitySummary' => $summary,
        ]);

        self::assertStringContainsString('No unusual security activity detected.', $html);
        self::assertStringNotContainsString('icon-warning', $html);
    }

    /** @param array<string, mixed> $parameters */
    private function render(array $parameters): string
    {
        extract($parameters, EXTR_SKIP);

        ob_start();
        try {
            require \dirname(__DIR__, 4) . '/_admin/templates/dashboard/security-item.php.inc';
            $html = ob_get_clean();
        } catch (\Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        if (!\is_string($html)) {
            throw new \LogicException('Unable to render the dashboard security view.');
        }

        return $html;
    }
}
