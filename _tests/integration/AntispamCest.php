<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace integration;

use Psr\Log\LoggerInterface;
use Register\Auth\PublicAuthRepository;
use Register\Auth\PublicAuthSettings;
use Register\Comment\CommentSchema;
use Register\Content\ContentSchema;
use Register\Content\ContentType;
use Register\Core\Comment\AkismetProxy;
use Register\Core\Comment\Antispam\CommentFormTokenManager;
use Register\Core\Comment\Antispam\ConfigurableSpamDetector;
use Register\Core\Comment\Antispam\LocalSpamDetector;
use Register\Core\Comment\Antispam\SpamAssessment;
use Register\Comment\Antispam\SpamAssessmentRepository;
use Register\Comment\Antispam\SpamFeedbackService;
use Register\Core\Comment\Antispam\SpamFeatureExtractor;
use Register\Core\Comment\Antispam\SpamIdentityHasher;
use Register\Core\Comment\Antispam\SpamMaintenance;
use Register\Core\Comment\Antispam\SpamMetricsRepository;
use Register\Core\Comment\Antispam\SpamRateLimiter;
use Register\Core\Comment\Antispam\SpamRatePolicyRepository;
use Register\Core\Comment\Antispam\SpamReputationRepository;
use Register\Core\Comment\Antispam\SpamRiskScorer;
use Register\Core\Comment\Antispam\SpamRuleRepository;
use Register\Core\Comment\Antispam\SpamSignalPolicyRepository;
use Register\Core\Comment\Antispam\SpamTextFeatureExtractor;
use Register\Core\Comment\Antispam\SpamTextModel;
use Register\Core\Comment\Antispam\SpamTextModelRepository;
use Register\Core\Comment\SpamDetectorComment;
use Register\Core\Comment\SpamDetectorReport;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Pdo\DbLayer;
use Register\Core\Pdo\DbLayerSqlite;
use Symfony\Component\HttpFoundation\Request;

/**
 * @group spam
 */
final class AntispamCest
{
    public function _before(\IntegrationTester $I): void
    {
        $I->setConfigValue(PublicAuthSettings::EMAIL_ENABLED_CONFIG_KEY, '1');
        $I->resetTestCookie('register_cookie_904732485_c');
    }

    public function testCommentFormRendersServerProtection(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('parent_id', '1')
            ->setValue('slug_scope', "'root'")
            ->setValue('title', "'Form test'")
            ->setValue('excerpt', "''")
            ->setValue('body', "'Page text'")
            ->setValue('created_at', ':now')->setParameter('now', time())
            ->setValue('published_at', ':now')
            ->setValue('updated_at', ':now')
            ->setValue('revision', '1')
            ->setValue('sort_order', '0')
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('slug', "'form-test'")
            ->setValue('template', "'site.php'")
            ->execute()
        ;

        $I->amOnPage('http://register.localhost/form-test');
        $I->seeResponseCodeIs(200);

        $response = $I->grabResponse();
        $etag = $I->grabHttpHeader('ETag');
        $firstTextField = (string)$I->grabAttributeFrom('#comment-text', 'name');
        $firstTrapField = (string)$I->grabAttributeFrom('.comment-form-trap', 'name');
        $I->assertStringContainsString('name="antispam_token"', $response);
        $I->assertMatchesRegularExpression('#^cf_[0-9a-f]{24}$#', $firstTextField);
        $I->assertMatchesRegularExpression('#^cf_[0-9a-f]{24}$#', $firstTrapField);
        $I->assertNotSame($firstTextField, $firstTrapField);
        $I->assertNotSame('text', $firstTextField);
        $I->assertNotSame('homepage', $firstTrapField);
        $I->seeElement('.visually-hidden .comment-form-trap');
        $I->assertStringNotContainsString('name="question"', $response);
        $I->assertStringNotContainsString('name="key"', $response);

        $cookie = (string)$I->grabHttpHeader('Set-Cookie');
        $I->assertStringContainsString('_antispam=', $cookie);
        $I->assertStringContainsString('httponly', mb_strtolower($cookie));
        $I->assertStringContainsString('samesite=lax', mb_strtolower($cookie));

        $I->amOnPage('http://register.localhost/form-test');
        $I->assertNotSame($etag, $I->grabHttpHeader('ETag'), 'A fresh one-time form token must invalidate the old ETag.');

        $secondTextField = (string)$I->grabAttributeFrom('#comment-text', 'name');
        $I->assertNotSame($firstTextField, $secondTextField);

        $postData = $I->grabFormValues('#comment-form');
        $postData[$secondTextField] = 'A human-readable mutable form still works';
        $postData[(string)$I->grabAttributeFrom('#comment-name', 'name')] = 'Reader';
        $postData[(string)$I->grabAttributeFrom('#comment-email', 'name')] = 'reader@example.test';
        $I->sendPost('http://register.localhost/form-test', $postData);
        $I->seeResponseCodeIs(302);
    }

    public function testFormTokenValidationAndReplayProtection(\IntegrationTester $I): void
    {
        /** @var CommentFormTokenManager $manager */
        $manager      = $I->grabService(CommentFormTokenManager::class);
        $request      = Request::create('https://register.localhost/article');
        $visitorToken = $manager->getOrCreateVisitorToken($request);
        $visitorCookie = $manager->createVisitorCookie($visitorToken, $request);
        $request->cookies->set($visitorCookie->getName(), $visitorToken);
        $token        = $manager->issue('/article', $visitorToken, 1_000);

        $fieldNames = $manager->fieldNames($token);
        $I->assertCount(10, $fieldNames);
        $I->assertCount(10, array_unique($fieldNames));
        foreach ($fieldNames as $canonicalName => $fieldName) {
            $I->assertMatchesRegularExpression('#^cf_[0-9a-f]{24}$#', $fieldName);
            $I->assertNotSame($canonicalName, $fieldName);
        }

        $I->assertNotSame(
            $fieldNames,
            $manager->fieldNames($manager->issue('/article', $visitorToken, 1_000)),
        );

        $preview = $manager->validateAndMaybeConsume($token, $request, false, 1_005);
        $I->assertTrue($preview->valid);
        $I->assertSame(5, $preview->ageSeconds);

        $firstSubmit = $manager->validateAndMaybeConsume($token, $request, true, 1_005);
        $I->assertTrue($firstSubmit->valid);

        $replay = $manager->validateAndMaybeConsume($token, $request, true, 1_006);
        $I->assertFalse($replay->valid);
        $I->assertSame('replayed', $replay->error);

        $wrongTargetRequest = Request::create('https://register.localhost/other');
        $wrongTargetRequest->cookies->set($visitorCookie->getName(), $visitorToken);

        $wrongTarget = $manager->validateAndMaybeConsume($manager->issue('/article', $visitorToken, 1_000), $wrongTargetRequest, false, 1_005);
        $I->assertFalse($wrongTarget->valid);
        $I->assertSame('target', $wrongTarget->error);

        $expired = $manager->validateAndMaybeConsume($manager->issue('/article', $visitorToken, 1_000), $request, false, 1_000 + CommentFormTokenManager::FORM_TOKEN_TTL + 1);
        $I->assertFalse($expired->valid);
        $I->assertSame('expired', $expired->error);

        $missingVisitor = Request::create('https://register.localhost/article');
        $unbound = $manager->validateAndMaybeConsume($manager->issue('/article', $visitorToken, 1_000), $missingVisitor, false, 1_005);
        $I->assertFalse($unbound->valid);
        $I->assertSame('visitor', $unbound->error);
    }

    public function testInvalidFormAndHoneypotAreRejected(\IntegrationTester $I): void
    {
        $before = $this->commentCount($I);
        $data   = $this->commentData('A normal comment');

        $I->sendPost('http://register.localhost/', [...$data, 'antispam_token' => 'invalid']);
        $I->seeResponseCodeIs(200);
        $I->see('The comment form is invalid or has expired');

        $I->sendPost('http://register.localhost/', [...$data, 'homepage' => 'https://spam.example']);
        $I->seeResponseCodeIs(200);
        $I->see('Your comment cannot be saved because it contains spam');
        $I->assertSame($before, $this->commentCount($I));
    }

    public function testCanonicalCommentFieldsAreRejected(\IntegrationTester $I): void
    {
        /** @var CommentFormTokenManager $manager */
        $manager      = $I->grabService(CommentFormTokenManager::class);
        $request      = Request::create('http://register.localhost/');
        $visitorToken = $manager->getOrCreateVisitorToken($request);
        $token        = $manager->issue('/', $visitorToken, time() - 5);
        $before       = $this->commentCount($I);

        $I->sendPostWithAntispamVisitor('http://register.localhost/', [
            ...$this->commentData('A bot used the old predictable field names'),
            'antispam_token' => $token,
        ], $visitorToken, false);

        $I->seeResponseCodeIs(200);
        $I->see('The comment form is invalid or has expired');
        $I->assertSame($before, $this->commentCount($I));
    }

    public function testControllerRejectsReplayedToken(\IntegrationTester $I): void
    {
        /** @var CommentFormTokenManager $manager */
        $manager      = $I->grabService(CommentFormTokenManager::class);
        $request      = Request::create('http://register.localhost/');
        $visitorToken = $manager->getOrCreateVisitorToken($request);
        $token        = $manager->issue('/', $visitorToken, time() - 5);
        $data         = [...$this->commentData('Only one copy must be saved'), 'antispam_token' => $token];
        $before       = $this->commentCount($I);

        $I->sendPostWithAntispamVisitor('http://register.localhost/', $data, $visitorToken);
        $I->seeResponseCodeIs(302);
        $I->assertSame($before, $this->commentCount($I));

        $authMails = $I->grabPublicAuthMails();
        $I->assertCount(1, $authMails);
        $I->amOnPage($this->callbackUrl($authMails[0]['message']));
        $I->seeResponseCodeIs(302);
        $I->assertSame($before + 1, $this->commentCount($I));

        $I->sendPostWithAntispamVisitor('http://register.localhost/', $data, $visitorToken);
        $I->seeResponseCodeIs(200);
        $I->see('The comment form is invalid or has expired');
        $I->assertSame($before + 1, $this->commentCount($I));
    }

    public function testControllerReturns429ForRateLimit(\IntegrationTester $I): void
    {
        /** @var CommentFormTokenManager $manager */
        $manager      = $I->grabService(CommentFormTokenManager::class);
        $request      = Request::create('http://register.localhost/');
        $visitorToken = $manager->getOrCreateVisitorToken($request);

        for ($i = 1; $i <= 5; ++$i) {
            $I->sendPostWithAntispamVisitor('http://register.localhost/', [
                ...$this->commentData('Rate test message ' . $i),
                'email'          => 'rate-' . $i . '@example.test',
                'antispam_token' => $manager->issue('/', $visitorToken, time() - 5),
            ], $visitorToken);
            $I->seeResponseCodeIs(302);
        }

        $I->sendPostWithAntispamVisitor('http://register.localhost/', [
            ...$this->commentData('Rate test message 6'),
            'email'          => 'rate-6@example.test',
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
            ->where("signal_code = 'links_one'")
            ->execute()
        ;

        /** @var LocalSpamDetector $detector */
        $detector = $I->grabService(LocalSpamDetector::class);
        $report = $detector->getReport(new SpamDetectorComment(
            'Reader',
            'reader@example.test',
            str_repeat('Useful context ', 6) . 'https://example.test/details',
            'Mozilla/5.0',
            'https://register.localhost/',
            'https://register.localhost/',
            10,
        ), '203.0.113.40');

        $I->assertSame(SpamDetectorReport::STATUS_SPAM, $report->status);
        $I->assertSame(47, $report->getScore());
        $I->assertSame(47, $report->getReasons()['links']);
    }

    public function testSentenceLikeTransliteratedRussianCampaignIsQuarantinedWithoutEmailHistory(
        \IntegrationTester $I,
    ): void {
        /** @var LocalSpamDetector $detector */
        $detector = $I->grabService(LocalSpamDetector::class);
        $examples = [
            ['a potom udivlyayutsya poc', 'hemu k gadalkam idut'],
            ['vertep o', 'n takoi'],
            ['tem zhe', 'chto tolstoi'],
        ];

        foreach ($examples as $index => [$name, $text]) {
            $report = $detector->getReport(new SpamDetectorComment(
                $name,
                'a-new-random-address-' . $index . '@example.test',
                $text,
                'Mozilla/5.0',
                'https://register.localhost/article',
                'https://register.localhost/',
                10,
            ), '203.0.113.' . (144 + $index));

            $I->assertSame(SpamDetectorReport::STATUS_SPAM, $report->status);
            $I->assertSame(40, $report->getScore());
            $I->assertSame(40, $report->getReasons()['sentence_like_latin_transliteration']);
        }
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
            ->where("signal_code = 'confirmed_spam_duplicate'")
            ->execute()
        ;

        /** @var SpamRiskScorer $scorer */
        $scorer = $I->grabService(SpamRiskScorer::class);
        $assessment = $scorer->assess(new SpamDetectorComment(
            'Reader',
            'reader@example.test',
            $text,
            'Mozilla/5.0',
            'https://register.localhost/',
            'https://register.localhost/',
            10,
        ), '203.0.113.41');

        $I->assertFalse($assessment->hardBlock);
        $I->assertArrayNotHasKey('confirmed_spam_duplicate', $assessment->reasons);
    }

    public function testTrainedTextModelContributesAReusableLocalSignal(\IntegrationTester $I): void
    {
        $name = 'Campaign account';
        $text = 'A learned campaign phrase';
        $salt = '00112233445566778899aabbccddeeff';
        /** @var SpamTextFeatureExtractor $extractor */
        $extractor = $I->grabService(SpamTextFeatureExtractor::class);
        $hashes = $extractor->hashes($name, $text, $salt);
        $I->assertNotEmpty($hashes);
        $weights = array_fill_keys($hashes, 100);
        $model = new SpamTextModel($salt, $weights, 100, time(), [
            'training_spam' => 10,
            'training_ham' => 10,
            'holdout_spam' => 2,
            'holdout_ham' => 2,
            'holdout_true_positive' => 2,
            'holdout_false_positive' => 0,
            'audited_visible' => 10,
            'audited_visible_positive' => 0,
        ]);
        /** @var SpamTextModelRepository $repository */
        $repository = $I->grabService(SpamTextModelRepository::class);
        $repository->save($model);

        /** @var SpamRiskScorer $scorer */
        $scorer = $I->grabService(SpamRiskScorer::class);
        $assessment = $scorer->assess(new SpamDetectorComment(
            $name,
            'changing-address@example.test',
            $text,
            'Mozilla/5.0',
            'https://register.localhost/',
            'https://register.localhost/',
            10,
        ), '203.0.113.142');

        $I->assertSame(45, $assessment->reasons['trained_text_model']);
        $I->assertGreaterThanOrEqual(45, $assessment->score);

        $repository->clear();
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
            'https://register.localhost/',
            'https://register.localhost/',
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
        $repository->attachComment($assessmentId, ContentType::POST, 123_456);

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

        $I->assertSame(ContentType::POST->value, $attachment['target_type']);
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
        /** @var PublicAuthRepository $authRepository */
        $authRepository = $I->grabService(PublicAuthRepository::class);
        $subscriberUserId = $authRepository->findOrCreateIdentity(
            'email',
            'subscriber@example.test',
            'subscriber@example.test',
            'Subscriber',
        );
        $dbLayer
            ->insert(CommentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('content_id', '1')
            ->setValue('user_id', ':user_id')->setParameter('user_id', $subscriberUserId)
            ->setValue('time', ':time')->setParameter('time', time() - 60)
            ->setValue('ip', "'198.51.100.20'")
            ->setValue('nick', "'Subscriber'")
            ->setValue('email', "'subscriber@example.test'")
            ->setValue('subscribed', '1')
            ->setValue('shown', '1')
            ->setValue('sent', '1')
            ->setValue('good', '0')
            ->setValue('text', "'Earlier comment'")
            ->execute()
        ;
        $dbLayer
            ->insert(CommentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('content_id', '1')
            ->setValue('time', ':time')->setParameter('time', time())
            ->setValue('ip', "'203.0.113.5'")
            ->setValue('nick', "'Reader'")
            ->setValue('email', "'reader@example.test'")
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
        $I->assertTrue($feedback->markSpam($commentId, ContentType::PAGE));

        $comment = $dbLayer
            ->select('shown', 'sent')
            ->from(CommentSchema::TABLE_NAME)
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
        $I->assertSame(0, (int)$dbLayer
            ->select('COUNT(*)')
            ->from('spam_reputation')
            ->where("key_type = 'email'")
            ->andWhere('key_hash = :key_hash')->setParameter('key_hash', $hasher->email('reader@example.test'))
            ->execute()
            ->result());

        $I->assertTrue($feedback->markHam($commentId, ContentType::PAGE));

        $comment = $dbLayer
            ->select('shown')
            ->from(CommentSchema::TABLE_NAME)
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

    public function testPostModeratorFeedbackUsesUnifiedNotifier(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        $dbLayer
            ->insert(ContentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('slug_scope', "'root'")
            ->setValue('created_at', ':time')->setParameter('time', time())
            ->setValue('published_at', ':time')
            ->setValue('updated_at', ':time')
            ->setValue('revision', '1')
            ->setValue('title', "'Feedback post'")
            ->setValue('excerpt', "''")
            ->setValue('body', "'Post text'")
            ->setValue('published', '1')
            ->setValue('featured', '0')
            ->setValue('comments_enabled', '1')
            ->setValue('series', "''")
            ->setValue('slug', "'feedback-post'")
            ->setValue('author_id', 'NULL')
            ->execute()
        ;
        $postId = (int)$dbLayer->insertId();

        $dbLayer
            ->insert(CommentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::POST->value)
            ->setValue('content_id', ':content_id')->setParameter('content_id', $postId)
            ->setValue('time', ':time')->setParameter('time', time())
            ->setValue('ip', "'203.0.113.6'")
            ->setValue('nick', "'Blog reader'")
            ->setValue('email', "'blog-reader@example.test'")
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
        $I->assertTrue($feedback->markSpam($commentId, ContentType::POST));

        $I->assertTrue($feedback->markHam($commentId, ContentType::POST));

        $comment = $dbLayer
            ->select('shown', 'sent')
            ->from(CommentSchema::TABLE_NAME)
            ->where('id = :id')->setParameter('id', $commentId)
            ->execute()
            ->fetchAssoc()
        ;
        if ($comment === false) {
            throw new \RuntimeException('The blog test comment was not found.');
        }

        $I->assertSame(1, (int)$comment['shown']);
        $I->assertSame(1, (int)$comment['sent']);

        $assessment = $dbLayer
            ->select('target_type', 'moderator_label')
            ->from('spam_assessments')
            ->where('comment_id = :comment_id')->setParameter('comment_id', $commentId)
            ->andWhere('target_type = :target_type')->setParameter('target_type', ContentType::POST->value)
            ->orderBy('id DESC')
            ->limit(1)
            ->execute()
            ->fetchAssoc()
        ;
        if ($assessment === false) {
            throw new \RuntimeException('The blog spam assessment was not found.');
        }

        $I->assertSame(ContentType::POST->value, $assessment['target_type']);
        $I->assertSame('ham', $assessment['moderator_label']);
    }

    public function testHamAfterRejectNotifiesSubscribers(\IntegrationTester $I): void
    {
        /** @var DbLayer $dbLayer */
        $dbLayer = $I->grabService(DbLayer::class);
        /** @var PublicAuthRepository $authRepository */
        $authRepository = $I->grabService(PublicAuthRepository::class);
        $subscriberUserId = $authRepository->findOrCreateIdentity(
            'email',
            'subscriber-2@example.test',
            'subscriber-2@example.test',
            'Subscriber',
        );
        $dbLayer
            ->insert(CommentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('content_id', '1')
            ->setValue('user_id', ':user_id')->setParameter('user_id', $subscriberUserId)
            ->setValue('time', ':time')->setParameter('time', time() - 60)
            ->setValue('ip', "'198.51.100.21'")
            ->setValue('nick', "'Subscriber'")
            ->setValue('email', "'subscriber-2@example.test'")
            ->setValue('subscribed', '1')
            ->setValue('shown', '1')
            ->setValue('sent', '1')
            ->setValue('good', '0')
            ->setValue('text', "'Earlier subscriber comment'")
            ->execute()
        ;
        $dbLayer
            ->insert(CommentSchema::TABLE_NAME)
            ->setValue('content_type', ':content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->setValue('content_id', '1')
            ->setValue('time', ':time')->setParameter('time', time())
            ->setValue('ip', "'203.0.113.7'")
            ->setValue('nick', "'Rejected reader'")
            ->setValue('email', "'rejected-reader@example.test'")
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
        $I->assertTrue($feedback->markHam($commentId, ContentType::PAGE));
        $I->assertCount(1, $I->grabSubscriberMails());

        $comment = $dbLayer
            ->select('shown', 'sent')
            ->from(CommentSchema::TABLE_NAME)
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
            contentType: ContentType::PAGE,
            commentId: 101,
        );
        $assessments->setShadowStatus($falsePositiveId, SpamDetectorReport::STATUS_HAM);
        $assessments->labelComment(101, 'ham', $assessment, ContentType::PAGE);

        $falseNegativeId = $assessments->save(
            $assessment,
            SpamDetectorReport::STATUS_HAM,
            contentType: ContentType::PAGE,
            commentId: 102,
        );
        $assessments->setShadowStatus($falseNegativeId, SpamDetectorReport::STATUS_SPAM);
        $assessments->labelComment(102, 'spam', $assessment, ContentType::PAGE);

        $failedId = $assessments->save(
            $assessment,
            SpamDetectorReport::STATUS_FAILED,
            contentType: ContentType::PAGE,
            commentId: 103,
        );
        $assessments->setShadowStatus($failedId, SpamDetectorReport::STATUS_FAILED);
        $assessments->labelComment(103, 'ham', $assessment, ContentType::PAGE);

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
        $unavailableDb = new DbLayerSqlite(new \Register\Core\Pdo\PDO('sqlite::memory:'));
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
        foreach (ContentType::cases() as $contentType) {
            $report = $detector->getReport(new SpamDetectorComment(
                'Reader',
                'reader@example.test',
                'Orphan assessment for ' . $contentType->value,
            ), '203.0.113.30');
            $assessmentId = $report->getAssessmentId();
            if ($assessmentId === null) {
                throw new \RuntimeException('The orphan assessment was not stored.');
            }

            $assessmentRepository->attachComment($assessmentId, $contentType, 999_999);
        }

        /** @var SpamMaintenance $maintenance */
        $maintenance = $I->grabService(SpamMaintenance::class);
        $deleted     = $maintenance->run($now);
        $I->assertSame(1, $deleted['rate_events']);
        $I->assertSame(1, $deleted['form_nonces']);
        $I->assertSame(2, $deleted['comment_assessment_orphans']);

        $I->assertSame(1, $this->rowCount($dbLayer, 'spam_rate_events'));
        $I->assertSame(1, $this->rowCount($dbLayer, 'spam_form_nonces'));
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

        return (int)$dbLayer
            ->select('COUNT(*)')
            ->from(CommentSchema::TABLE_NAME)
            ->where('content_type = :content_type')->setParameter('content_type', ContentType::PAGE->value)
            ->execute()
            ->result();
    }

    private function callbackUrl(string $message): string
    {
        if (preg_match('~https?://[^\s]+/auth/email/callback\?token=[A-Za-z0-9_-]+~', $message, $matches) !== 1) {
            throw new \RuntimeException('The test email contains no callback URL.');
        }

        return $matches[0];
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
