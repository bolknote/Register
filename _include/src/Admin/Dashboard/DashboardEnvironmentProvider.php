<?php
/**
 * @copyright 2024 Roman Parpalak
 * @license   http://opensource.org/licenses/MIT MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Admin\Dashboard;

use Register\AdminYard\TemplateRenderer;
use Symfony\Contracts\Translation\TranslatorInterface;

readonly class DashboardEnvironmentProvider implements SystemStatusProviderInterface
{
    public function __construct(
        private TranslatorInterface       $translator,
        private TemplateRenderer          $templateRenderer,
        private DashboardDatabaseProvider $databaseProvider,
    ) {
    }

    #[\Override]
    public function getHtml(): string
    {
        $serverLoad = $this->detectLoadAverages() ?? $this->translator->trans('N/A');
        $processorCount = $this->detectProcessorCount();
        $serverLoadContext = null;
        if ($processorCount !== null
            && preg_match('/^([0-9]+(?:\.[0-9]+)?)/D', $serverLoad, $matches) === 1
        ) {
            $serverLoadContext = [
                'cpu' => $processorCount,
                'percent' => number_format(100.0 * (float)$matches[1] / (float)$processorCount, 1, '.', ''),
            ];
        }

        return $this->templateRenderer->render('_admin/templates/dashboard/environment-item.php.inc', [
            'databaseInfo'   => $this->databaseProvider->getInfo(),
            'operatingSystem' => PHP_OS,
            'phpVersion'      => PHP_VERSION,
            'serverLoad'      => $serverLoad,
            'serverLoadContext' => $serverLoadContext,
        ]);
    }

    private function detectProcessorCount(): ?int
    {
        if (is_readable('/proc/cpuinfo')) {
            $cpuInfo = register_call_without_warnings(static fn(): string|false => file_get_contents('/proc/cpuinfo'));
            if (\is_string($cpuInfo)) {
                $count = preg_match_all('/^processor\s*:/m', $cpuInfo);
                if (\is_int($count) && $count > 0) {
                    return $count;
                }
            }
        }

        $command = PHP_OS_FAMILY === 'Windows' ? null : 'getconf _NPROCESSORS_ONLN';
        $output = $command === null ? null : shell_exec($command);
        if (\is_string($output) && preg_match('/^[1-9][0-9]*$/D', trim($output)) === 1) {
            return (int)trim($output);
        }

        return null;
    }

    /**
     * Get the server load averages (if possible)
     */
    private function detectLoadAverages(): ?string
    {
        if (\function_exists('sys_getloadavg')) {
            $loadAverages = sys_getloadavg();
            if (\is_array($loadAverages)) {
                $loadAverages = array_map(static fn(float $value): string => number_format($value, 3, '.', ''), $loadAverages);
                return implode(' ', $loadAverages);
            }
        }

        if (is_readable('/proc/loadavg')) {
            $loadAverages = register_call_without_warnings(static fn(): string|false => file_get_contents('/proc/loadavg'));
            if ($loadAverages !== false) {
                $loadAverages = explode(' ', $loadAverages);
                if (isset($loadAverages[2])) {
                    return $loadAverages[0] . ' ' . $loadAverages[1] . ' ' . $loadAverages[2];
                }
            }
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        $uptime = shell_exec('uptime');
        if (\is_string($uptime) && preg_match('/averages?:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)/i', $uptime, $matches) === 1) {
            return $matches[1] . ' ' . $matches[2] . ' ' . $matches[3];
        }

        if (PHP_OS_FAMILY === 'BSD' || PHP_OS_FAMILY === 'Darwin') {
            $load = shell_exec('sysctl -n vm.loadavg');
            if (\is_string($load)) {
                $load = str_replace(['{ ', ' }'], '', $load);
                $loadAverages = explode(' ', $load);
                if (isset($loadAverages[2])) {
                    return $loadAverages[0] . ' ' . $loadAverages[1] . ' ' . $loadAverages[2];
                }
            }
        }

        return null;
    }
}
