<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Cms\Comment\Antispam;

use Codeception\Test\Unit;
use Register\Core\Comment\Antispam\SpamFeatureExtractor;

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

    public function testDetectsSentenceSplitBetweenNameAndTextByTransliteratedRussianSpammer(): void
    {
        $extractor = new SpamFeatureExtractor();

        self::assertTrue($extractor->hasSentenceLikeLatinTransliteration(
            'nado bylo nach',
            'inat s tatarskogo',
        ));
        self::assertTrue($extractor->hasSentenceLikeLatinTransliteration(
            'a potom udivlyayutsya poc',
            'hemu k gadalkam idut',
        ));
        self::assertTrue($extractor->hasSentenceLikeLatinTransliteration(
            'vertep o',
            'n takoi',
        ));
        self::assertTrue($extractor->hasSentenceLikeLatinTransliteration(
            'tem zhe',
            'chto tolstoi',
        ));
    }

    /** @dataProvider ordinaryLatinCommentsProvider */
    public function testDoesNotConfuseOrdinaryLatinNamesAndCommentsWithCampaign(
        string $name,
        string $text,
    ): void {
        $extractor = new SpamFeatureExtractor();

        self::assertFalse($extractor->hasSentenceLikeLatinTransliteration($name, $text));
    }

    /** @return iterable<string, array{string, string}> */
    public static function ordinaryLatinCommentsProvider(): iterable
    {
        yield 'ordinary title-case name' => ['John Doe', 'Thank you for the useful article.'];
        yield 'lowercase English' => ['john doe', 'thank you for the useful article'];
        yield 'single identifier' => ['shaltai-boltai', 'spasibo za kommentarij'];
        yield 'legacy domain in name' => [
            'denis-barushev (barushev.net)',
            'please see this code and the linked article',
        ];
        yield 'Cyrillic text' => ['ivan ivanov', 'Спасибо за статью'];
    }
}
