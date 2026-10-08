#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * @copyright 2026 Register contributors
 * @license   MIT
 */

use Register\Module\Search\Morphology\ChurchSlavonicNormalizer;
use Register\Module\Search\Morphology\HistoricalRussianNormalizer;
use Register\Module\Search\Morphology\HybridWordNormalizer;
use Register\Module\Search\Morphology\OpenCorporaDictionary;
use Register\Module\Search\Morphology\PreReformRussianNormalizer;
use Register\Rose\Stemmer\PorterStemmerEnglish;
use Register\Rose\Stemmer\PorterStemmerRussian;
use Register\Tools\Search\RelevanceBenchmark;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Search evaluation can only run from the command line.');
}

require dirname(__DIR__) . '/_vendor/autoload.php';
require __DIR__ . '/search/RelevanceBenchmark.php';

$options = getopt('', ['dataset:', 'json', 'experiments', 'help']);
if ($options === false) {
    throw new InvalidArgumentException('Cannot parse command-line options.');
}
if (isset($options['help'])) {
    fwrite(STDOUT, 'Usage: php tools/evaluate-search.php [--dataset=corpus.json] [--json] [--experiments]' . PHP_EOL);
    fwrite(STDOUT, 'Documents: id, title, content; optional keywords, publishedAt (integer timestamp or null), relevanceRatio (0.001..9999).' . PHP_EOL);
    fwrite(STDOUT, 'Queries: query text and relevance map (document ID => grade 1..3; grades >= 2 count as relevant).' . PHP_EOL);
    fwrite(STDOUT, 'The corpus is indexed in memory. Full rankings are evaluated without fetching display-only snippets.' . PHP_EOL);
    fwrite(STDOUT, '--experiments adds opt-in rarity, repeat-saturation and weighted-coverage ablations; public search is unchanged.' . PHP_EOL);
    exit(0);
}

$datasetPath = $options['dataset'] ?? dirname(__DIR__) . '/_tests/_resources/search/relevance.json';
if (!is_string($datasetPath)) {
    throw new InvalidArgumentException('--dataset needs one JSON file path.');
}

$normalizer = new HybridWordNormalizer(
    new HistoricalRussianNormalizer(
        new ChurchSlavonicNormalizer(),
        new PreReformRussianNormalizer(),
        new OpenCorporaDictionary(dirname(__DIR__) . '/_include/src/Register/Module/Search/resources/morphology/ru'),
    ),
    new PorterStemmerRussian(new PorterStemmerEnglish()),
);
$report = (new RelevanceBenchmark($normalizer, experiments: isset($options['experiments'])))->run(RelevanceBenchmark::loadDataset($datasetPath));

if (isset($options['json'])) {
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
} else {
    fwrite(STDOUT, sprintf('%d documents, %d queries; grades >= 2 count as relevant.', $report['documents'], $report['queries']) . PHP_EOL);
    fwrite(STDOUT, sprintf('%-18s %8s %8s %8s %10s', 'Model', 'Hit@1', 'Hit@3', 'MRR', 'nDCG@10') . PHP_EOL);
    foreach ($report['models'] as $model => $metrics) {
        fwrite(STDOUT, sprintf('%-18s %8.3f %8.3f %8.3f %10.3f', $model, $metrics['hit1'], $metrics['hit3'], $metrics['mrr'], $metrics['ndcg10']) . PHP_EOL);
    }
}
