<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

use S2\Cms\Pdo\DbLayerException;

/**
 * @see \S2\Cms\Comment\Antispam\SpamTextClassifierTest
 */
final readonly class SpamTextClassifier
{
    public function __construct(
        private ?SpamTextModelRepository $repository,
        private SpamTextFeatureExtractor $featureExtractor,
    ) {
    }

    /** @throws DbLayerException */
    public function matches(string $name, string $text): bool
    {
        $model = $this->repository?->get();
        if (!$model instanceof \S2\Cms\Comment\Antispam\SpamTextModel) {
            return false;
        }

        return $this->score($model, $name, $text) >= $model->threshold;
    }

    public function score(SpamTextModel $model, string $name, string $text): int
    {
        $sum = 0;
        $matched = 0;
        foreach ($this->featureExtractor->hashes($name, $text, $model->salt) as $hash) {
            $weight = $model->weights[$hash] ?? null;
            if ($weight === null) {
                continue;
            }

            $sum += $weight;
            ++$matched;
        }

        return $matched === 0 ? 0 : (int)round((float)$sum / sqrt((float)$matched));
    }
}
