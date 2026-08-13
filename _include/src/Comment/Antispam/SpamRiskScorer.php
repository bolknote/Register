<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use S2\Cms\Comment\SpamDetectorComment;
use S2\Cms\Pdo\DbLayerException;

final readonly class SpamRiskScorer
{
    public const string VERSION = 'rules-1';

    public function __construct(
        private SpamIdentityHasher       $hasher,
        private SpamFeatureExtractor     $featureExtractor,
        private SpamReputationRepository $reputationRepository,
        private SpamRuleRepository       $ruleRepository,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function assess(SpamDetectorComment $comment, string $clientIp): SpamAssessment
    {
        $textHash  = $this->hasher->text($comment->text);
        $emailHash = $this->hasher->email($comment->email);
        $ipHash    = $this->hasher->ip($clientIp);
        $domains   = $this->featureExtractor->domains($comment->text);
        $domainHashes = array_map($this->hasher->domain(...), $domains);

        $score     = 0;
        $reasons   = [];
        $hardBlock = false;

        $linkCount = $this->featureExtractor->linkCount($comment->text);
        if ($linkCount > 0) {
            $this->add($score, $reasons, 'links', match (true) {
                $linkCount >= 3 => 35,
                $linkCount === 2 => 20,
                default => 10,
            });
        }

        if (\count($domains) > 1) {
            $this->add($score, $reasons, 'multiple_domains', 10);
        }

        if ($linkCount > 0 && mb_strlen(trim($comment->text)) <= 60) {
            $this->add($score, $reasons, 'short_link_comment', 15);
        }

        if ($this->featureExtractor->hasHtml($comment->text)) {
            $this->add($score, $reasons, 'html', 10);
        }

        if ($this->featureExtractor->hasFormattingControls($comment->text)) {
            $this->add($score, $reasons, 'formatting_controls', 20);
        }

        if ($this->featureExtractor->hasLongRepetition($comment->text)) {
            $this->add($score, $reasons, 'long_repetition', 10);
        }

        if ($comment->userAgent === null || trim($comment->userAgent) === '') {
            $this->add($score, $reasons, 'missing_user_agent', 5);
        }

        if ($comment->referrer === null || trim($comment->referrer) === '') {
            $this->add($score, $reasons, 'missing_referrer', 3);
        }

        if ($comment->formAgeSeconds !== null && $comment->formAgeSeconds < 2) {
            $this->add($score, $reasons, 'very_fast_submission', 20);
        }

        $textReputation = $this->reputationRepository->get('text', $textHash);
        if ($textReputation->spamCount >= 2) {
            $this->add($score, $reasons, 'confirmed_spam_duplicate', 100);
            $hardBlock = true;
        } elseif ($textReputation->spamCount === 1) {
            $this->add($score, $reasons, 'possible_spam_duplicate', 50);
        } elseif ($textReputation->hamCount >= 2) {
            $this->add($score, $reasons, 'known_ham_text', -25);
        }

        $this->applyIdentityReputation($score, $reasons, 'email', $emailHash, 2, 30, 3, -20);
        $this->applyIdentityReputation($score, $reasons, 'ip', $ipHash, 3, 25, 5, -10);

        $domainSpamWeight = 0;
        $domainHamWeight  = 0;
        foreach ($domainHashes as $domainHash) {
            $reputation = $this->reputationRepository->get('domain', $domainHash);
            if ($reputation->spamCount >= 2) {
                $domainSpamWeight += 40;
            } elseif ($reputation->spamCount === 1) {
                $domainSpamWeight += 15;
            } elseif ($reputation->hamCount >= 3) {
                $domainHamWeight -= 10;
            }
        }

        if ($domainSpamWeight > 0) {
            $this->add($score, $reasons, 'domain_reputation', min(60, $domainSpamWeight));
        }

        if ($domainHamWeight < 0) {
            $this->add($score, $reasons, 'trusted_domains', max(-20, $domainHamWeight));
        }

        $ruleResult = $this->ruleRepository->evaluate($comment->text, $comment->email, $domains);
        $score += $ruleResult->score;
        $reasons = [...$reasons, ...$ruleResult->reasons];
        $hardBlock = $hardBlock || $ruleResult->hardBlock;

        return new SpamAssessment(
            max(0, min(100, $score)),
            $reasons,
            $textHash,
            $emailHash,
            $ipHash,
            $domainHashes,
            $hardBlock,
        );
    }

    /**
     * @param array<string, int> $reasons
     * @throws DbLayerException
     */
    private function applyIdentityReputation(
        int    &$score,
        array  &$reasons,
        string $type,
        string $hash,
        int    $spamThreshold,
        int    $spamWeight,
        int    $hamThreshold,
        int    $hamWeight,
    ): void {
        $reputation = $this->reputationRepository->get($type, $hash);
        if ($reputation->spamCount >= $spamThreshold) {
            $this->add($score, $reasons, $type . '_reputation', $spamWeight);
        } elseif ($reputation->spamCount === 0 && $reputation->hamCount >= $hamThreshold) {
            $this->add($score, $reasons, 'trusted_' . $type, $hamWeight);
        }
    }

    /** @param array<string, int> $reasons */
    private function add(int &$score, array &$reasons, string $reason, int $weight): void
    {
        $score += $weight;
        $reasons[$reason] = ($reasons[$reason] ?? 0) + $weight;
    }
}
