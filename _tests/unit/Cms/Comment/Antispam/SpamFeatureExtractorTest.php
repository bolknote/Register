<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Comment\Antispam;

use Codeception\Test\Unit;
use S2\Cms\Comment\Antispam\SpamFeatureExtractor;

final class SpamFeatureExtractorTest extends Unit
{
    public function testExtractsNormalizedUniqueDomains(): void
    {
        $extractor = new SpamFeatureExtractor();

        self::assertSame(
            ['example.com', 'sub.example.org'],
            $extractor->domains('See HTTPS://Example.COM/a, http://sub.example.org/x and https://example.com/b.'),
        );
    }

    public function testCountsLinksWithoutCountingPlainDomains(): void
    {
        $extractor = new SpamFeatureExtractor();

        self::assertSame(2, $extractor->linkCount('example.com https://one.test/a and http://two.test/b.'));
    }

    public function testDetectsMarkupFormattingControlsAndRepetition(): void
    {
        $extractor = new SpamFeatureExtractor();

        self::assertTrue($extractor->hasHtml('Hello <strong>world</strong>'));
        self::assertFalse($extractor->hasHtml('2 < 3 and 4 > 1'));
        self::assertTrue($extractor->hasFormattingControls("safe\u{202E}hidden"));
        self::assertTrue($extractor->hasLongRepetition('aaaaaaaaaa'));
        self::assertFalse($extractor->hasLongRepetition('ordinary comment'));
    }
}
