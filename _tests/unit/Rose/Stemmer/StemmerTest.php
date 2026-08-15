<?php

declare(strict_types = 1);

/**
 * @copyright 2016-2024 Roman Parpalak
 * @license   MIT
 */

namespace S2\Rose\Test\Stemmer;

use Codeception\Test\Unit;
use S2\Rose\Stemmer\PorterStemmerEnglish;
use S2\Rose\Stemmer\PorterStemmerRussian;

/**
 * @group stem
 */
final class StemmerTest extends Unit
{
    /** @psalm-suppress PropertyNotSetInConstructor Codeception initializes this property in _before(). */
    private PorterStemmerRussian $russianStemmer;

    /** @psalm-suppress PropertyNotSetInConstructor Codeception initializes this property in _before(). */
    private PorterStemmerEnglish $englishStemmer;

    /** @psalm-suppress PropertyNotSetInConstructor Codeception initializes this property in _before(). */
    private PorterStemmerRussian $chainedStemmer1;

    /** @psalm-suppress PropertyNotSetInConstructor Codeception initializes this property in _before(). */
    private PorterStemmerEnglish $chainedStemmer2;

    #[\Override]
    protected function _before(): void
    {
        $this->russianStemmer  = new PorterStemmerRussian();
        $this->englishStemmer  = new PorterStemmerEnglish();
        $this->chainedStemmer1 = new PorterStemmerRussian(new PorterStemmerEnglish());
        $this->chainedStemmer2 = new PorterStemmerEnglish(new PorterStemmerRussian());
    }

    public function testRegexes(): void
    {
        self::assertSame('ухмыля', $this->russianStemmer->stemWord('ухмылявшись'));
        self::assertSame('доб', $this->russianStemmer->stemWord('добившись'));
    }

    public function testParticles(): void
    {
        self::assertSame('кто-нибудь', $this->russianStemmer->stemWord('кого-нибудь'));
        self::assertSame('когда-нибудь', $this->russianStemmer->stemWord('когда-нибудь'));
        self::assertSame('что-то', $this->russianStemmer->stemWord('чему-то'));
        self::assertSame('нехитр-то', $this->russianStemmer->stemWord('нехитрое-то'));
        self::assertSame('когда-либо', $this->russianStemmer->stemWord('когда-либо'));
        self::assertSame('что-либо', $this->russianStemmer->stemWord('чем-либо'));
        self::assertSame('кое-что', $this->russianStemmer->stemWord('кое-чем'));
        self::assertSame('кое-кто', $this->russianStemmer->stemWord('кое-кого'));
    }

    public function testStem(): void
    {
        self::assertSame('ухмыляться', $this->englishStemmer->stemWord('ухмыляться'));
        self::assertSame('ухмыля', $this->russianStemmer->stemWord('ухмыляться'));
        self::assertSame('ухмыля', $this->chainedStemmer1->stemWord('ухмыляться'));
        self::assertSame('ухмыля', $this->chainedStemmer2->stemWord('ухмыляться'));

        self::assertSame('рраф', $this->russianStemmer->stemWord('Ррафа'));

        self::assertSame('метро', $this->russianStemmer->stemWord('метро'));

        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзамен'));
        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзамена'));
        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзамену'));
        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзаменом'));
        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзамене'));
        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзамены'));
        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзаменов'));
        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзаменам'));
        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзаменами'));
        self::assertSame('экзамен', $this->russianStemmer->stemWord('экзаменах'));

        self::assertSame('домен', $this->russianStemmer->stemWord('домен'));
        self::assertSame('домен', $this->russianStemmer->stemWord('домена'));
        self::assertSame('домен', $this->russianStemmer->stemWord('домену'));
        self::assertSame('домен', $this->russianStemmer->stemWord('доменом'));
        self::assertSame('домен', $this->russianStemmer->stemWord('домене'));
        self::assertSame('домен', $this->russianStemmer->stemWord('домены'));
        self::assertSame('домен', $this->russianStemmer->stemWord('доменов'));
        self::assertSame('домен', $this->russianStemmer->stemWord('доменам'));
        self::assertSame('домен', $this->russianStemmer->stemWord('доменами'));
        self::assertSame('домен', $this->russianStemmer->stemWord('доменах'));

        self::assertSame('учитель', $this->englishStemmer->stemWord('Учитель'));
        self::assertSame('учител', $this->russianStemmer->stemWord('учитель'));
        self::assertSame('учител', $this->chainedStemmer1->stemWord('учитель'));
        self::assertSame('учител', $this->chainedStemmer2->stemWord('учитель'));

        self::assertSame('gun', $this->englishStemmer->stemWord('guns'));
        self::assertSame('guns', $this->russianStemmer->stemWord('guns'));

        self::assertSame('papa', $this->chainedStemmer1->stemWord("papa's"));
        self::assertSame('papa', $this->chainedStemmer2->stemWord("papa's"));
    }
}
