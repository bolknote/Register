<?php

declare(strict_types = 1);

use Register\Admin\Controller\UserController;

/** @var callable $trans */
/** @var string $userAccountType */

$tabs = [
    UserController::ACCOUNT_TYPE_TEAM => [
        'label'       => $trans('Team'),
        'description' => $trans('Team account description'),
    ],
    UserController::ACCOUNT_TYPE_GUEST => [
        'label'       => $trans('Guests'),
        'description' => $trans('Guest account description'),
    ],
    UserController::ACCOUNT_TYPE_IMPORTED => [
        'label'       => $trans('Imported commenters'),
        'description' => $trans('Imported account description'),
    ],
];

?>
<nav class="section-tabs user-section-tabs" aria-label="<?= $html($trans('User sections')) ?>">
    <?php foreach ($tabs as $accountType => $tab): ?>
        <?php $tabUrl = '?' . http_build_query([
            'entity'       => 'User',
            'action'       => 'list',
            'apply_filter' => '1',
            'account_type' => $accountType,
        ]); ?>
        <a href="<?= $html($tabUrl) ?>"
           <?= $accountType === $userAccountType ? 'aria-current="page"' : '' ?>><?= $html($tab['label']) ?></a>
    <?php endforeach; ?>
</nav>
<p class="user-section-description"><?= $html($tabs[$userAccountType]['description'] ?? '') ?></p>
