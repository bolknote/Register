<?php

declare(strict_types = 1);

/** @var string|null $value */
/** @var callable $trans */

$source = (string)$value;
$labels = [
    'email'           => $trans('Email sign-in'),
    'yandex'          => 'Яндекс',
    'vk'              => 'VK',
    'mail_ru'         => 'Mail.ru',
    'ok_ru'           => $trans('Odnoklassniki'),
    'facebook'        => $trans('Facebook import'),
    'telegram'        => 'Telegram',
    'import_facebook' => $trans('Facebook import'),
    'import_legacy'   => $trans('Legacy blog import'),
    'import_external' => $trans('External import'),
    'local'           => $trans('Local account'),
];
$label = $labels[$source]
    ?? ($source !== '' ? ucfirst(str_replace('_', ' ', $source)) : $trans('Unknown source'));
$class = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($source)) ?: 'unknown';

?><span class="user-source user-source-<?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
