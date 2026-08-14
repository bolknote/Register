<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace unit\Register\Url;

use Codeception\Test\Unit;
use Register\Url\SlugGenerator;
use Register\Url\TransliteratorInterface;
use Register\Url\UniqueSlugGenerator;

final class UniqueSlugGeneratorTest extends Unit
{
    public function testAddsNumericSuffixUntilSlugIsAvailable(): void
    {
        $generator = new UniqueSlugGenerator(new SlugGenerator(new FixedTransliterator('new-post')));

        $slug = $generator->generate(
            'New post',
            static fn(string $candidate): bool => !\in_array($candidate, ['new-post', 'new-post-2'], true),
        );

        self::assertSame('new-post-3', $slug);
    }

    public function testUsesCallerFallbackForTitleWithoutLetters(): void
    {
        $generator = new UniqueSlugGenerator(new SlugGenerator(new FixedTransliterator('')));

        self::assertSame('post', $generator->generate('💥', static fn(string $candidate): bool => $candidate === 'post', 'post'));
    }

    public function testKeepsNumericSuffixWithinDatabaseColumnLength(): void
    {
        $base      = str_repeat('a', SlugGenerator::MAX_LENGTH);
        $generator = new UniqueSlugGenerator(new SlugGenerator(new FixedTransliterator($base)));

        $slug = $generator->generate(
            'Long title',
            static fn(string $candidate): bool => str_ends_with($candidate, '-2'),
        );

        self::assertSame(SlugGenerator::MAX_LENGTH, strlen($slug));
        self::assertStringEndsWith('-2', $slug);
    }

    public function testStopsWhenAvailabilityCheckNeverSucceeds(): void
    {
        $generator = new UniqueSlugGenerator(new SlugGenerator(new FixedTransliterator('occupied')));

        $this->expectException(\OverflowException::class);
        $generator->generate('Occupied', static fn(string $_candidate): bool => false);
    }
}

final readonly class FixedTransliterator implements TransliteratorInterface
{
    public function __construct(private string $result)
    {
    }

    #[\Override]
    public function transliterate(string $text): string
    {
        unset($text);

        return $this->result;
    }
}
