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
use Register\Module\VisitorIdentity\VisitorIdentityManager;
use S2\Cms\Pdo\DbLayer;

final class ReactionsCest
{
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

        $fingerprint = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $I->sendJson('https://localhost/_visitor/resolve', [
            'fingerprint' => $fingerprint,
            'trackPage'   => false,
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

        // Cookie deletion cannot be used to add a second reaction from the same fingerprint.
        $I->resetTestCookie($identityManager->cookieName());
        $I->sendJson('https://localhost/_visitor/resolve', [
            'fingerprint' => $fingerprint,
            'trackPage'   => false,
        ], headers: ['Origin' => 'https://localhost']);
        $recovered = $I->grabJson();
        $I->assertIsArray($recovered);
        $I->assertSame('fingerprint', $recovered['source']);
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
            'fingerprint' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        ], headers: ['Origin' => 'https://localhost']);

        $I->sendJson('https://localhost/_reactions/post/' . $contentId, [
            'reaction' => 'love',
        ], headers: ['Origin' => 'https://attacker.example']);
        $I->seeResponseCodeIs(403);
        $I->assertSame(0, (int)$dbLayer->select('COUNT(*)')->from(Manifest::TABLE_NAME)->execute()->result());

        $I->amOnPage('https://localhost/_reactions/post/999999');
        $I->seeResponseCodeIs(404);
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
