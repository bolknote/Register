<?php

declare(strict_types = 1);

/**
 * Renders views.
 *
 * @copyright 2014-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

namespace Register\Core\Template;

use Register\Core\Config\StringProxy;
use Register\Core\Model\UrlBuilder;
use Symfony\Contracts\Translation\TranslatorInterface;

class Viewer
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly UrlBuilder          $urlBuilder,
        private readonly string              $rootDir,
        private readonly StringProxy         $style,
        private readonly bool                $debug
    ) {
    }

    /**
     * @param array<mixed> $vars
     */
    public function render(string $name, array $vars, string ...$resourceOwners): string
    {
        $name     = preg_replace('#[^0-9a-zA-Z._\-]#', '', $name)
            ?? throw new \RuntimeException('Unable to sanitize view name.');
        $filename = $name . '.php';

        $style                = $this->style->get();
        $styleViewDir         = $this->rootDir . '_styles/' . $style . '/views/';
        $systemViewDir        = $this->rootDir . '_include/views/';

        $foundFile = null;
        $dirs      = [
            $styleViewDir,
            ...array_map(
                fn(string $resourceOwner): string => ModuleResourceLocator::views($this->rootDir, $resourceOwner),
                $resourceOwners,
            ),
            $systemViewDir
        ];
        foreach ($dirs as $dir) {
            if (file_exists($dir . $filename)) {
                $foundFile = $dir . $filename;
                break;
            }
        }

        ob_start();

        if ($this->debug) {
            echo '<div class="view-debug-block"><details class="view-debug-details">',
                '<summary><code>', register_htmlencode($name), '</code></summary><pre>',
                $this->jsonFormat($vars),
                '</pre></details>';
        }

        if ($foundFile !== null) {
            $this->includeFile($foundFile, $vars);
        } elseif ($this->debug) {
            echo 'View file not found in ', register_htmlencode(var_export($dirs, true));
        }

        if ($this->debug) {
            echo '</div>';
        }

        $rendered = ob_get_clean();
        if ($rendered === false) {
            throw new \RuntimeException('Unable to read rendered view output.');
        }

        return $rendered;
    }

    /**
     * Puts the date into a string
     */
    public function date(int $time): string
    {
        if ($time === 0) {
            return '';
        }

        $format = $this->translator->trans('Date format');
        $date   = gmdate($format, $time);
        if (str_contains($format, 'F')) {
            $month = gmdate('F', $time);

            return str_replace($month, $this->translator->trans($month . ' genitive'), $date);
        }

        return $date;
    }

    /**
     * Puts the date and time into a string
     */
    public function dateAndTime(int $time): string
    {
        if ($time === 0) {
            return '';
        }

        $format = $this->translator->trans('Time format');
        $date   = gmdate($format, $time);
        if (str_contains($format, 'F')) {
            $month = gmdate('F', $time);

            return str_replace($month, $this->translator->trans($month . ' genitive'), $date);
        }

        return $date;
    }

    /**
     * Outputs integers using current language settings
     */
    public function numberFormat(float $number, bool $trailingZeros = false, ?int $decimalCount = null): string
    {
        $decimalPoint = $this->translator->trans('Decimal point');
        $result       = number_format(
            $number,
            $decimalCount ?? (int)$this->translator->trans('Decimal count'),
            $decimalPoint,
            $this->translator->trans('Thousands separator')
        );
        if (!$trailingZeros) {
            return preg_replace('#' . preg_quote($decimalPoint, '#') . '?0*$#', '', $result)
                ?? throw new \RuntimeException('Unable to format a number.');
        }

        return $result;
    }

    /**
     * @throws \JsonException
     */
    private function jsonFormat(mixed $vars): string
    {
        return register_htmlencode(json_encode(
            $vars,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param array<mixed> $_vars
     */
    private function includeFile(string $_found_file, array $_vars): void
    {
        $trans        = $this->translator->trans(...);
        $makeLink     = $this->urlBuilder->link(...);
        $formatDate   = $this->date(...);
        $dateAndTime  = $this->dateAndTime(...);
        $numberFormat = $this->numberFormat(...);

        // Template variables must not be able to replace the selected view file or helper closures.
        extract($_vars, EXTR_SKIP);
        include $_found_file;
    }
}
