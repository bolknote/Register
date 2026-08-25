<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Monitoring;

/** Removes values and comments while retaining enough SQL structure for performance analysis. */
final readonly class SqlQueryTemplateSanitizer
{
    private const int MAX_TEMPLATE_LENGTH = 4000;

    public function sanitize(string $sql): string
    {
        $template = $this->removeCommentsAndStrings($sql);
        $template = preg_replace('/(?<![a-z0-9_])0x[0-9a-f]+\b/i', '?', $template);
        $template = \is_string($template)
            ? preg_replace('/(?<![a-z0-9_:$])[-+]?(?:\d+\.\d+|\d+)(?:e[-+]?\d+)?\b/i', '?', $template)
            : null;
        $template = \is_string($template) ? preg_replace('/\s+/u', ' ', $template) : null;
        if (!\is_string($template)) {
            return '[unavailable SQL template]';
        }

        $template = trim($template);
        return $template === '' ? '[empty SQL template]' : mb_substr($template, 0, self::MAX_TEMPLATE_LENGTH);
    }

    private function removeCommentsAndStrings(string $sql): string
    {
        $result = '';
        $length = \strlen($sql);
        for ($position = 0; $position < $length;) {
            $character = $sql[$position];
            $next = $position + 1 < $length ? $sql[$position + 1] : '';

            if ($character === "'" || $character === '"') {
                $result .= '?';
                $position = $this->positionAfterQuotedValue($sql, $position, $character);
                continue;
            }

            if ($character === '$') {
                $delimiter = $this->dollarQuoteDelimiter($sql, $position);
                if ($delimiter !== null) {
                    $result .= '?';
                    $closingPosition = strpos($sql, $delimiter, $position + \strlen($delimiter));
                    $position = $closingPosition === false
                        ? $length
                        : $closingPosition + \strlen($delimiter);
                    continue;
                }
            }

            if ($character === '`') {
                $start = $position;
                $position = $this->positionAfterQuotedIdentifier($sql, $position);
                $result .= substr($sql, $start, $position - $start);
                continue;
            }

            if (($character === '-' && $next === '-') || ($character === '/' && $next === '*')) {
                $position += 2;
                if ($character === '/') {
                    $end = strpos($sql, '*/', $position);
                    $position = $end === false ? $length : $end + 2;
                } else {
                    while ($position < $length && $sql[$position] !== "\r" && $sql[$position] !== "\n") {
                        ++$position;
                    }
                }

                $result .= ' ';
                continue;
            }

            if ($character === '#') {
                ++$position;
                while ($position < $length && $sql[$position] !== "\r" && $sql[$position] !== "\n") {
                    ++$position;
                }

                $result .= ' ';
                continue;
            }

            $result .= $character;
            ++$position;
        }

        return $result;
    }

    private function positionAfterQuotedValue(string $sql, int $position, string $quote): int
    {
        $length = \strlen($sql);
        for (++$position; $position < $length; ++$position) {
            if ($sql[$position] === '\\') {
                ++$position;
                continue;
            }

            if ($sql[$position] !== $quote) {
                continue;
            }

            if ($position + 1 < $length && $sql[$position + 1] === $quote) {
                ++$position;
                continue;
            }

            return $position + 1;
        }

        return $length;
    }

    private function positionAfterQuotedIdentifier(string $sql, int $position): int
    {
        $length = \strlen($sql);
        for (++$position; $position < $length; ++$position) {
            if ($sql[$position] !== '`') {
                continue;
            }

            if ($position + 1 < $length && $sql[$position + 1] === '`') {
                ++$position;
                continue;
            }

            return $position + 1;
        }

        return $length;
    }

    private function dollarQuoteDelimiter(string $sql, int $position): ?string
    {
        $candidate = substr($sql, $position);
        if (preg_match('/\A\$(?:[a-z_][a-z0-9_]*)?\$/i', $candidate, $matches) !== 1) {
            return null;
        }

        return $matches[0];
    }
}
