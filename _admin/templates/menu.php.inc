<?php

declare(strict_types=1);

/** @var array $primaryLinks */
/** @var array $systemLinks */
/** @var bool $systemActive */
/** @var callable $trans */
/** @var bool $seeUsers */
/** @var string $login */
/** @var ?int $userId */

use S2\Cms\AdminYard\Signal;

$echoSignal = static function (Signal $signal) use ($trans): void {
    ?>
    <li class="main-menu-subitem">
        <a class="main-menu-sublink"
           title="<?= htmlspecialchars($trans($signal->title), ENT_QUOTES, 'UTF-8') ?>"
           href="<?= htmlspecialchars($signal->url, ENT_QUOTES, 'UTF-8') ?>"
        >
            <?= htmlspecialchars($signal->text, ENT_QUOTES, 'UTF-8') ?>
        </a>
    </li>
    <?php
};

$echoLinks = static function (array $links) use ($echoSignal, $trans): void {
    foreach ($links as $link) {
        ?>
        <li class="main-menu-item <?= $link['active'] ? 'active' : '' ?>">
            <a class="main-menu-link" href="<?= htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($trans($link['name']), ENT_QUOTES, 'UTF-8') ?>
            </a>

            <?php if (!empty($link['signals'])): ?>
                <ul class="main-menu-sublist">
                    <?php
                    /** @var Signal[] $signals */
                    $signals  = $link['signals'];
                    $allEmpty = true;
                    $titles   = [];
                    foreach ($signals as $signal) {
                        if (!$signal->isEmpty()) {
                            $allEmpty = false;
                            break;
                        }
                        $titles[] = $signal->title;
                    }
                    if ($allEmpty) {
                        $echoSignal(new Signal((string)count($signals), implode("\n", $titles), $link['url']));
                    } else {
                        foreach ($signals as $signal) {
                            $echoSignal($signal);
                        }
                    }
                    ?>
                </ul>
            <?php endif; ?>
        </li>
        <?php
    }
};

?>
<ul class="main-menu-list main-menu-primary-list"><?php $echoLinks($primaryLinks) ?></ul>
<?php if ($systemLinks !== []): ?>
    <details class="main-menu-system <?= $systemActive ? 'active' : '' ?>">
        <summary class="main-menu-link"><?= htmlspecialchars($trans('System'), ENT_QUOTES, 'UTF-8') ?></summary>
        <ul class="main-menu-list main-menu-system-list"><?php $echoLinks($systemLinks) ?></ul>
    </details>
<?php endif; ?>
<ul class="main-menu-list">
    <li class="main-menu-item" data-user-id="<?= $userId ?>">
        <?php if ($seeUsers): ?>
            <a class="main-menu-link" href="?entity=User&action=list"><?php printf($trans('Welcome'), $login); ?></a>
        <?php else: ?>
            <a class="main-menu-link" href="?"><?php printf($trans('Welcome'), $login); ?></a>
        <?php endif; ?>
        <a class="main-menu-link" href="?action=logout" title="<?= $trans('Logout info') ?>"><?= $trans('Logout') ?></a>
    </li>
</ul>
