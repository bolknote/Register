<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var callable $isGranted */

if ($isGranted(\Register\Core\Model\PermissionChecker::PERMISSION_CREATE_ARTICLES)) {
    $listPrimaryActions[] = ['url' => '?entity=Site#create_page_button', 'label' => $trans('Create page')];
}
$listIntroHtml = '<p class="list-context-note">' . htmlspecialchars($trans('Page list intro'), ENT_QUOTES, 'UTF-8') . '</p>';
require __DIR__ . '/admin-yard/list.php.inc';
