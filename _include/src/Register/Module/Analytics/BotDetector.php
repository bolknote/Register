<?php
/**
 * @copyright 2007-2025 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Module\Analytics;

final readonly class BotDetector
{
    private const array MARKERS = [
        'bot',
        'crawler',
        'spider',
        'slurp',
        'Yahoo!',
        'Mediapartners-Google',
        'Yandex',
        'StackRambler',
        'ia_archiver',
        'appie',
        'ZyBorg',
        'WebAlta',
        'ichiro',
        'TurtleScanner',
        'LinkWalker',
        'Snoopy',
        'libwww',
        'Aport',
        'Spyder',
        'findlinks',
        'Parser',
        'Mail.Ru',
        'rulinki.ru',
    ];

    public function isBot(string $userAgent): bool
    {
        foreach (self::MARKERS as $marker) {
            if (stripos($userAgent, $marker) !== false) {
                return true;
            }
        }

        return false;
    }
}
