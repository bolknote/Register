<?php

declare(strict_types = 1);

namespace unit\Register\Comment;

use Codeception\Test\Unit;
use Register\Comment\CommentAgePolicy;
use Register\Core\Config\DynamicConfigProvider;

final class CommentAgePolicyTest extends Unit
{
    public function testDisabledAndMissingConfigurationKeepExistingDiscussionsOpen(): void
    {
        $config = $this->createMock(DynamicConfigProvider::class);
        $config->method('get')->willThrowException(new \LogicException('Missing setting'));
        self::assertFalse((new CommentAgePolicy($config))->isClosed(1, 2_000_000_000));
        self::assertFalse($this->policy('0')->isClosed(1, 2_000_000_000));
    }

    public function testClosesExactlyAtThePublicationAgeBoundary(): void
    {
        $policy = $this->policy('14');
        $publishedAt = 1_700_000_000;
        $deadline = $publishedAt + 14 * 86400;
        self::assertSame($deadline, $policy->closesAt($publishedAt));
        self::assertFalse($policy->isClosed($publishedAt, $deadline - 1));
        self::assertTrue($policy->isClosed($publishedAt, $deadline));
        self::assertTrue($policy->isClosed($publishedAt, $deadline + 1));
        self::assertNull($policy->closesAt(null));
    }

    public function testInvalidConfigurationCannotUnexpectedlyCloseDiscussions(): void
    {
        foreach (['-1', '1.5', 'garbage', '36501', '99999999999999999999999'] as $value) {
            self::assertSame(0, $this->policy($value)->days());
        }

        self::assertSame(CommentAgePolicy::MAX_DAYS, $this->policy('36500')->days());
    }

    private function policy(string $days): CommentAgePolicy
    {
        $config = $this->createMock(DynamicConfigProvider::class);
        $config->method('get')->with(CommentAgePolicy::CONFIG_KEY)->willReturn($days);

        return new CommentAgePolicy($config);
    }
}
