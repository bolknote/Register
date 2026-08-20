<?php
/**
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

declare(strict_types = 1);

namespace S2\Cms\Comment\Antispam;

final readonly class SpamTextModel
{
    public const int FORMAT_VERSION = 1;

    /**
     * @param array<string, int> $weights
     * @param array<string, int> $metrics
     */
    public function __construct(
        public string $salt,
        public array  $weights,
        public int    $threshold,
        public int    $trainedAt,
        public array  $metrics,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $salt) !== 1) {
            throw new \InvalidArgumentException('The spam text-model salt is invalid.');
        }

        if ($trainedAt <= 0) {
            throw new \InvalidArgumentException('The spam text-model training time is invalid.');
        }

        foreach (array_keys($weights) as $hash) {
            if (preg_match('/^[A-Za-z0-9_-]{11}$/D', $hash) !== 1) {
                throw new \InvalidArgumentException('The spam text-model contains an invalid feature weight.');
            }
        }

        foreach ($metrics as $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException('The spam text-model contains invalid training metrics.');
            }
        }
    }

    public function toJson(): string
    {
        return json_encode([
            'version'      => self::FORMAT_VERSION,
            'salt'         => $this->salt,
            'weights'      => $this->weights,
            'threshold'    => $this->threshold,
            'trained_at'   => $this->trainedAt,
            'metrics'      => $this->metrics,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!\is_array($data) || (int)($data['version'] ?? 0) !== self::FORMAT_VERSION) {
            throw new \UnexpectedValueException('The spam text-model format is unsupported.');
        }

        $rawWeights = $data['weights'] ?? null;
        $rawMetrics = $data['metrics'] ?? null;
        if (!\is_array($rawWeights) || !\is_array($rawMetrics)) {
            throw new \UnexpectedValueException('The spam text-model payload is incomplete.');
        }

        $weights = [];
        foreach ($rawWeights as $hash => $weight) {
            if (!\is_string($hash) || !\is_int($weight)) {
                throw new \UnexpectedValueException('The spam text-model weights are malformed.');
            }

            $weights[$hash] = $weight;
        }

        $metrics = [];
        foreach ($rawMetrics as $metric => $value) {
            if (!\is_string($metric) || !\is_int($value)) {
                throw new \UnexpectedValueException('The spam text-model metrics are malformed.');
            }

            $metrics[$metric] = $value;
        }

        return new self(
            (string)($data['salt'] ?? ''),
            $weights,
            (int)($data['threshold'] ?? 0),
            (int)($data['trained_at'] ?? 0),
            $metrics,
        );
    }
}
