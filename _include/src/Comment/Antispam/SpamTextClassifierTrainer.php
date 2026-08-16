<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

/** Builds a compact, installation-local hashed text model from moderator labels. */
final readonly class SpamTextClassifierTrainer
{
    private const int MAX_POSITIVE_WEIGHTS = 1_600;

    private const int HOLDOUT_DIVISOR = 5;

    private const int FALSE_POSITIVE_BUDGET_DIVISOR = 5_000;

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
        // A historical corpus is available during import, so the production threshold can be
        // chosen conservatively against every visible comment instead of accepting known errors.
        $falsePositiveBudget = intdiv(count($ham), self::FALSE_POSITIVE_BUDGET_DIVISOR);
        $threshold = $this->threshold($this->scores($classifier, $model, $ham), $falsePositiveBudget);

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
                if (isset($spamCounts[$feature])) {
                    $hamCounts[$feature] = ($hamCounts[$feature] ?? 0) + 1;
                }
            }
        }

        $positive = [];
        $spamTotal = count($spam);
        $hamTotal = count($ham);
        foreach ($spamCounts as $feature => $spamCount) {
            $hamCount = $hamCounts[$feature] ?? 0;
            if ($spamCount + $hamCount < 2) {
                continue;
            }

            $spamRate = ($spamCount + 0.5) / ($spamTotal + 1);
            $hamRate = ($hamCount + 0.5) / ($hamTotal + 1);
            $enrichment = $spamRate / $hamRate;
            $weight = (int)round(100 * log($enrichment));
            $weight = max(-1_200, min(1_200, $weight));
            if ($weight === 0) {
                continue;
            }

            $rank = abs($weight) * log(2 + ($weight > 0 ? $spamCount : $hamCount));
            if ($weight >= 300 && $spamCount >= 2) {
                $positive[$feature] = ['weight' => $weight, 'rank' => $rank];
            }
        }

        uasort($positive, static fn(array $left, array $right): int => $right['rank'] <=> $left['rank']);
        $weights = [];
        $key = hex2bin($salt);
        if (!\is_string($key)) {
            throw new \InvalidArgumentException('The spam text-model salt cannot be decoded.');
        }
        foreach (array_slice($positive, 0, self::MAX_POSITIVE_WEIGHTS, true) as $feature => $data) {
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
            $partition = hexdec(substr(hash('sha256', $example['key']), 0, 8)) % self::HOLDOUT_DIVISOR;
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

        return $threshold;
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
