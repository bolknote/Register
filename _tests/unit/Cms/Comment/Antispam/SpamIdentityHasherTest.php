<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace unit\Cms\Comment\Antispam;

use Codeception\Test\Unit;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;

final class SpamIdentityHasherTest extends Unit
{
    public function testNormalizesIdentifiersBeforeHashing(): void
    {
        $hasher = new SpamIdentityHasher(str_repeat('a', 32));

        self::assertSame($hasher->email('User@Example.com'), $hasher->email(' user@example.COM '));
        self::assertSame($hasher->text("Hello\n world"), $hasher->text(' hello   WORLD '));
        self::assertSame($hasher->domain('.Example.COM.'), $hasher->domain('example.com'));
        self::assertSame($hasher->ip('2001:db8::1'), $hasher->ip('2001:0db8:0:0:0:0:0:1'));
    }

    public function testSeparatesPurposesAndInstallationSecrets(): void
    {
        $first  = new SpamIdentityHasher(str_repeat('a', 32));
        $second = new SpamIdentityHasher(str_repeat('b', 32));

        self::assertNotSame($first->email('same'), $first->text('same'));
        self::assertNotSame($first->email('same'), $second->email('same'));
        self::assertNotSame($first->sign('form', 'payload'), $first->sign('visitor', 'payload'));
    }

    public function testRejectsShortSecret(): void
    {
        $this->expectException(\LogicException::class);

        (new SpamIdentityHasher('short'))->email('user@example.com');
    }
}
