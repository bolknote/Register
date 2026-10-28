<?php

declare(strict_types = 1);

use Register\AdminYard\Translator;
use Register\Content\ContentId;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Model\AuthenticatedPublicUser;
use Register\Core\Pdo\DbLayerSqlite;
use Register\Core\Template\Viewer;
use Register\Module\Blog\Module;
use Register\Module\Reactions\Manifest;
use Register\Module\Reactions\ReactionRenderer;
use Register\Module\Reactions\ReactionRepository;

// Ordinary product views, isolated from all configured databases and articles.
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This responsive layout fixture is CLI-only.');
}

$root = dirname(__DIR__, 3) . '/';
require $root . '_vendor/autoload.php';
$mode = $argv[1] ?? '';
$initialLayout = str_starts_with($mode, 'initial-layout');
$role = $mode === 'initial-layout-moderator' ? 'moderator' : ($mode === 'initial-layout-guest' ? 'guest' : 'author');
if ($mode === 'header-guest') {
    $role = 'guest';
}

$provider = new DynamicConfigProvider();
(new ReflectionClass($provider))->getProperty('params')->setValue($provider, ['REGISTER_STYLE' => 'register']);
$theme = match ($mode) {
    'header-oldschool' => 'oldschool',
    'header-pixel-forest' => 'pixel-forest',
    'header-system-1' => 'system-1',
    default => null,
};
$viewer = new Viewer(new Translator(['locale' => 'ru', ...require $root . '_include/src/Register/Module/Blog/resources/lang/Russian.php'], 'ru'), new UrlBuilder('', '', ''), $root, $provider->getStringProxy('REGISTER_STYLE'), false);
$paragraph = 'Это обычная тестовая заметка. У неё есть текст, фотография с подписью, таблица и пример кода. '
    . 'При изменении ширины окна длина строки остаётся удобной для чтения, а элементы управления находятся рядом с содержимым. ';
$body = '<p id="responsive-prose">' . str_repeat($paragraph, 3) . '</p>'
    . '<div class="post-picture post-media-picture" data-post-media-kind="image">'
    . '<img class="post-media-image" src="/responsive-image.svg" width="1600" height="640" alt="Тестовая иллюстрация">'
    . '<div class="post-caption">Подпись принадлежит иллюстрации и остаётся непосредственно под ней.</div></div>'
    . '<p id="responsive-after">Текст после иллюстрации. Здесь можно поставить курсор и продолжить писать.</p>'
    . '<pre id="responsive-code"><code>' . str_repeat('sample_function(argument); ', 45) . '</code></pre>'
    . '<table id="responsive-table"><thead><tr><th>Название</th><th>Параметры</th><th>Описание</th></tr></thead>'
    . '<tbody><tr><td>Пример</td><td><code>' . str_repeat('unbroken_parameter_', 35) . '</code></td><td>Обычная таблица</td></tr></tbody></table>';
if (($argv[1] ?? '') === 'image-dimensions') {
    // The upload policy stores 2000px Retina files with 1000px display dimensions.
    // Exercise ordinary image markup, independent of any real site's content.
    $retina = '<img class="post-media-image" src="/responsive-retina-image.svg" width="1000" height="400" alt="Retina">';
    $body = '<p id="responsive-prose">' . $paragraph . '</p>'
        . '<div class="post-picture post-media-picture">' . $retina
        . '<div class="post-caption">Подпись к Retina-иллюстрации.</div></div>'
        . '<div class="post-picture post-media-picture"><a href="/image-example">'
        . str_replace('alt="Retina"', 'alt="Linked Retina"', $retina) . '</a></div>'
        . '<div class="post-picture post-media-picture"><picture><source srcset="/responsive-retina-image.svg">'
        . str_replace('alt="Retina"', 'alt="Picture Retina"', $retina) . '</picture></div>'
        . '<figure>' . str_replace(['class="post-media-image" ', 'alt="Retina"'], ['', 'alt="Figure Retina"'], $retina)
        . '<figcaption>Подпись внутри figure.</figcaption></figure>'
        . '<div class="post-picture post-media-picture"><div class="post-media-overlay" data-post-media-overlay>'
        . str_replace('alt="Retina"', 'alt="Overlay Retina"', $retina)
        . '<div class="post-media-overlay-caption">Подпись на изображении.</div></div></div>'
        . '<div class="post-picture post-media-picture">'
        . str_replace(['width="1000" height="400"', 'alt="Retina"'], ['width="320" height="128"', 'alt="Small image"'], $retina)
        . '</div><div class="post-picture post-media-picture">'
        . str_replace('alt="Retina"', 'style="width: 480px" alt="Authored width"', $retina)
        . '</div><div class="post-picture post-media-picture">'
        . '<img class="post-media-image" src="/responsive-image.svg" alt="Natural width"></div>'
        . '<p id="responsive-after">Текст после иллюстраций.</p>';
}

$vars = [
    'id' => 7, 'author' => '', 'title' => 'Заметка с текстом и большой иллюстрацией',
    'title_link' => '/all/responsive-example', 'link' => '/all/responsive-example',
    'create_time' => 1_700_000_000, 'time' => '14 ноября 2023 года, 22:13', 'display_date' => '',
    'text' => $body, 'tags' => [['title' => 'тестовая заметка', 'link' => '/tags/example/']],
    'commented' => true, 'comment_num' => 0, 'favorite' => false, 'view_count' => 0,
    'showComments' => true, 'enabledComments' => true, 'see_also' => null,
    'scheduled_preview' => $mode === 'scheduled-layout', 'draft_preview' => $mode === 'draft-layout',
    'inplace' => ['action_url' => '/_inplace/post/7', 'token' => 'fixture-token', 'revision' => 1, 'return_to' => '/', 'create' => false],
];
if ($role !== 'author') {
    $vars['inplace'] = null;
}

$account = $viewer->render('public_auth_account', [
    'user' => $role === 'guest' ? null : new AuthenticatedPublicUser(1, 'fixture', 'author@example.test', 'Тестовый автор', false, false, true, false, false, 'fixture-session'),
    'unread' => $mode === 'header-unread' ? 12 : 0, 'live_region' => null, 'login_url' => '/auth', 'unread_url' => '/auth/unread',
    'logout_url' => '/auth/logout', 'logout_token' => 'fixture-token', 'admin_url' => '/_admin/', 'return_path' => '/',
]);
$header = $viewer->render('site-header', [
    'site_name' => 'Обычный сайт с заметками', 'tagline' => 'Текст, иллюстрации и обсуждение на общей адаптивной сетке.',
    'home_url' => '/', 'is_home' => true,
    'site_tools_html' => $role === 'author' ? $viewer->render('site-header-tools', ['can_create_post' => true], Module::class) : '',
    'public_auth_html' => '',
    'create_post_html' => null, 'editor_resources_html' => '',
], Module::class);
$actions = $viewer->render('header-search', [
    'search_label' => 'Поиск', 'search_url' => '/search', 'quick_search_url' => '/search?title=', 'prefixed' => false,
], \Register\Module\Search\Module::class)
    . $viewer->render('public_auth_header', ['account_html' => $account, 'dialog_html' => '']);
$recovery = file_get_contents(__DIR__ . '/recovery.html');
if ($recovery === false) {
    throw new RuntimeException('Unable to read the shared editor fixture.');
}

$resourceStart = strpos($recovery, '<div id="post-editor-resources"');
if ($resourceStart === false) {
    throw new RuntimeException('The shared editor resources are missing.');
}

$resources = substr($recovery, $resourceStart);
$resources = str_replace(['<script src="/recovery-fixture.js"></script>', '</html>'], ['<script src="/responsive-layout-fixture.js"></script>', ''], $resources);
$db = new DbLayerSqlite(new PDO('sqlite::memory:'));
(new Manifest())->installFresh($db);
$reactions = (new ReactionRenderer(new ReactionRepository($db), new Translator([], 'en'), ''))->render(ContentId::post(7));
$post = str_replace('<!-- register_reactions:post:7 -->', $reactions, $viewer->render('post', $vars, Module::class));
if ($initialLayout) {
    // An ordinary parser-blocking article script exposes frames before the
    // document finishes loading, independently of network/cache speed.
    $post = str_replace('<p id="responsive-after">', '<script src="/initial-layout-gate.js"></script><p id="responsive-after">', $post);
}

$comment = $viewer->render('comment', [
    'id' => 1, 'i' => 1, 'depth' => 0, 'visual_depth' => 0, 'nick' => 'Читатель',
    'good' => false, 'is_author' => false, 'time' => 1_700_000_001,
    'text' => 'Тестовый комментарий к заметке.', 'show_addressee' => false, 'parent' => null, 'children' => '',
    'moderation' => $role === 'moderator' ? [
        'can_hide' => true, 'can_edit' => false, 'action_url' => '/comment-moderation',
        'target' => 'post', 'token' => 'fixture-token', 'return_to' => '/',
    ] : null,
]);

echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">'
    . '<title>Адаптивная раскладка Register</title>'
    . ($role !== 'guest' ? '<meta name="register-tool-rail" content="1">' : '')
    . '<link rel="stylesheet" href="/site.css"><link rel="stylesheet" href="/responsive-blog.css">'
    . ($theme !== null ? '<link rel="stylesheet" href="/_styles/' . $theme . '/' . $theme . '.css">' : '')
    . '<link rel="stylesheet" href="/_assets/register/partial-navigation.css">'
    . '<link rel="stylesheet" href="/post-recovery.css"><link rel="stylesheet" href="/reactions.css">'
    . '<link rel="stylesheet" href="/responsive-auth.css">'
    . '<link rel="stylesheet" href="/_assets/register/search/search.css">'
    . '<script src="/_assets/register/search/header-search.js" defer></script>'
    . '<script src="/_assets/register/search/autocomplete.js" defer></script>'
    . '<script src="/_assets/register/public-auth.js" defer></script>'
    . ($initialLayout ? '<script src="/local-time.js"></script>' : '')
    . '</head><body class="blog_main"><div id="register-page" data-register-page>'
    . '<header id="header"><div class="site-header-actions">' . $actions . '</div>' . $header . '</header>'
    . '<main id="container"><div id="content"><div class="live-post-feed">'
    . $post
    . '</div><section class="comments-section"><h2 class="comment">Обсуждение</h2>'
    . '<div class="comment-thread">' . $comment . '</div>'
    . '</section></div></main>'
    . '<footer id="footer">Тестовая страница Register</footer></div>' . $resources . '</body></html>';
