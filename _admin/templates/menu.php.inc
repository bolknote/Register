<?php

declare(strict_types = 1);

/** @var list<array<mixed>> $navigationItems */
/** @var array<mixed> $accountGroup */
/** @var callable $trans */
/** @var string $logoutUrl */
/** @var ?int $userId */

use S2\Cms\AdminYard\Signal;

$html = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

/**
 * @param list<Signal> $signals
 */
$renderSignals = static function (array $signals, string $fallbackUrl) use ($html, $trans): void {
    foreach ($signals as $signal) {
        $title = $html($trans($signal->title));
        $url   = $signal->url !== '' ? $signal->url : $fallbackUrl;
        $text  = $signal->text !== '' ? $signal->text : '!';
        ?>
        <a class="main-menu-signal"
           title="<?= $title ?>"
           aria-label="<?= $title ?>"
           href="<?= $html($url) ?>"
        ><?= $html($text) ?></a>
        <?php
    }
};

/**
 * @param list<array<mixed>> $links
 * @return array{text: string, title: string}|null
 */
$summarizeSignals = static function (array $links) use ($trans): ?array {
    $count  = 0;
    $alert  = false;
    $titles = [];
    foreach ($links as $link) {
        /** @var list<Signal> $signals */
        $signals = $link['signals'] ?? [];
        foreach ($signals as $signal) {
            if ($signal->text !== '' && ctype_digit($signal->text)) {
                $count += (int)$signal->text;
            } else {
                $alert = true;
            }
            $titles[] = $trans($signal->title);
        }
    }

    if ($count === 0 && !$alert) {
        return null;
    }

    return [
        'text'  => $count > 0 ? (string)$count : '!',
        'title' => implode("\n", array_unique($titles)),
    ];
};

$renderLink = static function (array $link, bool $dropdown = false) use ($html, $renderSignals, $trans): void {
    $name = $trans((string)$link['name']);
    ?>
    <li class="main-menu-item <?= !empty($link['active']) ? 'active' : '' ?>"
        data-menu-key="<?= $html((string)$link['key']) ?>">
        <a class="main-menu-link <?= $dropdown ? 'main-menu-dropdown-link' : '' ?> <?= $link['key'] === 'Logout' ? 'main-menu-logout-link' : '' ?>"
           href="<?= $html((string)$link['url']) ?>"
            <?= !empty($link['active']) ? 'aria-current="page"' : '' ?>
        >
            <span class="main-menu-link-label"><?= $html($name) ?></span>
        </a>
        <?php
        /** @var list<Signal> $signals */
        $signals = $link['signals'] ?? [];
        $renderSignals($signals, (string)$link['url']);
        ?>
    </li>
    <?php
};

$renderGroup = static function (array $group, string $extraClass = '') use (
    $html,
    $renderLink,
    $summarizeSignals,
    $trans,
): void {
    /** @var list<array<mixed>> $links */
    $links         = $group['links'];
    $visibleLinks  = array_values(array_filter(
        $links,
        static fn(array $link): bool => empty($link['menuHidden']),
    ));
    $signalSummary = $summarizeSignals($links);
    ?>
    <details class="main-menu-group <?= !empty($group['active']) ? 'active' : '' ?> <?= $html($extraClass) ?>"
             data-menu-group="<?= $html((string)$group['key']) ?>">
        <summary class="main-menu-link">
            <span class="main-menu-group-label"><?= $html($trans((string)$group['name'])) ?></span>
            <?php if (!empty($group['currentName'])): ?>
                <span class="main-menu-current-label"><?= $html($trans((string)$group['currentName'])) ?></span>
            <?php endif; ?>
            <?php if ($signalSummary !== null): ?>
                <span class="main-menu-group-signal"
                      title="<?= $html($signalSummary['title']) ?>"
                      aria-label="<?= $html($signalSummary['title']) ?>"
                ><?= $html($signalSummary['text']) ?></span>
            <?php endif; ?>
        </summary>
        <ul class="main-menu-list main-menu-dropdown-list">
            <?php foreach ($visibleLinks as $link) {
                $renderLink($link, true);
            } ?>
        </ul>
    </details>
    <?php
};

?>
<ul class="main-menu-list main-menu-primary-list">
    <?php foreach ($navigationItems as $item): ?>
        <?php if ($item['kind'] === 'group'): ?>
            <li class="main-menu-group-item"><?php $renderGroup($item) ?></li>
        <?php else: ?>
            <?php $renderLink($item) ?>
        <?php endif; ?>
    <?php endforeach; ?>
</ul>
<?php
$accountGroup['links'][] = [
    'key'     => 'Logout',
    'name'    => 'Logout',
    'url'     => $logoutUrl,
    'active'  => false,
    'signals' => [],
];
?>
<div class="main-menu-account" data-user-id="<?= $userId ?>">
    <?php $renderGroup($accountGroup, 'main-menu-account-group') ?>
</div>
