<?php

declare(strict_types = 1);

use Register\AdminYard\Translator;
use Register\Content\ContentId;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Module\Reactions\Manifest;
use Register\Module\Reactions\ReactionAggregate;
use Register\Module\Reactions\ReactionAggregateRepository;
use Register\Module\Reactions\ReactionAggregateTargetType;
use Register\Module\Reactions\ReactionRenderer;
use Register\Module\Reactions\ReactionRepository;

// The browser sees the real renderer and imported totals, not copied widget HTML.
// This database is disposable and never opens a configured Register installation.
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This reaction fixture is CLI-only.');
}

require dirname(__DIR__, 3) . '/_vendor/autoload.php';
$db = new DbLayerSqlite(new PDO('sqlite::memory:'));
(new Manifest())->installFresh($db);
$aggregates = new ReactionAggregateRepository($db);
foreach ([['like', '👍', ($argv[1] ?? '') === 'zero' ? 0 : 1], ['love', '❤️', 1], ['haha', '😂', 2], ['', '🔥', 2]] as [$reaction, $emoji, $count]) {
    if ($count === 0) {
        continue;
    }

    $aggregates->store(new ReactionAggregate(
        ReactionAggregateTargetType::POST,
        1,
        'test-archive',
        $emoji,
        $reaction,
        $emoji,
        $count,
        100,
    ));
}

echo (new ReactionRenderer(new ReactionRepository($db), new Translator([], 'en'), ''))->render(ContentId::post(1));
