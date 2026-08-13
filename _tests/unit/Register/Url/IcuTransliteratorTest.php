<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Url;

use Codeception\Test\Unit;
use Register\Url\IcuTransliterator;

final class IcuTransliteratorTest extends Unit
{
    public function testUsesIcuWhenIntlIsAvailable(): void
    {
        $transliterator = IcuTransliterator::create();
        if (!$transliterator instanceof IcuTransliterator) {
            self::markTestSkipped('ext-intl is not installed.');
        }

        self::assertSame('privet, mir!', $transliterator->transliterate('Привет, мир!'));
        self::assertSame('creme brulee', $transliterator->transliterate('Crème brûlée'));
    }
}
