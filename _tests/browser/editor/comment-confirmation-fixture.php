<?php

declare(strict_types = 1);

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This confirmation fixture is CLI-only.');
}

require dirname(__DIR__, 3) . '/_vendor/autoload.php';
$translator = new \Register\AdminYard\Translator(require dirname(__DIR__, 3) . '/_lang/English/common.php', 'en');
$renderer = new \Register\AdminYard\TemplateRenderer($translator);

echo $renderer->render(dirname(__DIR__, 3) . '/_include/views/comments.php', ['comments' => '', 'count' => 0]);
