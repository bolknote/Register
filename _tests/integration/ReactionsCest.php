<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Module\Reactions\Manifest;
use Register\Module\Reactions\ReactionAggregate;
use Register\Module\Reactions\ReactionAggregateRepository;
use Register\Module\Reactions\ReactionAggregateTargetType;
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use Register\Core\Pdo\DbLayer;

final class ReactionsCest
{
    public function rendersImportedTotalsWithoutSyntheticVisitors(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var ReactionAggregateRepository $aggregateRepository */
        $aggregateRepository = $I->grabService(ReactionAggregateRepository::class);
        $contentId = $this->insertPost($dbLayer, 'imported-aggregate-reaction-post');
        foreach ([['like', '👍', 3, 'like'], ['', '🔥', 2, 'fire']] as [$reaction, $emoji, $count, $key]) {
            $aggregateRepository->store(new ReactionAggregate(
                ReactionAggregateTargetType::POST,
                $contentId,
                'test-archive',
                $key,
                $reaction,
                $emoji,
                $count,
                time(),
            ));
        }

        // Reimporting the same source identity updates it instead of double-counting it.
        $aggregateRepository->store(new ReactionAggregate(
            ReactionAggregateTargetType::POST,
            $contentId,
            'test-archive',
            'like',
            'like',
            '👍',
            3,
            time(),
            ['remote_id' => 'https://social.example/likes/1'],
        ));

        $I->amOnPage('https://localhost/imported-aggregate-reaction-post');
        $I->seeResponseCodeIs(200);
        $I->seeElement('[data-register-reactions] [data-reaction="like"][data-count="3"]');
        $I->see('🔥', '.register-reaction-imported');
        $I->see('2', '.register-reaction-imported .register-reaction-count');
        $I->assertSame(0, (int)$dbLayer->select('COUNT(*)')->from(Manifest::TABLE_NAME)->execute()->result());
        $I->assertTrue($aggregateRepository->remove(
            ReactionAggregateTargetType::POST,
            $contentId,
            'test-archive',
            'fire',
        ));
        $I->assertFalse($aggregateRepository->remove(
            ReactionAggregateTargetType::POST,
            $contentId,
            'test-archive',
            'fire',
        ));
    }

    public function rendersCompactReactionsOnEveryPostInTheBlogList(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $firstId = $this->insertPost($dbLayer, 'first-list-reaction-post');
        $secondId = $this->insertPost($dbLayer, 'second-list-reaction-post');

        $I->sendJson('https://localhost/_visitor/resolve', [
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);
        $this->react($I, 'https://localhost/_reactions/post/' . $firstId, 'love');
        $this->react($I, 'https://localhost/_reactions/post/' . $secondId, 'like');

        $I->amOnPage('https://localhost/');
        $I->seeResponseCodeIs(200);
        $I->assertCount(2, $I->grabMultiple('.post.foot > [data-register-reactions]'));
        $I->seeElement('[data-register-reactions][data-endpoint="/_reactions/post/' . $firstId . '"]');
        $I->seeElement('[data-register-reactions][data-endpoint="/_reactions/post/' . $secondId . '"]');
        $I->seeElement('[data-endpoint="/_reactions/post/' . $firstId . '"] [data-reaction="love"][data-count="1"]');
        $I->seeElement('[data-endpoint="/_reactions/post/' . $secondId . '"] [data-reaction="like"][data-count="1"]');
        $I->seeElement('[data-endpoint="/_reactions/post/' . $firstId . '"] [data-reaction="love"].register-reaction-primary');
        $I->seeElement('[data-endpoint="/_reactions/post/' . $firstId . '"] [data-reaction="like"][hidden]');
        $I->assertCount(1, $I->grabMultiple('[data-endpoint="/_reactions/post/' . $firstId . '"] .register-reaction-chip:not([hidden])'));
        $I->assertCount(2, $I->grabMultiple('.register-reaction-primary[aria-haspopup="menu"]'));
        $I->assertCount(2, $I->grabMultiple('.register-reaction-like-icon'));
        $I->dontSeeElement('.register-reaction-add');
        $I->dontSee('register_reactions:post', 'body');
    }

    public function rendersAndTogglesFacebookStyleReactions(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer   = $I->grabService(DbLayer::class);
        $contentId = $this->insertPost($dbLayer, 'reaction-post');
        $endpoint  = 'https://localhost/_reactions/post/' . $contentId;

        $I->amOnPage('https://localhost/reaction-post');
        $I->seeResponseCodeIs(200);
        $I->seeElement('[data-register-reactions]');
        $I->seeElement('[data-reaction="like"]');
        $I->seeElement('[data-picker-reaction="love"]');
        $I->seeElement('link[href$="/_assets/register/reactions/reactions.css"]');
        $I->seeElement('script[src$="/_assets/register/reactions/reactions.js"]');
        $I->seeElement('script[src$="/_assets/register/visitor/identity.js"]');
        $I->dontSeeElement('meta[name="register-visitor"][data-fingerprint-src]');

        $I->sendJson('https://localhost/_visitor/resolve', [
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);
        $I->seeResponseCodeIs(200);

        $visitor = $I->grabJson();
        $I->assertIsArray($visitor);

        /** @var VisitorIdentityManager $identityManager */
        $identityManager = $I->grabService(VisitorIdentityManager::class);
        $I->resetTestCookie($identityManager->cookieName());
        $I->sendJson('https://localhost/_visitor/resolve', [
            'token'     => $visitor['token'],
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);
        $fromStorage = $I->grabJson();
        $I->assertIsArray($fromStorage);
        $I->assertSame('storage', $fromStorage['source']);
        $I->assertSame($visitor['token'], $fromStorage['token']);

        $state = $this->react($I, $endpoint, 'like');
        $I->assertSame('like', $state['selected']);
        $I->assertSame(1, $state['counts']['like']);
        $I->assertSame(1, $state['total']);

        // Repeating the active reaction removes it.
        $state = $this->react($I, $endpoint, 'like');
        $I->assertNull($state['selected']);
        $I->assertSame(0, $state['counts']['like']);

        $this->react($I, $endpoint, 'love');
        $state = $this->react($I, $endpoint, 'haha');
        $I->assertSame('haha', $state['selected']);
        $I->assertSame(0, $state['counts']['love']);
        $I->assertSame(1, $state['counts']['haha']);
        $I->assertSame(1, $state['total']);

        // The signed browser-storage token restores the same reaction identity after cookie loss.
        $I->resetTestCookie($identityManager->cookieName());
        $I->sendJson('https://localhost/_visitor/resolve', [
            'token'     => $visitor['token'],
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);
        $recovered = $I->grabJson();
        $I->assertIsArray($recovered);
        $I->assertSame('storage', $recovered['source']);
        $I->assertSame($visitor['token'], $recovered['token']);

        $state = $this->react($I, $endpoint, 'haha');
        $I->assertNull($state['selected']);
        $I->assertSame(0, $state['total']);
        $I->assertSame(0, (int)$dbLayer->select('COUNT(*)')->from(Manifest::TABLE_NAME)->execute()->result());
    }

    public function rejectsCrossOriginMutationAndUnknownContent(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer   = $I->grabService(DbLayer::class);
        $contentId = $this->insertPost($dbLayer, 'guarded-reaction-post');

        $I->sendJson('https://localhost/_visitor/resolve', [
            'trackPage' => false,
        ], headers: ['Origin' => 'https://localhost']);

        $I->sendJson('https://localhost/_reactions/post/' . $contentId, [
            'reaction' => 'love',
        ], headers: ['Origin' => 'https://attacker.example']);
        $I->seeResponseCodeIs(403);
        $I->assertSame(0, (int)$dbLayer->select('COUNT(*)')->from(Manifest::TABLE_NAME)->execute()->result());

        $I->amOnPage('https://localhost/_reactions/post/999999');
        $I->seeResponseCodeIs(404);
    }

    public function ignoresLegacyBrowserFingerprintPayload(\IntegrationTester $I): void
    {
        /** @var VisitorIdentityManager $identityManager */
        $identityManager = $I->grabService(VisitorIdentityManager::class);
        $I->resetTestCookie($identityManager->cookieName());

        $fingerprint = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $I->sendJson('https://localhost/_visitor/resolve', [
            'fingerprint' => $fingerprint,
            'trackPage'   => false,
        ], headers: ['Origin' => 'https://localhost']);
        $first = $I->grabJson();
        $I->assertIsArray($first);
        $I->assertSame('new', $first['source']);

        $I->resetTestCookie($identityManager->cookieName());
        $I->sendJson('https://localhost/_visitor/resolve', [
            'fingerprint' => $fingerprint,
            'trackPage'   => false,
        ], headers: ['Origin' => 'https://localhost']);
        $second = $I->grabJson();
        $I->assertIsArray($second);
        $I->assertSame('new', $second['source']);
        $I->assertNotSame($first['token'], $second['token']);
    }

    /** @return array{success: true, counts: array<string, int>, selected: string|null, total: int} */
    private function react(\IntegrationTester $I, string $endpoint, string $reaction): array
    {
        $I->sendJson($endpoint, ['reaction' => $reaction], headers: ['Origin' => 'https://localhost']);
        $I->seeResponseCodeIs(200);

        $state = $I->grabJson();
        $I->assertIsArray($state);
        if (($state['success'] ?? null) !== true || !\is_array($state['counts'] ?? null)) {
            throw new \UnexpectedValueException('The reaction endpoint returned an invalid state.');
        }

        $counts = [];
        foreach ($state['counts'] as $reactionName => $count) {
            if (!\is_string($reactionName) || !\is_int($count)) {
                throw new \UnexpectedValueException('The reaction endpoint returned invalid counts.');
            }

            $counts[$reactionName] = $count;
        }

        $selected = $state['selected'] ?? null;
        $total    = $state['total'] ?? null;
        if (($selected !== null && !\is_string($selected)) || !\is_int($total)) {
            throw new \UnexpectedValueException('The reaction endpoint returned invalid summary values.');
        }

        return [
            'success'  => true,
            'counts'   => $counts,
            'selected' => $selected,
            'total'    => $total,
        ];
    }

    private function insertPost(DbLayer $dbLayer, string $slug): int
    {
        $now = time();
        $dbLayer->insert(ContentSchema::TABLE_NAME)->values([
            'content_type' => ':content_type',
            'parent_id'    => 'NULL',
            'slug_scope'   => "'root'",
            'slug'         => ':slug',
            'title'        => ':title',
            'excerpt'      => "''",
            'body'         => ':body',
            'created_at'   => ':now',
            'published_at' => ':now',
            'updated_at'   => ':now',
            'published'    => '1',
        ])->execute([
            'content_type' => ContentType::POST->value,
            'slug'         => $slug,
            'title'        => 'Reaction post',
            'body'         => '<p>A post that can receive reactions.</p>',
            'now'          => $now,
        ]);

        return (int)$dbLayer->insertId();
    }
}
