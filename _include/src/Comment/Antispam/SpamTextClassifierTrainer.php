<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

/** Builds a compact, installation-local hashed text model from moderator labels. */
final readonly class SpamTextClassifierTrainer
{
    private const int MAX_POSITIVE_WEIGHTS = 2_000;

    private const int MAX_NEGATIVE_WEIGHTS = 1_000;

    private const int MIN_ABSOLUTE_WEIGHT = 100;

    private const int MIN_CLASS_OCCURRENCES = 2;

    private const int HOLDOUT_DIVISOR = 5;

    /** Calibrate at no more than 0.1% candidate false positives in the training ham corpus. */
    private const int FALSE_POSITIVE_BUDGET_DIVISOR = 1_000;

    public function __construct(private SpamTextFeatureExtractor $featureExtractor)
    {
    }

    /**
     * @param list<array{key: string, name: string, text: string}> $spam
     * @param list<array{key: string, name: string, text: string}> $ham
     * @return array{model: SpamTextModel, report: array<string, mixed>}
     */
    public function train(array $spam, array $ham, ?string $salt = null, ?int $trainedAt = null): array
    {
        if (count($spam) < 10 || count($ham) < 10) {
            throw new \InvalidArgumentException('The spam text classifier needs at least ten examples of each label.');
        }

        $salt ??= bin2hex(random_bytes(SODIUM_CRYPTO_SHORTHASH_KEYBYTES));
        $trainedAt ??= time();
        [$trainingSpam, $holdoutSpam] = $this->split($spam);
        [$trainingHam, $holdoutHam] = $this->split($ham);
        if ($trainingSpam === [] || $holdoutSpam === [] || $trainingHam === [] || $holdoutHam === []) {
            throw new \LogicException('The deterministic spam text-model holdout is empty.');
        }

        $model = $this->buildModel($trainingSpam, $trainingHam, $salt, $trainedAt);
        $classifier = new SpamTextClassifier(
            null,
            $this->featureExtractor,
        );

        $trainingSpamScores = $this->scores($classifier, $model, $trainingSpam);
        $trainingHamScores = $this->scores($classifier, $model, $trainingHam);
        // Calibration must not inspect the holdout. The previous near-zero budget, chosen against
        // the complete corpus, hid the model's poor recall and made its holdout non-independent.
        $falsePositiveBudget = intdiv(count($trainingHam), self::FALSE_POSITIVE_BUDGET_DIVISOR);
        $threshold = $this->threshold($trainingHamScores, $falsePositiveBudget);

        $metrics = [
            'training_spam'           => count($trainingSpam),
            'training_ham'            => count($trainingHam),
            'holdout_spam'            => count($holdoutSpam),
            'holdout_ham'             => count($holdoutHam),
            'holdout_true_positive'   => $this->positiveCount($this->scores($classifier, $model, $holdoutSpam), $threshold),
            'holdout_false_positive'  => $this->positiveCount($this->scores($classifier, $model, $holdoutHam), $threshold),
            'audited_visible'         => count($ham),
            'audited_visible_positive'=> $this->positiveCount($this->scores($classifier, $model, $ham), $threshold),
        ];
        $model = new SpamTextModel(
            $model->salt,
            $model->weights,
            $threshold,
            $model->trainedAt,
            $metrics,
        );
        $classifier = new SpamTextClassifier(
            null,
            $this->featureExtractor,
        );

        return [
            'model' => $model,
            'report' => [
                'model' => [
                    'format_version' => SpamTextModel::FORMAT_VERSION,
                    'trained_at'     => gmdate(DATE_ATOM, $model->trainedAt),
                    'feature_weights'=> count($model->weights),
                    'positive_feature_weights' => count(array_filter(
                        $model->weights,
                        static fn(int $weight): bool => $weight > 0,
                    )),
                    'negative_feature_weights' => count(array_filter(
                        $model->weights,
                        static fn(int $weight): bool => $weight < 0,
                    )),
                    'threshold'      => $model->threshold,
                ],
                'corpus' => [
                    'spam_labels' => count($spam),
                    'ham_labels'  => count($ham),
                ],
                'metrics' => $metrics,
                'rates' => [
                    'holdout_recall_percent' => $this->percent(
                        $metrics['holdout_true_positive'],
                        $metrics['holdout_spam'],
                    ),
                    'holdout_false_positive_percent' => $this->percent(
                        $metrics['holdout_false_positive'],
                        $metrics['holdout_ham'],
                    ),
                    'all_visible_positive_percent' => $this->percent(
                        $metrics['audited_visible_positive'],
                        $metrics['audited_visible'],
                    ),
                ],
                'holdout_spam' => $this->assessExamples($classifier, $model, $holdoutSpam),
                'holdout_ham'  => $this->assessExamples($classifier, $model, $holdoutHam),
                'visible_detections' => array_values(array_filter(
                    $this->assessExamples($classifier, $model, $ham),
                    static fn(array $row): bool => $row['predicted_spam'],
                )),
                'training_score_ranges' => [
                    'spam' => $this->scoreRange($trainingSpamScores),
                    'ham'  => $this->scoreRange($trainingHamScores),
                    'allowed_false_positives' => $falsePositiveBudget,
                ],
            ],
        ];
    }

    /**
     * @param list<array{key: string, name: string, text: string}> $spam
     * @param list<array{key: string, name: string, text: string}> $ham
     */
    private function buildModel(array $spam, array $ham, string $salt, int $trainedAt): SpamTextModel
    {
        $spamCounts = [];
        foreach ($spam as $example) {
            foreach ($this->featureExtractor->features($example['name'], $example['text']) as $feature) {
                $spamCounts[$feature] = ($spamCounts[$feature] ?? 0) + 1;
            }
        }

        $hamCounts = [];
        foreach ($ham as $example) {
            foreach ($this->featureExtractor->features($example['name'], $example['text']) as $feature) {
                $hamCounts[$feature] = ($hamCounts[$feature] ?? 0) + 1;
            }
        }

        $positive = [];
        $negative = [];
        $spamTotal = count($spam);
        $hamTotal = count($ham);
        $features = array_fill_keys([...array_keys($spamCounts), ...array_keys($hamCounts)], true);
        foreach ($features as $feature => $_) {
            $spamCount = $spamCounts[$feature] ?? 0;
            $hamCount = $hamCounts[$feature] ?? 0;
            if ($spamCount + $hamCount < 2) {
                continue;
            }

            $spamRate = ((float)$spamCount + 0.5) / (float)($spamTotal + 1);
            $hamRate = ((float)$hamCount + 0.5) / (float)($hamTotal + 1);
            $enrichment = $spamRate / $hamRate;
            $weight = (int)round(100.0 * log($enrichment));
            $weight = max(-1_200, min(1_200, $weight));
            $classCount = $weight > 0 ? $spamCount : $hamCount;
            $rank = (float)abs($weight) * log((float)(2 + $classCount));
            if ($weight >= self::MIN_ABSOLUTE_WEIGHT && $spamCount >= self::MIN_CLASS_OCCURRENCES) {
                $positive[$feature] = ['weight' => $weight, 'rank' => $rank];
            } elseif ($weight <= -self::MIN_ABSOLUTE_WEIGHT && $hamCount >= self::MIN_CLASS_OCCURRENCES) {
                $negative[$feature] = ['weight' => $weight, 'rank' => $rank];
            }
        }

        uasort($positive, static fn(array $left, array $right): int => $right['rank'] <=> $left['rank']);
        uasort($negative, static fn(array $left, array $right): int => $right['rank'] <=> $left['rank']);
        $selected = array_slice($positive, 0, self::MAX_POSITIVE_WEIGHTS, true)
            + array_slice($negative, 0, self::MAX_NEGATIVE_WEIGHTS, true);
        $weights = [];
        $key = hex2bin($salt);
        if (!\is_string($key)) {
            throw new \InvalidArgumentException('The spam text-model salt cannot be decoded.');
        }

        foreach ($selected as $feature => $data) {
            $hash = rtrim(strtr(base64_encode(sodium_crypto_shorthash($feature, $key)), '+/', '-_'), '=');
            $weights[$hash] = $data['weight'];
        }

        ksort($weights, SORT_STRING);

        return new SpamTextModel($salt, $weights, 0, $trainedAt, []);
    }

    /**
     * @param list<array{key: string, name: string, text: string}> $examples
     * @return array{list<array{key: string, name: string, text: string}>, list<array{key: string, name: string, text: string}>}
     */
    private function split(array $examples): array
    {
        $training = [];
        $holdout = [];
        foreach ($examples as $example) {
            $partition = intval(substr(hash('sha256', $example['key']), 0, 8), 16) % self::HOLDOUT_DIVISOR;
            if ($partition === 0) {
                $holdout[] = $example;
            } else {
                $training[] = $example;
            }
        }

        return [$training, $holdout];
    }

    /**
     * @param list<array{key: string, name: string, text: string}> $examples
     * @return list<int>
     */
    private function scores(SpamTextClassifier $classifier, SpamTextModel $model, array $examples): array
    {
        return array_map(
            static fn(array $example): int => $classifier->score($model, $example['name'], $example['text']),
            $examples,
        );
    }

    /** @param list<int> $hamScores */
    private function threshold(array $hamScores, int $falsePositiveBudget): int
    {
        rsort($hamScores, SORT_NUMERIC);
        $threshold = ($hamScores[$falsePositiveBudget] ?? ($hamScores[0] ?? 0)) + 1;
        while ($this->positiveCount($hamScores, $threshold) > $falsePositiveBudget) {
            ++$threshold;
        }

        return max(1, $threshold);
    }

    /** @param list<int> $scores */
    private function positiveCount(array $scores, int $threshold): int
    {
        return count(array_filter($scores, static fn(int $score): bool => $score >= $threshold));
    }

    /**
     * @param list<array{key: string, name: string, text: string}> $examples
     * @return list<array{key: string, name: string, text: string, score: int, predicted_spam: bool}>
     */
    private function assessExamples(
        SpamTextClassifier $classifier,
        SpamTextModel      $model,
        array              $examples,
    ): array {
        $result = [];
        foreach ($examples as $example) {
            $score = $classifier->score($model, $example['name'], $example['text']);
            $result[] = [
                ...$example,
                'score'          => $score,
                'predicted_spam' => $score >= $model->threshold,
            ];
        }

        return $result;
    }

    /**
     * @param list<int> $scores
     * @return array{min: int, max: int}
     */
    private function scoreRange(array $scores): array
    {
        if ($scores === []) {
            return ['min' => 0, 'max' => 0];
        }

        return ['min' => min($scores), 'max' => max($scores)];
    }

    private function percent(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round(100 * $part / $whole, 4);
    }
}
