<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

use Register\Core\Comment\SpamDetectorComment;
use Register\Core\Pdo\DbLayerException;

final readonly class SpamRiskScorer
{
    public const string VERSION = 'rules-6';

    public function __construct(
        private SpamIdentityHasher       $hasher,
        private SpamFeatureExtractor     $featureExtractor,
        private SpamReputationRepository $reputationRepository,
        private SpamRuleRepository       $ruleRepository,
        private SpamSignalPolicyRepository $signalPolicyRepository,
        private ?SpamTextClassifier      $textClassifier = null,
    ) {
    }

    /**
     * @throws DbLayerException
     */
    public function assess(SpamDetectorComment $comment, string $clientIp): SpamAssessment
    {
        $weights   = $this->signalPolicyRepository->getWeights();
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
            $this->addPolicy(
                $score,
                $reasons,
                $weights,
                match (true) {
                    $linkCount >= 3 => 'links_many',
                    $linkCount === 2 => 'links_two',
                    default => 'links_one',
                },
                'links',
            );
        }

        if (\count($domains) > 1) {
            $this->addPolicy($score, $reasons, $weights, 'multiple_domains');
        }

        if ($linkCount > 0 && mb_strlen(trim($comment->text)) <= 60) {
            $this->addPolicy($score, $reasons, $weights, 'short_link_comment');
        }

        if ($this->featureExtractor->hasFormattingControls($comment->text)) {
            $this->addPolicy($score, $reasons, $weights, 'formatting_controls');
        }

        if ($this->featureExtractor->hasLongRepetition($comment->text)) {
            $this->addPolicy($score, $reasons, $weights, 'long_repetition');
        }

        if ($this->featureExtractor->hasSentenceLikeLatinTransliteration($comment->name, $comment->text)) {
            $this->addPolicy($score, $reasons, $weights, 'sentence_like_latin_transliteration');
        }

        if ($this->textClassifier?->matches($comment->name, $comment->text) === true) {
            $this->addPolicy($score, $reasons, $weights, 'trained_text_model');
        }

        if ($comment->userAgent === null || trim($comment->userAgent) === '') {
            $this->addPolicy($score, $reasons, $weights, 'missing_user_agent');
        }

        if ($comment->referrer === null || trim($comment->referrer) === '') {
            $this->addPolicy($score, $reasons, $weights, 'missing_referrer');
        }

        if ($comment->formAgeSeconds !== null && $comment->formAgeSeconds < 2) {
            $this->addPolicy($score, $reasons, $weights, 'very_fast_submission');
        }

        $textReputation = $this->reputationRepository->get('text', $textHash);
        if ($textReputation->spamCount >= 2) {
            $hardBlock = $this->addPolicy($score, $reasons, $weights, 'confirmed_spam_duplicate');
        } elseif ($textReputation->spamCount === 1) {
            $this->addPolicy($score, $reasons, $weights, 'possible_spam_duplicate');
        } elseif ($textReputation->hamCount >= 2) {
            $this->addPolicy($score, $reasons, $weights, 'known_ham_text');
        }

        $this->applyIdentityReputation(
            $score,
            $reasons,
            $weights,
            'email',
            $emailHash,
            2,
            3,
            'email_reputation',
            'trusted_email',
        );
        $this->applyIdentityReputation(
            $score,
            $reasons,
            $weights,
            'ip',
            $ipHash,
            3,
            5,
            'ip_reputation',
            'trusted_ip',
        );

        $domainSpamWeight = 0;
        $domainHamWeight  = 0;
        foreach ($domainHashes as $domainHash) {
            $reputation = $this->reputationRepository->get('domain', $domainHash);
            if ($reputation->spamCount >= 2) {
                $domainSpamWeight += $weights['domain_reputation_confirmed'] ?? 0;
            } elseif ($reputation->spamCount === 1) {
                $domainSpamWeight += $weights['domain_reputation_possible'] ?? 0;
            } elseif ($reputation->hamCount >= 3) {
                $domainHamWeight += $weights['trusted_domain'] ?? 0;
            }
        }

        if ($domainSpamWeight !== 0) {
            $this->add($score, $reasons, 'domain_reputation', max(-60, min(60, $domainSpamWeight)));
        }

        if ($domainHamWeight !== 0) {
            $this->add($score, $reasons, 'trusted_domains', max(-20, min(20, $domainHamWeight)));
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
     * @param array<string, int|null> $weights
     * @throws DbLayerException
     */
    private function applyIdentityReputation(
        int    &$score,
        array  &$reasons,
        array  $weights,
        string $type,
        string $hash,
        int    $spamThreshold,
        int    $hamThreshold,
        string $spamSignal,
        string $hamSignal,
    ): void {
        $reputation = $this->reputationRepository->get($type, $hash);
        if ($reputation->spamCount >= $spamThreshold) {
            $this->addPolicy($score, $reasons, $weights, $spamSignal, $type . '_reputation');
        } elseif ($reputation->spamCount === 0 && $reputation->hamCount >= $hamThreshold) {
            $this->addPolicy($score, $reasons, $weights, $hamSignal, 'trusted_' . $type);
        }
    }

    /**
     * @param array<string, int> $reasons
     * @param array<string, int|null> $weights
     */
    private function addPolicy(
        int    &$score,
        array  &$reasons,
        array  $weights,
        string $signal,
        ?string $reason = null,
    ): bool {
        $weight = $weights[$signal] ?? null;
        if ($weight === null) {
            return false;
        }

        $this->add($score, $reasons, $reason ?? $signal, $weight);

        return true;
    }

    /** @param array<string, int> $reasons */
    private function add(int &$score, array &$reasons, string $reason, int $weight): void
    {
        $score += $weight;
        $reasons[$reason] = ($reasons[$reason] ?? 0) + $weight;
    }
}
