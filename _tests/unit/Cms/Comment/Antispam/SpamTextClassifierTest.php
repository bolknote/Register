<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Tests
 */

declare(strict_types = 1);

namespace Register\Core\Comment\Antispam;

use Codeception\Test\Unit;

final class SpamTextClassifierTest extends Unit
{
    public function testTrainerKeepsRawTextOutOfPersistedModelAndRecognizesCampaign(): void
    {
        $spam = [];
        $ham = [];
        for ($i = 1; $i <= 50; ++$i) {
            $spam[] = [
                'key'  => 'spam:' . $i,
                'name' => 'Casino offer ' . $i,
                'text' => 'Buy jackpot tokens and claim the casino bonus today ' . $i,
            ];
            $ham[] = [
                'key'  => 'ham:' . $i,
                'name' => 'Reader ' . $i,
                'text' => 'A thoughtful reply about typography and old web pages ' . $i,
            ];
        }

        $extractor = new SpamTextFeatureExtractor();
        $result = (new SpamTextClassifierTrainer($extractor))->train(
            $spam,
            $ham,
            '00112233445566778899aabbccddeeff',
            1_700_000_000,
        );
        $model = $result['model'];
        $serialized = $model->toJson();

        self::assertStringNotContainsString('casino', strtolower($serialized));
        self::assertStringNotContainsString('typography', strtolower($serialized));
        self::assertSame($serialized, SpamTextModel::fromJson($serialized)->toJson());
        self::assertGreaterThan(0, $model->metrics['holdout_true_positive']);
        self::assertSame(0, $model->metrics['holdout_false_positive']);

        $classifier = new SpamTextClassifier(null, $extractor);
        self::assertGreaterThanOrEqual(
            $model->threshold,
            $classifier->score($model, 'Casino offer 99', 'Buy jackpot tokens and claim the casino bonus today'),
        );
        self::assertLessThan(
            $model->threshold,
            $classifier->score($model, $ham[0]['name'], $ham[0]['text']),
        );
    }
}
