<?php

declare(strict_types = 1);

/** @var callable $trans */
/** @var callable $isGranted */
/** @var callable $html */
/** @var string $entityName */

use Register\Core\Model\PermissionChecker;

$moderationEntities = [
    'Comment',
    'SpamAssessment',
    'SpamRule',
    'SpamSignalPolicy',
    'SpamRatePolicy',
];
if (!\in_array($entityName, $moderationEntities, true)) {
    return;
}

$canTuneAntispam = $isGranted(PermissionChecker::PERMISSION_EDIT_USERS);
$sections = [
    'Comment' => [
        'label' => 'Comments',
        'url'   => '?entity=Comment&action=list',
    ],
];
if ($canTuneAntispam) {
    $sections += [
        'SpamAssessment' => [
            'label' => 'Antispam report',
            'url'   => '?entity=SpamAssessment&action=list',
        ],
        'SpamRule' => [
            'label' => 'Spam rules',
            'url'   => '?entity=SpamRule&action=list',
        ],
        'Expert' => [
            'label' => 'Expert settings',
            'url'   => '?entity=SpamSignalPolicy&action=list',
        ],
    ];
}

$expertEntities = ['SpamSignalPolicy', 'SpamRatePolicy'];
$activeSection = \in_array($entityName, $expertEntities, true) ? 'Expert' : $entityName;

?>
<div class="moderation-navigation">
    <nav class="section-tabs moderation-tabs" aria-label="<?= $html($trans('Moderation sections')) ?>">
        <?php foreach ($sections as $sectionKey => $section): ?>
            <a href="<?= $html($section['url']) ?>" <?= $activeSection === $sectionKey ? 'aria-current="page"' : '' ?>>
                <?= $html($trans($section['label'])) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if ($activeSection === 'Expert' && $canTuneAntispam): ?>
        <nav class="moderation-subtabs" aria-label="<?= $html($trans('Antispam expert settings')) ?>">
            <?php foreach ([
                'SpamSignalPolicy' => 'Spam signal weights',
                'SpamRatePolicy' => 'Comment rate limits',
            ] as $sectionEntity => $sectionLabel): ?>
                <a
                    href="?entity=<?= $html($sectionEntity) ?>&amp;action=list"
                    <?= $entityName === $sectionEntity ? 'aria-current="page"' : '' ?>
                ><?= $html($trans($sectionLabel)) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
</div>
