<?php /** @noinspection RegExpRedundantEscape */
/** @noinspection CallableParameterUseCaseInTypeContextInspection */
/**
 * @copyright 2009-2025 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Helper;

class StringHelper
{
    public static function nameInitials(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '?';
        }

        $nameWithoutQualifier = preg_replace('/\s*\([^)]*\)\s*$/u', '', $name);
        if (\is_string($nameWithoutQualifier) && $nameWithoutQualifier !== '') {
            $name = $nameWithoutQualifier;
        }

        $matchCount = preg_match_all('/[\p{L}\p{N}]+/u', $name, $matches);
        if ($matchCount === false) {
            throw new \RuntimeException('Invalid initials pattern.');
        }

        if ($matchCount === 0) {
            return '?';
        }

        $parts = $matches[0];

        $initials = [mb_substr($parts[0], 0, 1)];
        if (\count($parts) > 1) {
            $initials[] = mb_substr($parts[array_key_last($parts)], 0, 1);
        }

        return mb_strtoupper(implode('', $initials));
    }

    public static function stablePaletteIndex(string $value, int $paletteSize): int
    {
        if ($paletteSize < 1) {
            throw new \InvalidArgumentException('Palette must contain at least one color.');
        }

        $hash = hash('sha256', mb_strtolower(trim($value)), true);

        return \ord($hash[0]) % $paletteSize;
    }

    /**
     * Mail link kept under the legacy method name for template compatibility.
     */
    public static function jsMailTo(string $name, string $email): string
    {
        $parts = explode('@', $email);
        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if (\count($parts) !== 2) {
            return $safeName;
        }

        $safeEmail = htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<a href="mailto:' . $safeEmail . '">' . $safeName . '</a>';
    }

    /**
     * Validate an e-mail address
     */
    public static function isValidEmail(string $email): bool
    {
        if (\strlen($email) > 80) {
            return false;
        }

        return preg_match('/^(([^<>()[\]\\.,;:\s@"\']+(\.[^<>()[\]\\.,;:\s@"\']+)*)|("[^"\']+"))@((\[\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\])|(([a-zA-Z\d\-]+\.)+[a-zA-Z]{2,}))$/', $email) > 0;
    }

    /**
     * Creates paging navigation (1  2  3 ... total_pages - 1  total_pages)
     *
     * @param int $page Current page
     * @param string $url must have the following form http://example.com/page?num=%d
     * @param array<string, mixed> $linksForNavigation [prev => url, next => url]
     */
    public static function paging(int $page, int $totalPages, string $url, array &$linksForNavigation): string
    {
        $linksForNavigation = [];
        if ($totalPages <= 1) {
            return '';
        }

        $links = '';
        for ($i = 1; $i <= $totalPages; ++$i) {
            $links .= ($i === $page
                ? ' <span class="current digit">' . $i . '</span>'
                : ' <a class="digit" href="' . \sprintf($url, $i) . '">' . $i . '</a>');
        }

        if ($page <= 1 || $page > $totalPages) {
            $prevLink = '<span class="arrow left">&larr;</span>';
        } else {
            $prevUrl                    = \sprintf($url, $page - 1);
            $linksForNavigation['prev'] = $prevUrl;
            $prevLink                   = '<a class="arrow left" href="' . $prevUrl . '">&larr;</a>';
        }

        if ($page === $totalPages) {
            $nextLink = ' <span class="arrow right">&rarr;</span>';
        } else {
            $nextUrl                    = \sprintf($url, $page + 1);
            $linksForNavigation['next'] = $nextUrl;
            $nextLink                   = ' <a class="arrow right" href="' . $nextUrl . '">&rarr;</a>';
        }

        return '<p class="paging">' . $prevLink . $links . $nextLink . '</p>';
    }

    /**
     * Parses BB-codes in comments
     */
    public static function bbcodeToHtml(string $s, string $wroteText): string
    {
        $s = str_replace(["''", "\r"], ['"', ''], $s);

        // Imported archives can attach only files from Register's managed Telegram directory.
        // Keeping this deliberately narrow prevents ordinary comment BBCode from becoming an
        // arbitrary external image/tracking embed.
        $s = preg_replace_callback(
            '~\[(IMG|VIDEO|AUDIO|FILE)\](/_pictures/bolknote/telegram/[A-Za-z0-9._/@%-]+)\[/\1\]~i',
            static function (array $matches): string {
                $kind = strtoupper($matches[1]);
                $url = $matches[2];

                return match ($kind) {
                    'IMG' => '<figure class="comment-media"><img src="' . $url
                        . '" alt="" loading="lazy" decoding="async"></figure>',
                    'VIDEO' => '<figure class="comment-media"><video src="' . $url
                        . '" controls preload="metadata"></video></figure>',
                    'AUDIO' => '<span class="comment-media"><audio src="' . $url
                        . '" controls preload="metadata"></audio></span>',
                    'FILE' => '<a class="comment-media-file" href="' . $url . '">'
                        . s2_htmlencode(rawurldecode(basename($url))) . '</a>',
                    default => throw new \LogicException('Unsupported managed comment attachment.'),
                };
            },
            $s,
        ) ?? throw new \RuntimeException('Invalid managed comment attachment pattern.');

        $s = preg_replace('#\[I\](.*?)\[/I\]#isS', '<em>\1</em>', $s) ?? throw new \RuntimeException('Invalid BBCode pattern.');
        $s = preg_replace('#\[B\](.*?)\[/B\]#isS', '<strong>\1</strong>', $s) ?? throw new \RuntimeException('Invalid BBCode pattern.');

        while (preg_match('/\[Q\s*=\s*([^\]]*)\].*?\[\/Q\]/isS', $s) === 1) {
            $s = preg_replace('/\s*\[Q\s*=\s*([^\]]*)\]\s*(.*?)\s*\[\/Q\]\s*/isS', '<blockquote><strong>\\1</strong> ' . $wroteText . '<br/><br/><em>\\2</em></blockquote>', $s) ?? throw new \RuntimeException('Invalid BBCode pattern.');
        }

        while (preg_match('/\[Q\].*?\[\/Q\]/isS', $s) === 1) {
            $s = preg_replace('/\s*\[Q\]\s*(.*?)\s*\[\/Q\]\s*/isS', '<blockquote>\\1</blockquote>', $s) ?? throw new \RuntimeException('Invalid BBCode pattern.');
        }

        $s = preg_replace_callback(
            '#(https?://\S{2,}?)(?=[\s),\'><\]]|&lt;|&gt;|[.;:](?:\s|$)|$)#u',
            static function (array $matches): string {
                $href = $matches[1];
                $link = $matches[1];
                if (mb_strlen($matches[1]) > 55) {
                    $link = mb_substr($matches[1], 0, 42) . ' &hellip; ' . mb_substr($matches[1], -10);
                }

                return '<noindex><a href="' . $href . '" rel="nofollow">' . $link . '</a></noindex>';
            },
            $s
        ) ?? throw new \RuntimeException('Invalid URL pattern.');

        return str_replace("\n", '<br />', $s);
    }

    /**
     * wordwrap() with utf-8 support
     */
    public static function utf8Wordwrap(string $string, int $width = 75, string $break = "\n"): string
    {
        $a = explode("\n", $string);
        foreach ($a as $k => $str) {
            $words  = preg_split('#[\s\r]+#', $str);
            $str    = $words === false ? [] : $words;
            $len    = 0;
            $return = '';
            foreach ($str as $val) {
                $val .= ' ';
                $tmp = mb_strlen($val);
                $len += $tmp;
                if ($len >= $width) {
                    $return .= $break . $val;
                    $len    = $tmp;
                } else {
                    $return .= $val;
                }
            }

            $a[$k] = $return;
        }

        return implode("\n", $a);
    }

    /**
     * Parses BB-codes in comments and makes quotes mail-styled (used '>')
     */
    public static function bbcodeToMail(string $s): string
    {
        $s = str_replace(["\r", '&quot;', '&laquo;', '&raquo;'], ['', '"', '"', '"'], $s);
        $s = preg_replace('/\[I\s*?\](.*?)\[\/I\s*?\]/isu', "_\\1_", $s) ?? throw new \RuntimeException('Invalid BBCode pattern.');
        $s = preg_replace('/\[B\s*?\](.*?)\[\/B\s*?\]/isu', "*\\1*", $s) ?? throw new \RuntimeException('Invalid BBCode pattern.');

        // Do not ask me how the rest of the function works.
        // It just works :)

        while (preg_match('/\[Q\s*?=?\s*?([^\]]*)\s*?\].*?\[\/Q.*?\]/is', $s) === 1) {
            $s = preg_replace('/\s*\[Q\s*?=?\s*?([^\]]*)\s*?\]\s*(.*?)\s*\[\/Q.*?\]\s*/is', "<q/>\\2</q>", $s) ?? throw new \RuntimeException('Invalid BBCode pattern.');
        }

        $strings = [];
        $levels = [];

        $curr  = 0;
        $level = 0;

        while (1) {
            $up   = strpos($s, '<q/>', $curr);
            $down = strpos($s, '</q>', $curr);
            if ($up === false) {
                if ($down === false) {
                    break;
                }

                $dl = -1;
                $c  = $down;
            } elseif ($down === false || $up < $down) {
                $dl = 1;
                $c  = $up;
            } else {
                $dl = -1;
                $c  = $down;
            }

            $strings[] = substr($s, $curr, $c - $curr);
            $curr      = $c + 4;
            $levels[]  = $level;
            $level     += $dl;
        }

        $strings[] = substr($s, $curr);
        $levels[]  = 0;

        $out = [];
        foreach ($strings as $i => $string) {
            if (trim($string) === '') {
                continue;
            }

            $delimiter = "\n" . str_repeat('> ', $levels[$i]);
            $out[]     = $delimiter . self::utf8Wordwrap(str_replace("\n", $delimiter, $string), 70 - 2 * $levels[$i], $delimiter);
        }

        $s = implode("\n", $out);

        return trim($s);
    }
}
