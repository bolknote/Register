<?php

declare(strict_types = 1);

use Register\AdminYard\Translator;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\Viewer;
use Register\Module\Blog\Module;
use Register\Module\Typography\Typograph;

// Render both public tag surfaces through the actual views and typography.
// No configured installation, database, credentials or article content is used.
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This tag display fixture is CLI-only.');
}

$root = dirname(__DIR__, 3) . '/';
require $root . '_vendor/autoload.php';
$provider = new DynamicConfigProvider();
(new ReflectionClass($provider))->getProperty('params')->setValue($provider, ['REGISTER_STYLE' => 'register']);
$viewer = new Viewer(new Translator(['locale' => 'ru'], 'ru'), new UrlBuilder('', '', ''), $root, $provider->getStringProxy('REGISTER_STYLE'), false);
$names = ['Лаборатория прибор-42', 'сенсор-7 документация', 'Модель-42 SDK-7', 'программирование', 'Справочник устройств', 'Книги & документы'];
$tags = [];
foreach ($names as $index => $name) {
    $tags[] = ['title' => $name, 'link' => '/tags/sample-' . $index . '/'];
}

$vars = [
    'id' => 7, 'author' => 'Fixture author', 'title' => 'Tag display fixture',
    'title_link' => '/all/sample-post', 'link' => '/all/sample-post',
    'create_time' => 1_700_000_000, 'time' => '2023-11-14', 'display_date' => '',
    'text' => '<p>Ordinary content with multiword tags.</p>', 'tags' => $tags,
    'commented' => false, 'comment_num' => 0, 'favorite' => false,
    'showComments' => false, 'enabledComments' => false, 'see_also' => null,
];

echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Register tag display regressions</title>'
    . '<link rel="stylesheet" href="/site.css"></head><body><main id="content">';
foreach (['post', 'post_short'] as $view) {
    echo '<section data-tag-view="' . $view . '">'
        . Typograph::process($viewer->render($view, $vars, Module::class), 'ru')
        . '</section>';
}

echo '</main></body></html>';
