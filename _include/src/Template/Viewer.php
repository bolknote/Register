<?php

declare(strict_types = 1);

/**
 * Renders views.
 *
 * @copyright 2014-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

namespace S2\Cms\Template;

use S2\Cms\Config\StringProxy;
use S2\Cms\Model\UrlBuilder;
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
    public function render(string $name, array $vars, string ...$extraDirs): string
    {
        $name     = preg_replace('#[^0-9a-zA-Z._\-]#', '', $name)
            ?? throw new \RuntimeException('Unable to sanitize view name.');
        $filename = $name . '.php';

        $style                = $this->style->get();
        $styleViewDir         = $this->rootDir . '_styles/' . $style . '/views/';
        $extensionDirPattern  = $this->rootDir . '_extensions/%s/views/';
        $systemViewDir        = $this->rootDir . '_include/views/';

        $foundFile = null;
        $dirs      = [
            $styleViewDir,
            ...array_map(static fn(string $dir): string => \sprintf($extensionDirPattern, $dir), $extraDirs),
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
            echo '<div style="border: 1px solid rgba(0, 0, 0, 0.15); margin: 1px; position: relative;">',
            '<pre style="opacity: 0.4; background: darkgray; color: white; position: absolute; z-index: 10000; right: 0; cursor: pointer; text-decoration: underline; padding: 0.1em 0.65em;" onclick="this.nextSibling.style.display = this.nextSibling.style.display === \'block\' ? \'none\' : \'block\'; ">', $name, '</pre>',
            '<pre style="display: none; font-size: 12px; line-height: 1.3; color: #9e9; background: #003;">';
            echo self::jsonFormat($vars);
            echo '</pre>';
        }

        if ($foundFile !== null) {
            $this->includeFile($foundFile, $vars);
        } elseif ($this->debug) {
            echo 'View file not found in ', s2_htmlencode(var_export($dirs, true));
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
        $date   = date($format, $time);
        if (str_contains($format, 'F')) {
            return str_replace(date('F', $time), $this->translator->trans(date('F', $time) . ' genitive'), $date);
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
        $date   = date($format, $time);
        if (str_contains($format, 'F')) {
            return str_replace(date('F', $time), $this->translator->trans(date('F', $time) . ' genitive'), $date);
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
    private static function jsonFormat(mixed $vars, int $level = 0): string
    {
        if (\is_array($vars)) {
            if (!array_is_list($vars)) {
                $s = "<span style='color:grey'>{</span>\n";
                $i = \count($vars);
                foreach ($vars as $k => $v) {
                    --$i;
                    $s .= sprintf("%s<span style='color:grey'>\"</span>%s<span style='color:grey'>\":</span> %s<span style='color:grey'>%s</span>\n",
                        str_pad(' ', ($level + 1) * 4),
                        s2_htmlencode($k),
                        self::jsonFormat($v, $level + 1),
                        $i > 0 ? ',' : ''
                    );
                }

                return $s . str_pad(' ', $level * 4) . '<span style="color:grey">}</span>';
            }

            $s = "<span style='color:grey'>[</span>\n";
            $i = \count($vars);
            foreach ($vars as $v) {
                --$i;
                $s .= \sprintf("%s%s<span style='color:grey'>%s</span>\n",
                    str_pad(' ', ($level + 1) * 4),
                    self::jsonFormat($v, $level + 1),
                    $i > 0 ? ',' : ''
                );
            }

            return $s . str_pad(' ', $level * 4) . '<span style="color:grey">]</span>';
        }

        $str = s2_htmlencode(json_encode($vars, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return str_replace(["\r", "\n"], ['', "\n" . str_pad(' ', $level * 4)], $str);
    }

    /**
     * @param array<mixed> $_vars
     */
    private function includeFile(string $_found_file, array $_vars): void
    {
        $trans        = $this->translator->trans(...);
        $makeLink     = $this->urlBuilder->link(...);
        $date         = $this->date(...);
        $dateAndTime  = $this->dateAndTime(...);
        $numberFormat = $this->numberFormat(...);

        extract($_vars, EXTR_OVERWRITE);
        include $_found_file;
    }
}
