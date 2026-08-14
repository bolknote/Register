<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Backup;

use Codeception\Test\Unit;
use Register\Backup\BackupFile;
use S2\AdminYard\Translator;

final class DashboardBackupViewTest extends Unit
{
    public function testRussianRetentionUsesTheCorrectPluralForm(): void
    {
        $translator = new Translator($this->translations('ru'), 'ru');

        foreach ([
            1  => 'Создаются ежедневно; хранится последняя 1 копия.',
            3  => 'Создаются ежедневно; хранятся последние 3 копии.',
            5  => 'Создаются ежедневно; хранятся последние 5 копий.',
            21 => 'Создаются ежедневно; хранится последняя 21 копия.',
        ] as $count => $expected) {
            self::assertSame($expected, $translator->trans('Automatic backup status', [
                '%count%'     => $count,
                '{{ count }}' => $count,
            ]));
        }
    }

    public function testBackupTimeHasAnExplicitUtcFallbackAndBrowserLocalizationHook(): void
    {
        $translator = new Translator($this->translations('ru'), 'ru');

        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Vladivostok');
        try {
            $html = $this->renderView(
                \dirname(__DIR__, 4) . '/_include/src/Register/Backup/resources/views/dashboard-backup.php.inc',
                [
                    'trans'            => $translator->trans(...),
                    'friendlyFilesize' => static fn(int $_size): string => '4,67 МБ',
                    'basePath'         => '',
                    'csrfToken'        => 'token',
                    'automatic'        => true,
                    'retention'        => 3,
                    'latestBackup'     => new BackupFile(
                        '/backup.zip',
                        'backup.zip',
                        1_786_680_000,
                        4_896_984,
                    ),
                ],
            );
        } finally {
            date_default_timezone_set($originalTimezone);
        }

        self::assertStringContainsString(
            '<time datetime="2026-08-14T04:00:00+00:00" data-local-time>',
            $html,
        );
        self::assertStringContainsString('2026-08-14 04:00 UTC', $html);
        self::assertStringContainsString('хранятся последние 3 копии', $html);
        self::assertStringContainsString('<form class="backup-actions"', $html);
    }

    /**
     * @param array<string, mixed> $parameters
     */
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
            throw new \LogicException('Unable to render the dashboard backup view.');
        }

        return $html;
    }

    /** @return array<mixed> */
    private function translations(string $locale): array
    {
        return require \dirname(__DIR__, 4) . '/_admin/lang/' . $locale . '/admin.php';
    }
}
