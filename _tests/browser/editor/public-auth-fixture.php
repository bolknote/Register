<?php

declare(strict_types = 1);

use Register\AdminYard\Translator;
use Register\Core\Config\DynamicConfigProvider;
use Register\Core\Model\UrlBuilder;
use Register\Core\Template\Viewer;

// Ordinary account templates and assets, without configured credentials or mail.
if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This account fixture is CLI-only.');
}

$root = dirname(__DIR__, 3) . '/';
require $root . '_vendor/autoload.php';
$providers = $argv[1] ?? 'yandex';
$email = ($argv[2] ?? '1') === '1';
$mode = $argv[3] ?? 'methods';
$panel = ($argv[4] ?? '') === 'panel';
$locale = ($argv[5] ?? 'ru') === 'en' ? 'en' : 'ru';
$prefix = $argv[6] ?? '';
$deferred = ($argv[7] ?? '') === 'deferred';
$urlBuilder = new UrlBuilder('', '', $prefix);
$return = $urlBuilder->rawLink('/all/auth-example', ['view=full']) . '#comment-draft';
$query = ['providers' => $providers, 'email' => $email ? '1' : '0', 'lang' => $locale, 'return' => $return];
if ($prefix !== '') {
    $query['prefix'] = $prefix;
}

$config = new DynamicConfigProvider();
(new ReflectionClass($config))->getProperty('params')->setValue($config, ['REGISTER_STYLE' => 'register']);
$messages = require $root . '_lang/' . ($locale === 'ru' ? 'Russian' : 'English') . '/common.php';
$messages = array_merge($messages, require $root . '_lang/' . ($locale === 'ru' ? 'Russian' : 'English') . '/comments.php');
$viewer = new Viewer(new Translator($messages, $locale), $urlBuilder, $root, $config->getStringProxy('REGISTER_STYLE'), false);
$variables = [
    'email_enabled' => $email,
    'vk_enabled' => $providers === 'all',
    'yandex_enabled' => in_array($providers, ['yandex', 'all'], true),
    'password_url' => $urlBuilder->rawLink('/auth/password'), 'email_url' => $urlBuilder->rawLink('/auth/email'),
    'vk_url' => $urlBuilder->rawLink('/auth/oauth/vk'),
    'mail_url' => $urlBuilder->rawLink('/auth/oauth/mail_ru'),
    'ok_url' => $urlBuilder->rawLink('/auth/oauth/ok_ru'),
    'yandex_url' => $urlBuilder->rawLink('/auth/oauth/yandex'),
    'return_path' => $return, 'form_token' => 'auth-fixture-token', 'initial_mode' => 'methods',
    'method_urls' => [
        'methods' => $urlBuilder->rawLink('/auth', [http_build_query($query)]),
        'email' => $urlBuilder->rawLink('/auth', [http_build_query(['mode' => 'email', ...$query])]),
        'password' => $urlBuilder->rawLink('/auth', [http_build_query(['mode' => 'password', ...$query])]),
    ],
];
$panelVariables = $variables;
$panelVariables['initial_mode'] = $mode;

echo '<!doctype html><html lang="' . $locale . '"><head><meta charset="utf-8">'
    . '<meta name="viewport" content="width=device-width, initial-scale=1">'
    . '<title>Register account fixture</title><link rel="stylesheet" href="/site.css">'
    . '<link rel="stylesheet" href="/responsive-auth.css">'
    . '<script src="/_assets/register/partial-navigation.js" defer></script>'
    . '<script src="/_assets/register/public-auth.js" defer></script><script src="/_assets/register/comment-submit.js" defer></script></head><body>'
    . '<main id="content" data-register-page><a id="auth-open" href="' . register_htmlencode($urlBuilder->rawLink('/auth', [http_build_query($query)])) . '" data-public-auth-open data-register-native-navigation>Sign in</a>'
    . '<label>Draft<textarea id="auth-draft">A comment drafted before signing in.</textarea></label>'
    . ($deferred ? $viewer->render('comment_form_deferred', [
        'id' => '1', 'formUrl' => $urlBuilder->rawLink('/all/auth-example', ['comment_form=1']) . '#add-comment',
    ]) : $viewer->render('comment_form', [
        'id' => '1', 'action' => $urlBuilder->rawLink('/all/auth-example'), 'cancelReplyUrl' => $urlBuilder->rawLink('/all/auth-example') . '#add-comment',
        'antispamToken' => 'fixture-token', 'syntaxHelpItems' => [],
        'commentFieldNames' => array_combine(
            ['text', 'name', 'email', 'subscribed', 'homepage', 'id', 'parent_id', 'reply_number', 'reply_name'],
            ['text', 'name', 'email', 'subscribed', 'homepage', 'id', 'parent_id', 'reply_number', 'reply_name'],
        ),
    ]))
    . ($panel ? $viewer->render('public_auth_panel', $panelVariables) : '')
    . '</main>' . $viewer->render('public_auth_dialog', $variables) . '</body></html>';
