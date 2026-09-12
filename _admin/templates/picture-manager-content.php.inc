<?php

declare(strict_types = 1);

/** @var string $basePath */
/** @var string $locale */
/** @var callable $trans */
/** @var callable $isGranted */
/** @var callable $friendlyFilesize */
/** @var callable $adminAssetVersion */
/** @var string $imagePath */
/** @var bool $standalone */

$html = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$bytesFromString = static function (string|false $value): int {
    if (!\is_string($value) || preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmgt]?)b?\s*$/i', $value, $matches) !== 1) {
        return 0;
    }

    $power = match (strtolower($matches[2])) {
        'k' => 1,
        'm' => 2,
        'g' => 3,
        't' => 4,
        default => 0,
    };

    return (int)round((float)$matches[1] * 1024 ** $power);
};
$maxFileSize = $bytesFromString(ini_get('upload_max_filesize'));
$maxPostSize = $bytesFromString(ini_get('post_max_size'));

?>
<link rel="stylesheet" href="<?= $html($basePath) ?>/_admin/css/pictures.css?v=<?= $html($adminAssetVersion('_admin/css/pictures.css')) ?>">
<link rel="stylesheet" href="<?= $html($basePath) ?>/_admin/css/media-library.css?v=<?= $html($adminAssetVersion('_admin/css/media-library.css')) ?>">
<section class="picture-manager-page <?= $standalone ? 'is-standalone' : 'is-embedded' ?>"
         data-picture-manager
         data-ajax-url="<?= $html($basePath . '/_admin/ajax.php?') ?>"
         data-picture-prefix="<?= $html($imagePath) ?>"
         data-max-file-size="<?= $maxFileSize ?>"
         data-friendly-max-file-size="<?= $html($friendlyFilesize($maxFileSize)) ?>"
         data-empty-directory="<?= $html($trans('Empty directory')) ?>"
         data-file-count="<?= $html($trans('Media file count')) ?>"
         data-selected-count="<?= $html($trans('Media selected count')) ?>"
         aria-labelledby="media-library-title">
    <?php if (!$standalone): ?>
        <header class="media-library-header">
            <div>
                <h1 id="media-library-title"><?= $html($trans('Media')) ?></h1>
                <p><?= $html($trans('Media library intro')) ?></p>
            </div>
        </header>
    <?php else: ?>
        <h1 class="visually-hidden" id="media-library-title"><?= $html($trans('Media')) ?></h1>
    <?php endif; ?>

    <div id="tree_div" class="media-browser">
        <aside class="treetree media-browser-sidebar">
            <?php if ($isGranted(\Register\Core\Model\PermissionChecker::PERMISSION_CREATE_ARTICLES)): ?>
                <form id="uploadForm" class="media-upload-panel" enctype="multipart/form-data"
                      action="<?= $html($basePath) ?>/_admin/ajax.php?action=upload" method="post">
                    <div id="loading_pict" class="loading-indicator"></div>
                    <h2><?= $html($trans('Upload files')) ?></h2>
                    <p class="media-upload-target">
                        <?= $html($trans('Upload to')) ?>
                        <span id="fold_name"><strong><?= $html($trans('Pictures')) ?></strong></span>
                    </p>
                    <label class="media-upload-button" for="media-upload-input"><?= $html($trans('Choose files')) ?></label>
                    <input class="visually-hidden" id="media-upload-input" name="pictures[]" multiple min="1" max="20" type="file">
                    <span class="media-upload-selection" data-media-upload-selection aria-live="polite"></span>
                    <p class="media-upload-limit">
                        <?php printf(
                            $trans('Upload limit'),
                            $friendlyFilesize($maxFileSize),
                            $friendlyFilesize($maxPostSize),
                        ) ?>
                    </p>
                    <input type="hidden" name="dir" value="">
                    <input type="hidden" name="csrf_token" value="">
                </form>
            <?php endif; ?>

            <details class="media-folder-panel" open>
                <summary id="media-folders-title"><?= $html($trans('Folders')) ?></summary>
                <div id="folders" tabindex="0" aria-label="<?= $html($trans('Folders')) ?>"></div>
            </details>
        </aside>

        <section id="file-wrap" class="media-files-panel" aria-labelledby="media-files-title">
            <header>
                <div>
                    <h2 id="media-files-title"><?= $html($trans('Files')) ?></h2>
                    <p data-media-folder-path aria-label="<?= $html($trans('Media folder location')) ?>"><?= $html($trans('Pictures')) ?></p>
                </div>
                <button type="button" class="link-as-button secondary" data-media-refresh><?= $html($trans('Media refresh')) ?></button>
            </header>
            <div class="media-file-toolbar">
                <label class="media-search-field">
                    <span><?= $html($trans('Media search')) ?></span>
                    <input type="search" data-media-search autocomplete="off" placeholder="<?= $html($trans('Media search')) ?>">
                </label>
                <label class="media-type-field">
                    <span><?= $html($trans('Media file type')) ?></span>
                    <select data-media-type>
                        <?php foreach (['all' => 'Media all types', 'image' => 'Media images', 'audio' => 'Media audio', 'video' => 'Media video', 'document' => 'Media documents', 'other' => 'Media other files'] as $type => $label): ?>
                            <option value="<?= $html($type) ?>"><?= $html($trans($label)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="media-file-count" data-media-count role="status" aria-live="polite"></p>
            </div>
            <div class="media-selection-bar" data-media-selection-bar hidden>
                <span data-media-selected-count></span>
                <?php if ($isGranted(\Register\Core\Model\PermissionChecker::PERMISSION_EDIT_SITE)): ?>
                    <button type="button" class="link-as-button secondary" data-media-rename><?= $html($trans('Media rename')) ?></button>
                    <button type="button" class="link-as-button secondary media-delete-button" data-media-delete><?= $html($trans('Delete')) ?></button>
                <?php endif; ?>
            </div>
            <div id="brd">
                <div id="files" tabindex="0" aria-label="<?= $html($trans('Files')) ?>"></div>
                <div id="loadstatus" role="status" aria-live="polite"></div>
                <div class="media-empty-state" data-media-empty hidden>
                    <h3><?= $html($trans('Media empty folder')) ?></h3>
                    <p><?= $html($trans($isGranted(\Register\Core\Model\PermissionChecker::PERMISSION_CREATE_ARTICLES) ? 'Media empty folder hint' : 'Media empty folder browse hint')) ?></p>
                </div>
                <div class="media-empty-state" data-media-no-matches hidden>
                    <h3><?= $html($trans('Media no matches')) ?></h3>
                    <p><?= $html($trans('Media no matches hint')) ?></p>
                    <button type="button" class="link-as-button secondary" data-media-reset><?= $html($trans('Media reset filters')) ?></button>
                </div>
            </div>
            <div id="finfo" class="media-selection-info" aria-live="polite"></div>
            <?php if ($isGranted(\Register\Core\Model\PermissionChecker::PERMISSION_CREATE_ARTICLES)): ?>
                <p class="media-files-hint"><?= $html($trans('Media drop hint')) ?></p>
            <?php endif; ?>
        </section>
    </div>

    <?php if ($standalone): ?>
        <?php require __DIR__ . '/confirm-dialog.php.inc'; ?>
        <dialog id="error-dialog">
            <h1><?= $html($trans('An error encountered')) ?></h1>
            <p><iframe id="error-iframe" src="about:blank"></iframe></p>
            <button class="primary" id="error-dialog-close"><?= $html($trans('Close')) ?></button>
        </dialog>
    <?php endif; ?>
</section>
<?php if ($standalone): ?>
    <script src="<?= $html($basePath) ?>/_admin/lang/<?= $html($locale) ?>/ui.js"></script>
<?php endif; ?>
<script src="<?= $html($basePath) ?>/_admin/js/ajax.js"></script>
<script src="<?= $html($basePath) ?>/_admin/lib/jquery.js"></script>
<script src="<?= $html($basePath) ?>/_admin/lib/jquery-tools.js"></script>
<script src="<?= $html($basePath) ?>/_admin/lib/jquery.jstree.js"></script>
<script src="<?= $html($basePath) ?>/_admin/js/pictman.js?v=<?= $html($adminAssetVersion('_admin/js/pictman.js')) ?>"></script>
