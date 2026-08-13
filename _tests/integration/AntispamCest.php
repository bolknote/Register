<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace integration;

use Psr\Log\LoggerInterface;
use S2\Cms\Comment\AkismetProxy;
use S2\Cms\Comment\Antispam\CommentFormTokenManager;
use S2\Cms\Comment\Antispam\ConfigurableSpamDetector;
use S2\Cms\Comment\Antispam\LocalSpamDetector;
use S2\Cms\Comment\Antispam\SpamAssessment;
use S2\Cms\Comment\Antispam\SpamAssessmentRepository;
use S2\Cms\Comment\Antispam\SpamFeedbackService;
use S2\Cms\Comment\Antispam\SpamFeatureExtractor;
use S2\Cms\Comment\Antispam\SpamIdentityHasher;
use S2\Cms\Comment\Antispam\SpamMaintenance;
use S2\Cms\Comment\Antispam\SpamMetricsRepository;
use S2\Cms\Comment\Antispam\SpamRateLimiter;
use S2\Cms\Comment\Antispam\SpamRatePolicyRepository;
use S2\Cms\Comment\Antispam\SpamReputationRepository;
use S2\Cms\Comment\Antispam\SpamRiskScorer;
use S2\Cms\Comment\Antispam\SpamRuleRepository;
use S2\Cms\Comment\Antispam\SpamSignalPolicyRepository;
use S2\Cms\Comment\SpamDetectorComment;
use S2\Cms\Comment\SpamDetectorReport;
use S2\Cms\Config\DynamicConfigProvider;
use S2\Cms\Model\Installer;
use S2\Cms\Model\MigrationManager;
use S2\Cms\Pdo\DbLayer;
use S2\Cms\Pdo\DbLayerSqlite;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group spam
 */
final class AntispamCest
{
    public function testCommentFormRendersServerProtection(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->insert('articles')
            ->setValue('parent_id', '1')
            ->setValue('title', "'Form test'")
            ->setValue('excerpt', "''")
            ->setValue('pagetext', "'Page text'")
            ->setValue('create_time', ':now')->setParameter('now', time())
            ->setValue('modify_time', ':now')
            ->setValue('revision', '1')
            ->setValue('priority', '0')
            ->setValue('published', '1')
            ->setValue('favorite', '0')
            ->setValue('commented', '1')
            ->setValue('url', "'form-test'")
            ->setValue('template', "'site.php'")
            ->execute()
        ;

        $I->amOnPage('http://s2.localhost/form-test');
        $I->seeResponseCodeIs(200);

        $response = $I->grabResponse();
        $etag = $I->grabHttpHeader('ETag');
        $I->assertStringContainsString('name="antispam_token"', $response);
        $I->assertStringContainsString('name="homepage"', $response);
        $I->assertStringNotContainsString('name="question"', $response);
        $I->assertStringNotContainsString('name="key"', $response);

        $cookie = (string)$I->grabHttpHeader('Set-Cookie');
        $I->assertStringContainsString('_antispam=', $cookie);
        $I->assertStringContainsString('httponly', mb_strtolower($cookie));
        $I->assertStringContainsString('samesite=lax', mb_strtolower($cookie));

        $I->amOnPage('http://s2.localhost/form-test');
        $I->assertNotSame($etag, $I->grabHttpHeader('ETag'), 'A fresh one-time form token must invalidate the old ETag.');
    }

    public function testFormTokenValidationAndReplayProtection(\IntegrationTester $I): void
    {
        /** @var CommentFormTokenManager $manager */
        $manager      = $I->grabService(CommentFormTokenManager::class);
        $request      = Request::create('https://s2.localhost/article');
        $visitorToken = $manager->getOrCreateVisitorToken($request);
        $visitorCookie = $manager->createVisitorCookie($visitorToken, $request);
        $request->cookies->set($visitorCookie->getName(), $visitorToken);
        $token        = $manager->issue('/article', $visitorToken, 1_000);

        $preview = $manager->validateAndMaybeConsume($token, $request, false, 1_005);
        $I->assertTrue($preview->valid);
        $I->assertSame(5, $preview->ageSeconds);

        $firstSubmit = $manager->validateAndMaybeConsume($token, $request, true, 1_005);
        $I->assertTrue($firstSubmit->valid);

        $replay = $manager->validateAndMaybeConsume($token, $request, true, 1_006);
        $I->assertFalse($replay->valid);
        $I->assertSame('replayed', $replay->error);

        $wrongTargetRequest = Request::create('https://s2.localhost/other');
        $wrongTargetRequest->cookies->set($visitorCookie->getName(), $visitorToken);

        $wrongTarget = $manager->validateAndMaybeConsume($manager->issue('/article', $visitorToken, 1_000), $wrongTargetRequest, false, 1_005);
        $I->assertFalse($wrongTarget->valid);
        $I->assertSame('target', $wrongTarget->error);

        $expired = $manager->validateAndMaybeConsume($manager->issue('/article', $visitorToken, 1_000), $request, false, 1_000 + CommentFormTokenManager::FORM_TOKEN_TTL + 1);
        $I->assertFalse($expired->valid);
        $I->assertSame('expired', $expired->error);

        $missingVisitor = Request::create('https://s2.localhost/article');
        $unbound = $manager->validateAndMaybeConsume($manager->issue('/article', $visitorToken, 1_000), $missingVisitor, false, 1_005);
        $I->assertFalse($unbound->valid);
        $I->assertSame('visitor', $unbound->error);
    }

    public function testInvalidFormAndHoneypotAreRejected(\IntegrationTester $I): void
    {
        $before = $this->commentCount($I);
        $data   = $this->commentData('A normal comment');

        $I->sendPost('http://s2.localhost/', [...$data, 'antispam_token' => 'invalid']);
        $I->seeResponseCodeIs(200);
        $I->see('The comment form is invalid or has expired');

        $I->sendPost('http://s2.localhost/', [...$data, 'homepage' => 'https://spam.example']);
        $I->seeResponseCodeIs(200);
        $I->see('Your comment cannot be saved because it contains spam');
        $I->assertSame($before, $this->commentCount($I));
    }

    public function testControllerRejectsReplayedToken(\IntegrationTester $I): void
    {
        /** @var CommentFormTokenManager $manager */
        $manager      = $I->grabService(CommentFormTokenManager::class);
        $request      = Request::create('http://s2.localhost/');
        $visitorToken = $manager->getOrCreateVisitorToken($request);
        $token        = $manager->issue('/', $visitorToken, time() - 5);
        $data         = [...$this->commentData('Only one copy must be saved'), 'antispam_token' => $token];
        $before       = $this->commentCount($I);

        $I->sendPostWithAntispamVisitor('http://s2.localhost/', $data, $visitorToken);
        $I->seeResponseCodeIs(302);
        $I->assertSame($before + 1, $this->commentCount($I));

        $I->sendPostWithAntispamVisitor('http://s2.localhost/', $data, $visitorToken);
        $I->seeResponseCodeIs(200);
        $I->see('The comment form is invalid or has expired');
        $I->assertSame($before + 1, $this->commentCount($I));
    }

    public function testControllerReturns429ForRateLimit(\IntegrationTester $I): void
    {
        /** @var CommentFormTokenManager $manager */
        $manager      = $I->grabService(CommentFormTokenManager::class);
        $request      = Request::create('http://s2.localhost/');
        $visitorToken = $manager->getOrCreateVisitorToken($request);

        for ($i = 1; $i <= 4; ++$i) {
            $I->sendPostWithAntispamVisitor('http://s2.localhost/', [
                ...$this->commentData('Rate test message ' . $i),
                'antispam_token' => $manager->issue('/', $visitorToken, time() - 5),
            ], $visitorToken);
            $I->seeResponseCodeIs(302);
        }

        $I->sendPostWithAntispamVisitor('http://s2.localhost/', [
            ...$this->commentData('Rate test message 5'),
            'antispam_token' => $manager->issue('/', $visitorToken, time() - 5),
        ], $visitorToken);
        $I->seeResponseCodeIs(429);

        $retryAfter = (int)$I->grabHttpHeader('Retry-After');
        $I->assertGreaterThanOrEqual(600, $retryAfter);
        $I->assertLessThanOrEqual(601, $retryAfter);
    }

    public function testIndependentSqlRateLimits(\IntegrationTester $I): void
    {
        /** @var SpamRateLimiter $limiter */
        $limiter = $I->grabService(SpamRateLimiter::class);

        for ($i = 1; $i <= 4; ++$i) {
            $result = $limiter->consume('192.0.2.' . $i, 'same@example.test', 'unique email text ' . $i, 'email-visitor-' . $i);
            $I->assertFalse($result->isLimited());
        }

        $emailLimit = $limiter->consume('192.0.2.10', 'same@example.test', 'unique email text 5', 'email-visitor-5');
        $I->assertContains('email', $emailLimit->violations);

        for ($i = 1; $i <= 5; ++$i) {
            $result = $limiter->consume('198.51.100.10', 'ip-' . $i . '@example.test', 'unique ip text ' . $i, 'ip-visitor-' . $i);
            $I->assertFalse($result->isLimited());
        }

        $ipLimit = $limiter->consume('198.51.100.10', 'ip-6@example.test', 'unique ip text 6', 'ip-visitor-6');
        $I->assertContains('ip', $ipLimit->violations);
    }

    public function testRateLimitPoliciesAreAppliedWithoutCodeChanges(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->update('spam_rate_policies')
            ->set('request_limit', '1')
            ->set('window_seconds', '120')
            ->where("bucket_type = 'email'")
            ->execute()
        ;

        /** @var SpamRateLimiter $limiter */
        $limiter = $I->grabService(SpamRateLimiter::class);
        $first = $limiter->consume('192.0.2.1', 'policy@example.test', 'first policy text', 'policy-visitor-1');
        $second = $limiter->consume('192.0.2.2', 'policy@example.test', 'second policy text', 'policy-visitor-2');

        $I->assertFalse($first->isLimited());
        $I->assertSame(['email'], $second->violations);
        $I->assertGreaterThanOrEqual(120, $second->retryAfter);
        $I->assertLessThanOrEqual(121, $second->retryAfter);
    }

    public function testRateLimitPoliciesCanBeDisabledAndAreClampedOnRead(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->update('spam_rate_policies')
            ->set('request_limit', '5000')
            ->set('window_seconds', '1')
            ->where("bucket_type = 'ip'")
            ->execute()
        ;

        /** @var SpamRatePolicyRepository $policies */
        $policies = $I->grabService(SpamRatePolicyRepository::class);
        $ipPolicy = $policies->getPolicies()['ip'];
        $I->assertSame(1_000, $ipPolicy->limit);
        $I->assertSame(10, $ipPolicy->windowSeconds);

        $dbLayer
            ->update('spam_rate_policies')
            ->set('enabled', '0')
            ->execute()
        ;

        /** @var SpamRateLimiter $limiter */
        $limiter = $I->grabService(SpamRateLimiter::class);
        $result = $limiter->consume('192.0.2.1', 'disabled@example.test', 'Disabled policies', 'disabled-visitor');

        $I->assertTrue($result->available);
        $I->assertFalse($result->isLimited());
        $I->assertSame(0, $this->rowCount($dbLayer, 'spam_rate_events'));
    }

    public function testSignalWeightsAreAppliedWithoutCodeChanges(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->update('spam_signal_policies')
            ->set('weight', '47')
            ->where("signal = 'links_one'")
            ->execute()
        ;

        /** @var LocalSpamDetector $detector */
        $detector = $I->grabService(LocalSpamDetector::class);
        $report = $detector->getReport(new SpamDetectorComment(
            'Reader',
            'reader@example.test',
            str_repeat('Useful context ', 6) . 'https://example.test/details',
            'Mozilla/5.0',
            'https://s2.localhost/',
            'https://s2.localhost/',
            10,
        ), '203.0.113.40');

        $I->assertSame(SpamDetectorReport::STATUS_SPAM, $report->status);
        $I->assertSame(47, $report->getScore());
        $I->assertSame(47, $report->getReasons()['links']);
    }

    public function testDisablingConfirmedDuplicateRemovesItsHardBlock(\IntegrationTester $I): void
    {
        $text = 'A previously confirmed duplicate';
        /** @var SpamIdentityHasher $hasher */
        $hasher = $I->grabService(SpamIdentityHasher::class);
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->insert('spam_reputation')
            ->setValue('key_type', "'text'")
            ->setValue('key_hash', ':key_hash')->setParameter('key_hash', $hasher->text($text))
            ->setValue('ham_count', '0')
            ->setValue('spam_count', '2')
            ->setValue('last_seen', ':now')->setParameter('now', time())
            ->setValue('expires_at', ':expires_at')->setParameter('expires_at', time() + 3_600)
            ->execute()
        ;
        $dbLayer
            ->update('spam_signal_policies')
            ->set('enabled', '0')
            ->where("signal = 'confirmed_spam_duplicate'")
            ->execute()
        ;

        /** @var SpamRiskScorer $scorer */
        $scorer = $I->grabService(SpamRiskScorer::class);
        $assessment = $scorer->assess(new SpamDetectorComment(
            'Reader',
            'reader@example.test',
            $text,
            'Mozilla/5.0',
            'https://s2.localhost/',
            'https://s2.localhost/',
            10,
        ), '203.0.113.41');

        $I->assertFalse($assessment->hardBlock);
        $I->assertArrayNotHasKey('confirmed_spam_duplicate', $assessment->reasons);
    }

    public function testLocalDetectorAuditsRulesAndScores(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->insert('spam_rules')
            ->setValue('type', "'phrase'")
            ->setValue('pattern', "'buy now'")
            ->setValue('weight', '5')
            ->setValue('action', "'block'")
            ->setValue('enabled', '1')
            ->setValue('expires_at', 'NULL')
            ->setValue('note', "''")
            ->execute()
        ;
        $ruleId = (int)$dbLayer->insertId();

        /** @var LocalSpamDetector $detector */
        $detector = $I->grabService(LocalSpamDetector::class);
        $report   = $detector->getReport(new SpamDetectorComment(
            'Spammer',
            'sender@example.test',
            'Please BUY NOW',
            'Mozilla/5.0',
            'https://s2.localhost/',
            'https://s2.localhost/',
            10,
        ), '203.0.113.1');

        $I->assertSame(SpamDetectorReport::STATUS_BLATANT, $report->status);
        $I->assertTrue($report->shouldReject());
        $I->assertNotNull($report->getAssessmentId());
        $I->assertSame(5, $report->getScore());
        $I->assertSame(5, $report->getReasons()['rule_' . $ruleId]);

        $I->assertSame(1, $this->rowCount($dbLayer, 'spam_assessments'));
    }

    public function testHighSoftScoreIsQuarantined(\IntegrationTester $I): void
    {
        /** @var LocalSpamDetector $detector */
        $detector = $I->grabService(LocalSpamDetector::class);
        $report   = $detector->getReport(new SpamDetectorComment(
            'Reader',
            'reader@example.test',
            "http://one.test http://two.test http://three.test \u{202E}aaaaaaaaaa",
            formAgeSeconds: 0,
        ), '203.0.113.2');

        $I->assertSame(SpamDetectorReport::STATUS_BLATANT, $report->status);
        $I->assertFalse($report->shouldReject());

        $assessmentId = $report->getAssessmentId();
        $I->assertNotNull($assessmentId);
        /** @var SpamAssessmentRepository $repository */
        $repository = $I->grabService(SpamAssessmentRepository::class);
        $repository->attachComment($assessmentId, 'blog', 123_456);

        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $attachment = $dbLayer
            ->select('target_type', 'comment_id')
            ->from('spam_assessments')
            ->where('id = :id')->setParameter('id', $assessmentId)
            ->execute()
            ->fetchAssoc()
        ;
        if ($attachment === false) {
            throw new \RuntimeException('The blog assessment attachment was not found.');
        }

        $I->assertSame('blog', $attachment['target_type']);
        $I->assertSame(123_456, (int)$attachment['comment_id']);
    }

    public function testShadowModeFallsBackToLocalVerdictWithoutAkismetKey(\IntegrationTester $I): void
    {
        $configProvider = new class extends DynamicConfigProvider {
            #[\Override]
            public function get(string $paramName): mixed
            {
                return $paramName === 'mode'
                    ? 'shadow'
                    : throw new \LogicException('Unexpected test configuration parameter.');
            }
        };
        $detector = new ConfigurableSpamDetector(
            $I->grabService(LocalSpamDetector::class),
            $I->grabService(AkismetProxy::class),
            $I->grabService(SpamAssessmentRepository::class),
            $configProvider->getStringProxy('mode'),
            $I->grabService(LoggerInterface::class),
        );
        $report   = $detector->getReport(new SpamDetectorComment(
            'Reader',
            'reader@example.test',
            "http://one.test http://two.test http://three.test \u{202E}aaaaaaaaaa",
            formAgeSeconds: 0,
        ), '203.0.113.3');

        $I->assertSame(SpamDetectorReport::STATUS_BLATANT, $report->status);
        $I->assertNotNull($report->getAssessmentId());
        $I->assertFalse($report->shouldReject());
    }

    public function testAkismetModeFallsBackToLocalVerdictWithoutAkismetKey(\IntegrationTester $I): void
    {
        $configProvider = new class extends DynamicConfigProvider {
            #[\Override]
            public function get(string $paramName): mixed
            {
                return $paramName === 'mode'
                    ? 'akismet'
                    : throw new \LogicException('Unexpected test configuration parameter.');
            }
        };
        $detector = new ConfigurableSpamDetector(
            $I->grabService(LocalSpamDetector::class),
            $I->grabService(AkismetProxy::class),
            $I->grabService(SpamAssessmentRepository::class),
            $configProvider->getStringProxy('mode'),
            $I->grabService(LoggerInterface::class),
        );
        $report = $detector->getReport(new SpamDetectorComment(
            'Reader',
            'reader@example.test',
            "http://one.test http://two.test http://three.test \u{202E}aaaaaaaaaa",
            formAgeSeconds: 0,
        ), '203.0.113.4');

        $I->assertSame(SpamDetectorReport::STATUS_BLATANT, $report->status);
        $I->assertNotNull($report->getAssessmentId());
        $I->assertFalse($report->shouldReject());
    }

    public function testModeratorFeedbackChangesReputationAndVisibility(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->insert('art_comments')
            ->setValue('article_id', '1')
            ->setValue('time', ':time')->setParameter('time', time() - 60)
            ->setValue('ip', "'198.51.100.20'")
            ->setValue('nick', "'Subscriber'")
            ->setValue('email', "'subscriber@example.test'")
            ->setValue('show_email', '0')
            ->setValue('subscribed', '1')
            ->setValue('shown', '1')
            ->setValue('sent', '1')
            ->setValue('good', '0')
            ->setValue('text', "'Earlier comment'")
            ->execute()
        ;
        $dbLayer
            ->insert('art_comments')
            ->setValue('article_id', '1')
            ->setValue('time', ':time')->setParameter('time', time())
            ->setValue('ip', "'203.0.113.5'")
            ->setValue('nick', "'Reader'")
            ->setValue('email', "'reader@example.test'")
            ->setValue('show_email', '0')
            ->setValue('subscribed', '0')
            ->setValue('shown', '0')
            ->setValue('sent', '0')
            ->setValue('good', '0')
            ->setValue('text', "'Useful comment'")
            ->execute()
        ;
        $commentId = (int)$dbLayer->insertId();

        /** @var SpamFeedbackService $feedback */
        $feedback = $I->grabService(SpamFeedbackService::class);
        $I->assertTrue($feedback->markSpam($commentId));

        $comment = $dbLayer
            ->select('shown', 'sent')
            ->from('art_comments')
            ->where('id = :id')->setParameter('id', $commentId)
            ->execute()
            ->fetchAssoc()
        ;
        if ($comment === false) {
            throw new \RuntimeException('The test comment was not found.');
        }

        $I->assertSame(0, (int)$comment['shown']);
        $I->assertSame(1, (int)$comment['sent']);

        /** @var SpamIdentityHasher $hasher */
        $hasher = $I->grabService(SpamIdentityHasher::class);
        $textHash = $hasher->text('Useful comment');
        $reputation = $this->reputation($dbLayer, $textHash);
        $I->assertSame(0, (int)$reputation['ham_count']);
        $I->assertSame(1, (int)$reputation['spam_count']);

        $I->assertTrue($feedback->markHam($commentId));

        $comment = $dbLayer
            ->select('shown')
            ->from('art_comments')
            ->where('id = :id')->setParameter('id', $commentId)
            ->execute()
            ->fetchAssoc()
        ;
        if ($comment === false) {
            throw new \RuntimeException('The test comment was not found.');
        }

        $I->assertSame(1, (int)$comment['shown']);
        $I->assertCount(1, $I->grabSubscriberMails());

        $reputation = $this->reputation($dbLayer, $textHash);
        $I->assertSame(1, (int)$reputation['ham_count']);
        $I->assertSame(0, (int)$reputation['spam_count']);
    }

    public function testBlogModeratorFeedbackUsesBlogAssessment(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->insert('s2_blog_posts')
            ->setValue('create_time', ':time')->setParameter('time', time())
            ->setValue('modify_time', ':time')
            ->setValue('revision', '1')
            ->setValue('title', "'Feedback post'")
            ->setValue('text', "'Post text'")
            ->setValue('published', '1')
            ->setValue('favorite', '0')
            ->setValue('commented', '1')
            ->setValue('label', "''")
            ->setValue('url', "'feedback-post'")
            ->setValue('user_id', 'NULL')
            ->execute()
        ;
        $postId = (int)$dbLayer->insertId();

        $dbLayer
            ->insert('s2_blog_comments')
            ->setValue('post_id', ':post_id')->setParameter('post_id', $postId)
            ->setValue('time', ':time')->setParameter('time', time())
            ->setValue('ip', "'203.0.113.6'")
            ->setValue('nick', "'Blog reader'")
            ->setValue('email', "'blog-reader@example.test'")
            ->setValue('show_email', '0')
            ->setValue('subscribed', '0')
            ->setValue('shown', '0')
            ->setValue('sent', '0')
            ->setValue('good', '0')
            ->setValue('text', "'Blog feedback comment'")
            ->execute()
        ;
        $commentId = (int)$dbLayer->insertId();

        /** @var SpamFeedbackService $feedback */
        $feedback = $I->grabService(SpamFeedbackService::class);
        $I->assertTrue($feedback->markSpam($commentId, 'blog', 's2_blog_comments'));

        $notifiedCommentId = null;
        $notifier          = static function (int $id) use (&$notifiedCommentId): void {
            $notifiedCommentId = $id;
        };
        $I->assertTrue($feedback->markHam($commentId, 'blog', 's2_blog_comments', $notifier));
        $I->assertSame($commentId, $notifiedCommentId);

        $comment = $dbLayer
            ->select('shown', 'sent')
            ->from('s2_blog_comments')
            ->where('id = :id')->setParameter('id', $commentId)
            ->execute()
            ->fetchAssoc()
        ;
        if ($comment === false) {
            throw new \RuntimeException('The blog test comment was not found.');
        }

        $I->assertSame(1, (int)$comment['shown']);
        $I->assertSame(0, (int)$comment['sent']);

        $assessment = $dbLayer
            ->select('target_type', 'moderator_label')
            ->from('spam_assessments')
            ->where('comment_id = :comment_id')->setParameter('comment_id', $commentId)
            ->andWhere('target_type = :target_type')->setParameter('target_type', 'blog')
            ->orderBy('id DESC')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;
        if ($assessment === false) {
            throw new \RuntimeException('The blog spam assessment was not found.');
        }

        $I->assertSame('blog', $assessment['target_type']);
        $I->assertSame('ham', $assessment['moderator_label']);
    }

    public function testHamAfterRejectNotifiesSubscribers(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->insert('art_comments')
            ->setValue('article_id', '1')
            ->setValue('time', ':time')->setParameter('time', time() - 60)
            ->setValue('ip', "'198.51.100.21'")
            ->setValue('nick', "'Subscriber'")
            ->setValue('email', "'subscriber-2@example.test'")
            ->setValue('show_email', '0')
            ->setValue('subscribed', '1')
            ->setValue('shown', '1')
            ->setValue('sent', '1')
            ->setValue('good', '0')
            ->setValue('text', "'Earlier subscriber comment'")
            ->execute()
        ;
        $dbLayer
            ->insert('art_comments')
            ->setValue('article_id', '1')
            ->setValue('time', ':time')->setParameter('time', time())
            ->setValue('ip', "'203.0.113.7'")
            ->setValue('nick', "'Rejected reader'")
            ->setValue('email', "'rejected-reader@example.test'")
            ->setValue('show_email', '0')
            ->setValue('subscribed', '0')
            ->setValue('shown', '0')
            ->setValue('sent', '1')
            ->setValue('good', '0')
            ->setValue('text', "'A rejected but valid comment'")
            ->execute()
        ;
        $commentId = (int)$dbLayer->insertId();

        /** @var SpamFeedbackService $feedback */
        $feedback = $I->grabService(SpamFeedbackService::class);
        $I->assertTrue($feedback->markHam($commentId));
        $I->assertCount(1, $I->grabSubscriberMails());

        $comment = $dbLayer
            ->select('shown', 'sent')
            ->from('art_comments')
            ->where('id = :id')->setParameter('id', $commentId)
            ->execute()
            ->fetchAssoc()
        ;
        if ($comment === false) {
            throw new \RuntimeException('The rejected test comment was not found.');
        }

        $I->assertSame(1, (int)$comment['shown']);
        $I->assertSame(1, (int)$comment['sent']);
    }

    public function testMetricsCompareLocalShadowAndModeratorDecisions(\IntegrationTester $I): void
    {
        /** @var SpamAssessmentRepository $assessments */
        $assessments = $I->grabService(SpamAssessmentRepository::class);
        $assessment = new SpamAssessment(
            50,
            ['links' => 50],
            str_repeat('a', 64),
            str_repeat('b', 64),
            str_repeat('c', 64),
            [],
        );

        $falsePositiveId = $assessments->save(
            $assessment,
            SpamDetectorReport::STATUS_SPAM,
            targetType: 'article',
            commentId: 101,
        );
        $assessments->setShadowStatus($falsePositiveId, SpamDetectorReport::STATUS_HAM);
        $assessments->labelComment(101, 'ham', $assessment);

        $falseNegativeId = $assessments->save(
            $assessment,
            SpamDetectorReport::STATUS_HAM,
            targetType: 'article',
            commentId: 102,
        );
        $assessments->setShadowStatus($falseNegativeId, SpamDetectorReport::STATUS_SPAM);
        $assessments->labelComment(102, 'spam', $assessment);

        $failedId = $assessments->save(
            $assessment,
            SpamDetectorReport::STATUS_FAILED,
            targetType: 'article',
            commentId: 103,
        );
        $assessments->setShadowStatus($failedId, SpamDetectorReport::STATUS_FAILED);
        $assessments->labelComment(103, 'ham', $assessment);

        /** @var SpamMetricsRepository $metricsRepository */
        $metricsRepository = $I->grabService(SpamMetricsRepository::class);
        $metrics = $metricsRepository->summarize();

        $I->assertSame(3, $metrics['total']);
        $I->assertSame(1, $metrics['failed']);
        $I->assertSame(1, $metrics['labelled_ham']);
        $I->assertSame(1, $metrics['labelled_spam']);
        $I->assertSame(1, $metrics['local_false_positive']);
        $I->assertSame(1, $metrics['local_false_negative']);
        $I->assertSame(2, $metrics['shadow_total']);
        $I->assertSame(0, $metrics['shadow_agreement']);
        $I->assertSame(1, $metrics['shadow_labelled_ham']);
        $I->assertSame(1, $metrics['shadow_labelled_spam']);
        $I->assertSame(0, $metrics['shadow_false_positive']);
        $I->assertSame(0, $metrics['shadow_false_negative']);
    }

    public function testLocalDetectorAuditsEngineFailureWhenStorageIsAvailable(\IntegrationTester $I): void
    {
        $unavailableDb = new DbLayerSqlite(new \S2\Cms\Pdo\PDO('sqlite::memory:'));
        /** @var SpamIdentityHasher $hasher */
        $hasher = $I->grabService(SpamIdentityHasher::class);
        /** @var SpamFeatureExtractor $featureExtractor */
        $featureExtractor = $I->grabService(SpamFeatureExtractor::class);
        $configProvider = new class extends DynamicConfigProvider {
            #[\Override]
            public function get(string $paramName): mixed
            {
                return match ($paramName) {
                    'spam_threshold' => 35,
                    'blatant_threshold' => 80,
                    default => throw new \LogicException('Unexpected test configuration parameter.'),
                };
            }
        };

        $detector = new LocalSpamDetector(
            new SpamRiskScorer(
                $hasher,
                $featureExtractor,
                new SpamReputationRepository($unavailableDb),
                new SpamRuleRepository($unavailableDb),
                new SpamSignalPolicyRepository($unavailableDb),
            ),
            $I->grabService(SpamAssessmentRepository::class),
            $hasher,
            $featureExtractor,
            $I->grabService(LoggerInterface::class),
            $configProvider->getIntProxy('spam_threshold'),
            $configProvider->getIntProxy('blatant_threshold'),
        );

        $report = $detector->getReport(new SpamDetectorComment(
            'Reader',
            'reader@example.test',
            'A comment whose scoring engine fails',
        ), '203.0.113.99');

        $I->assertSame(SpamDetectorReport::STATUS_FAILED, $report->status);
        $I->assertNotNull($report->getAssessmentId());

        /** @var SpamMetricsRepository $metricsRepository */
        $metricsRepository = $I->grabService(SpamMetricsRepository::class);
        $metrics = $metricsRepository->summarize();
        $I->assertSame(1, $metrics['total']);
        $I->assertSame(1, $metrics['failed']);
    }

    public function testMaintenanceKeepsCurrentRows(\IntegrationTester $I): void
    {
        $now = time();
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        foreach ([$now - 100_000, $now] as $createdAt) {
            $dbLayer
                ->insert('spam_rate_events')
                ->setValue('bucket_type', "'ip'")
                ->setValue('bucket_key', ':bucket_key')->setParameter('bucket_key', hash('sha256', (string)$createdAt))
                ->setValue('created_at', ':created_at')->setParameter('created_at', $createdAt)
                ->execute()
            ;
        }

        foreach ([$now - 1, $now + 1] as $expiresAt) {
            $dbLayer
                ->insert('spam_form_nonces')
                ->setValue('nonce_hash', ':nonce_hash')->setParameter('nonce_hash', hash('sha256', (string)$expiresAt))
                ->setValue('expires_at', ':expires_at')->setParameter('expires_at', $expiresAt)
                ->execute()
            ;
        }

        /** @var LocalSpamDetector $detector */
        $detector = $I->grabService(LocalSpamDetector::class);
        /** @var SpamAssessmentRepository $assessmentRepository */
        $assessmentRepository = $I->grabService(SpamAssessmentRepository::class);
        foreach (['article', 'blog'] as $targetType) {
            $report = $detector->getReport(new SpamDetectorComment(
                'Reader',
                'reader@example.test',
                'Orphan assessment for ' . $targetType,
            ), '203.0.113.30');
            $assessmentId = $report->getAssessmentId();
            if ($assessmentId === null) {
                throw new \RuntimeException('The orphan assessment was not stored.');
            }

            $assessmentRepository->attachComment($assessmentId, $targetType, 999_999);
        }

        /** @var SpamMaintenance $maintenance */
        $maintenance = $I->grabService(SpamMaintenance::class);
        $deleted     = $maintenance->run($now);
        $I->assertSame(1, $deleted['rate_events']);
        $I->assertSame(1, $deleted['form_nonces']);
        $I->assertSame(1, $deleted['article_assessment_orphans']);
        $I->assertSame(1, $deleted['blog_assessment_orphans']);

        $I->assertSame(1, $this->rowCount($dbLayer, 'spam_rate_events'));
        $I->assertSame(1, $this->rowCount($dbLayer, 'spam_form_nonces'));
    }

    public function testRevision26MigrationCreatesPoliciesAndShadowModeForExistingAkismetKey(\IntegrationTester $I): void
    {
        $legacyDb = new DbLayerSqlite(new \S2\Cms\Pdo\PDO('sqlite::memory:'));
        $installer = new Installer($legacyDb);
        $installer->createTables();
        $installer->insertConfigData('Legacy site', 'admin@example.test', 'English', 24);
        foreach ([
                     'spam_rate_policies',
                     'spam_signal_policies',
                     'spam_rules',
                     'spam_form_nonces',
                     'spam_rate_events',
                     'spam_reputation',
                     'spam_assessments',
                 ] as $table) {
            $legacyDb->dropTable($table);
        }

        foreach ([
                     'S2_ANTISPAM_MODE',
                     'S2_ANTISPAM_SPAM_SCORE',
                     'S2_ANTISPAM_BLATANT_SCORE',
                 ] as $name) {
            $legacyDb
                ->delete('config')
                ->where('name = :name')->setParameter('name', $name)
                ->execute()
            ;
        }

        $legacyDb
            ->update('config')
            ->set('value', ':value')->setParameter('value', 'short')
            ->where('name = :name')->setParameter('name', 'S2_ANTISPAM_SECRET')
            ->execute()
        ;

        $legacyDb
            ->update('config')
            ->set('value', ':value')->setParameter('value', 'existing-key')
            ->where('name = :name')->setParameter('name', 'S2_AKISMET_KEY')
            ->execute()
        ;

        (new MigrationManager($legacyDb, 'sqlite'))->migrate(24, Installer::DB_REVISION);

        foreach ([
                     'spam_assessments',
                     'spam_reputation',
                     'spam_rate_events',
                     'spam_form_nonces',
                     'spam_rules',
                     'spam_signal_policies',
                     'spam_rate_policies',
                 ] as $table) {
            $I->assertTrue($legacyDb->tableExists($table));
        }

        $I->assertTrue($legacyDb->fieldExists('spam_assessments', 'target_type'));
        $I->assertSame(\count(SpamSignalPolicyRepository::DEFAULT_WEIGHTS), $this->rowCount($legacyDb, 'spam_signal_policies'));
        $I->assertSame(\count(SpamRatePolicyRepository::DEFAULT_POLICIES), $this->rowCount($legacyDb, 'spam_rate_policies'));
        $I->assertSame('shadow', $this->configValue($legacyDb, 'S2_ANTISPAM_MODE'));
        $I->assertSame('26', $this->configValue($legacyDb, 'S2_DB_REVISION'));
        $I->assertSame(64, \strlen($this->configValue($legacyDb, 'S2_ANTISPAM_SECRET')));
    }

    /** @return array<string, string> */
    private function commentData(string $text): array
    {
        return [
            'name'  => 'Reader',
            'email' => 'reader@example.test',
            'text'  => $text,
        ];
    }

    private function commentCount(\IntegrationTester $I): int
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);

        return $this->rowCount($dbLayer, 'art_comments');
    }

    private function rowCount(DbLayer $dbLayer, string $table): int
    {
        return (int)$dbLayer
            ->select('COUNT(*)')
            ->from($table)
            ->execute()
            ->result()
        ;
    }

    private function configValue(DbLayer $dbLayer, string $name): string
    {
        return (string)$dbLayer
            ->select('value')
            ->from('config')
            ->where('name = :name')->setParameter('name', $name)
            ->execute()
            ->result()
        ;
    }

    /** @return array<string, mixed> */
    private function reputation(DbLayer $dbLayer, string $textHash): array
    {
        $reputation = $dbLayer
            ->select('ham_count', 'spam_count')
            ->from('spam_reputation')
            ->where("key_type = 'text'")
            ->andWhere('key_hash = :hash')->setParameter('hash', $textHash)
            ->execute()
            ->fetchAssoc()
        ;
        if ($reputation === false) {
            throw new \RuntimeException('The test reputation row was not found.');
        }

        return $reputation;
    }
}
