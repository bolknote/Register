<?php

declare(strict_types = 1);

// Render the real, fully enabled menu: a short hand-written fixture cannot
// catch unnecessary scrolling or wrapped labels in the complete editor UI.
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This menu fixture is CLI-only.');
}

$root = dirname(__DIR__, 3);
require $root . '/_include/functions.php';
/** @var array<string, string> $messages */
$messages = require $root . '/_include/src/Register/Module/Blog/resources/lang/Russian.php';
$trans = static fn(string $message): string => $messages[$message] ?? $message;
$editor_config = [
    'tag_suggestions_url' => '/_inplace/tags',
    'ai_enabled' => true, 'ai_alt_enabled' => true, 'recovery_user_id' => 1,
];
require $root . '/_include/src/Register/Module/Blog/resources/views/post-editor-resources.php';
