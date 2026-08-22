<?php
/**
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   RegisterActivityPub
 */

declare(strict_types = 1);

namespace unit\Extensions\ActivityPub;

use Codeception\Test\Unit;
use Register\Extension\activitypub\Application\SiteActorDraft;
use Register\Extension\activitypub\Domain\ActorType;
use Register\Extension\activitypub\Domain\LocalHandle;

final class ActorProfileValidationTest extends Unit
{
    /**
     * @dataProvider invalidDraftProvider
     * @param \Closure(): SiteActorDraft $draft
     */
    public function testRejectsUnsafeOrUnboundedActorProfiles(\Closure $draft): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $draft();
    }

    /** @return iterable<string, array{\Closure(): SiteActorDraft}> */
    public static function invalidDraftProvider(): iterable
    {
        yield 'plaintext profile URL' => [static fn(): SiteActorDraft => self::draft(profileUrl: 'http://example.test/about')];
        yield 'credentialed profile URL' => [static fn(): SiteActorDraft => self::draft(profileUrl: 'https://user@example.test/about')];
        yield 'fragmented profile URL' => [static fn(): SiteActorDraft => self::draft(profileUrl: 'https://example.test/about#card')];
        yield 'markup in display name' => [static fn(): SiteActorDraft => self::draft(displayName: '<b>Journal</b>')];
        yield 'unknown media field' => [static fn(): SiteActorDraft => self::draft(avatar: [
            'url'    => 'https://example.test/avatar.png',
            'script' => 'alert(1)',
        ])];
        yield 'excessive metadata' => [static fn(): SiteActorDraft => self::draft(metadata: array_fill(
            0,
            9,
            ['name' => 'Field', 'value' => 'Value'],
        ))];
    }

    /**
     * @param array<string, scalar|null>|null $avatar
     * @param list<array{name: string, value: string}> $metadata
     */
    private static function draft(
        string $displayName = 'Journal',
        string $profileUrl = 'https://example.test/about',
        ?array $avatar = null,
        array $metadata = [],
    ): SiteActorDraft {
        return new SiteActorDraft(
            ActorType::SERVICE,
            new LocalHandle('journal'),
            $displayName,
            '<p>Summary.</p>',
            $profileUrl,
            $avatar,
            metadata: $metadata,
        );
    }
}
