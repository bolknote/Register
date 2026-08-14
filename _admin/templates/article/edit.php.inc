<?php

declare(strict_types=1);

/** @var string $basePath */
/** @var array $tagsList */
/** @var array $templateList */
/** @var array $labelList */
/** @var ?array $statusData Info about URL status */
/** @var callable $trans */
/** @var callable $param */
/** @var string $title */
/** @var string $entityName */
/** @var array $header */
/** @var array $hint */
/** @var array $errorMessages */
/** @var \S2\AdminYard\Form\Form $form */
/** @var string|null $csrfToken */
/** @var array|null $primaryKey */
/** @var array $actions */
/** @var int|null $commentsNum */

$primaryKey   = $primaryKey ?? [];
$csrfToken    = $csrfToken ?? null;
$tagsList     = $tagsList ?? [];
$templateList = $templateList ?? [];
$labelList    = $labelList ?? [];
$statusData   = $statusData ?? null;
$commentsNum  = $commentsNum ?? 0;
$isNew        = $primaryKey === [];

$formQueryParams = http_build_query(array_merge([
    'entity' => $entityName,
    'action' => $isNew ? 'new' : 'edit'
], $primaryKey));

$errorMessages = array_merge($errorMessages, $form->getGlobalFormErrors());

$isPost = match ($entityName) {
    'BlogPost' => true,
    'Article' => false,
    default => throw new \LogicException('The content editor supports posts and pages only.'),
};
$contentType = $isPost ? 'post' : 'page';

// NOTE: order affects the order in HTML and focus (implicit tab order).
$columnMap = $isPost
    ? [
        'title'            => ['column' => 1,],
        'tags'             => ['column' => 1,],
        'body'             => ['column' => 1, 'input_area' => '2/1/3/5'],
        'author_id'        => ['column' => 2,],
        'series'           => ['column' => 2,],
        'published_at'     => ['column' => 2,],
        'scheduled_at'     => ['column' => 2,],
        'date_label'       => ['column' => 2,],
        'updated_at'       => ['column' => 2,],
        'featured'         => ['column' => 2,],
        'comments_enabled' => ['column' => 2,],
        'slug'             => ['column' => 2,],
        'published'        => ['column' => 2,],
    ]
    : [
        'title'            => ['column' => 1,],
        'tags'             => ['column' => 1,],
        'body'             => ['column' => 1, 'input_area' => '4/1/5/5'],
        'meta_description' => ['column' => 1,],
        'meta_keywords'    => ['column' => 1,],
        'excerpt'          => ['column' => 1, 'label_area' => '3/1/4/2', 'input_area' => '3/2/4/5'],
        'author_id'        => ['column' => 2,],
        'template'         => ['column' => 2,],
        'published_at'     => ['column' => 2,],
        'scheduled_at'     => ['column' => 2,],
        'updated_at'       => ['column' => 2,],
        'featured'         => ['column' => 2,],
        'comments_enabled' => ['column' => 2,],
        'slug'             => ['column' => 2,],
        'published'        => ['column' => 2,],
    ];

$visibleControls = $form->getVisibleControls();

if (!$isPost && $param('S2_ADMIN_CUT')) {
    unset($visibleControls['excerpt'], $columnMap['excerpt']);
}

/** @var array<string,\S2\Cms\Framework\ControllerInterface>[] $columns */
$columns = [1 => [], 2 => []];
foreach ($columnMap as $fieldName => $columnInfo) {
    if (!isset($visibleControls[$fieldName])) {
        continue;
    }
    $columns[$columnInfo['column']][$fieldName] = $visibleControls[$fieldName];
    unset($visibleControls[$fieldName]);
}

$columns[1] = array_merge($columns[1], $visibleControls);

?>
<link rel="stylesheet" href="<?php echo $basePath; ?>/_admin/lib/codemirror.css"/>
<link rel="stylesheet" href="<?php echo $basePath; ?>/_admin/lib/codemirror/foldgutter.css"/>
<link rel="stylesheet" href="<?php echo $basePath; ?>/_admin/lib/codemirror/dialog.css"/>
<link rel="stylesheet" href="<?php echo $basePath; ?>/_admin/lib/codemirror/matchesonscrollbar.css"/>

<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/codemirror.min.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/selection-pointer.min.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/xml.min.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/javascript.min.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/css.min.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/htmlmixed.min.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/clike.min.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/php.min.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/foldcode.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/foldgutter.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/xml-fold.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/annotatescrollbar.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/dialog.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/jump-to-line.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/matchesonscrollbar.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/search.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/codemirror/searchcursor.js"></script>
<script src="<?php echo $basePath; ?>/_admin/lib/morphdom-umd.min.js"></script>

<script src="<?php echo $basePath; ?>/_admin/lib/image-q.min.js"></script>

<section class="edit-content large-edit-form content-edit-content <?= $isPost ? 'post' : 'article' ?>-edit-content <?= $isNew ? 'is-new' : 'is-edit' ?>">
    <header class="content-editor-header">
        <h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
        <a href="?<?= http_build_query(['entity' => $entityName, 'action' => 'list']) ?>">
            <?= htmlspecialchars($trans('Back to list'), ENT_QUOTES, 'UTF-8') ?>
        </a>
    </header>
    <?php if (!empty($errorMessages)): ?>
        <div class="error-message-box">
            <p><?php printf($trans($isNew ? 'Cannot create new %s due to the following errors:' : 'Cannot save %s due to the following errors:'), $entityName) ?></p>
            <ul class="error-messages">
                <?php foreach ($errorMessages as $message): ?>
                    <li><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <form name="article-form" method="POST" action="?<?= $formQueryParams ?>">
        <?php foreach ($form->getHiddenControls() as $control): ?>
            <?= $control->getHtml() ?>
        <?php endforeach; ?>
        <div class="large-form-wrapper">
            <?php foreach ($columns as $columnIndex => $controls): ?>
                <div class="article-form-column article-form-column-<?= $columnIndex ?>">
                    <?php foreach ($controls as $fieldName => $control): ?>
                        <?php if ($control instanceof \S2\AdminYard\Form\Checkbox): ?>
                            <div
                                    style="grid-area: <?= $columnMap[$fieldName]['input_area'] ?? 'auto' ?>"
                                    class="article-form-control form-control-<?= strtolower(basename(strtr(get_class($control), ['\\' => '/']))) ?> field-<?= $fieldName ?>">
                                <label class="field-name"
                                       style="grid-area: <?= $columnMap[$fieldName]['label_area'] ?? 'auto' ?>"
                                       title="<?= htmlspecialchars($hint[$fieldName] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                       for="id-<?= $fieldName ?>">
                                    <?= $control->getHtml('id-' . $fieldName) ?>
                                    <?= htmlspecialchars($header[$fieldName], ENT_QUOTES, 'UTF-8') ?>
                                </label>
                                <?php foreach ($control->getValidationErrors() as $error): ?>
                                    <span class="validation-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                                <?php if ($fieldName === 'comments_enabled') {
                                    if ($commentsNum > 0) {
                                        echo '<div><a href="?' . http_build_query([
                                                        'entity'  => 'Comment',
                                                        'action'  => 'list',
                                                        'content_type' => $contentType,
                                                        'content_id' => $primaryKey['id'],
                                                        'apply_filter' => 1,
                                                ]) . '">' . $trans('N comments', ['{{ count }}' => $commentsNum, '%count%' => $commentsNum]) . ' &rarr;</a></div>';
                                    } else {
                                        echo '<div>' . $trans('No comments') . '</div>';
                                    }
                                } ?>
                            </div>
                        <?php elseif ($control instanceof \S2\Cms\AdminYard\Form\HtmlTextarea): ?>
                            <div style="grid-area: <?= $columnMap[$fieldName]['input_area'] ?? 'auto' ?>"
                                 id="id-article-editor-block"
                                 class="article-form-control form-control-<?= strtolower(basename(strtr(get_class($control), ['\\' => '/']))) ?> field-<?= $fieldName ?>">
                                <?= $control->getHtmlWithWrapper($trans, 'id-' . $fieldName) ?>
                            </div>
                        <?php else: ?>
                            <label class="field-name"
                                   style="grid-area: <?= $columnMap[$fieldName]['label_area'] ?? 'auto' ?>"
                                   title="<?= htmlspecialchars($hint[$fieldName] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                   for="id-<?= $fieldName ?>"><?= htmlspecialchars($header[$fieldName], ENT_QUOTES, 'UTF-8') ?></label>
                            <div style="grid-area: <?= $columnMap[$fieldName]['input_area'] ?? 'auto' ?>"
                                 class="article-form-control form-control-<?= strtolower(basename(strtr(get_class($control), ['\\' => '/']))) ?> field-<?= $fieldName ?>">
                                <?= $control->getHtml('id-' . $fieldName) ?>
                                <?php foreach ($control->getValidationErrors() as $error): ?>
                                    <span class="validation-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($columnIndex === 2): ?>
                        <p class="article-form-buttons">
                            <button type="submit" title="<?= $trans('Save info') ?>">
                                <?= $trans($isNew ? 'Create draft' : 'Save') ?>
                            </button>
                        </p>
                        <hr>
                        <?php if ($isNew): ?>
                            <p class="content-editor-note"><?= $trans('Preview after first save') ?></p>
                        <?php else: ?>
                            <p>
                                <a target="_blank" title="<?= $trans('Preview published') ?>"
                                   href="#" id="preview_link"><?= $trans('Preview ready') ?></a>
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($isPost): ?>
            <datalist id="post-labels-datalist">
                <?php foreach ($labelList as $labelItem): ?>
                    <option value="<?= htmlspecialchars($labelItem, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($labelItem, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </datalist>
        <?php else: ?>
            <datalist id="article-templates-datalist">
                <?php foreach ($templateList as $templateItem): ?>
                    <option value="<?= htmlspecialchars($templateItem, ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($templateItem, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </datalist>
        <?php endif; ?>
    </form>
</section>
<dialog id="picture_dialog">
    <button class="picture-dialog-close" onclick="this.parentNode.close()">&times;</button>
    <iframe src="" frameborder="0" id="picture_frame" name="picture_frame"></iframe>
</dialog>
<script src="<?php echo $basePath; ?>/_admin/lib/autoComplete.min.js"></script>
<script>
    window.S2_EDITOR_CONFIG = {
        formName: 'article-form',
        entityName: <?= json_encode($entityName, JSON_THROW_ON_ERROR) ?>,
        textareaName: 'body',
        slugFieldName: 'slug',
        templateId: <?= json_encode($isPost ? 'blog.php' : null, JSON_THROW_ON_ERROR) ?>,
        templateScope: <?= json_encode($contentType, JSON_THROW_ON_ERROR) ?>,
        statusData: <?= json_encode($statusData, JSON_THROW_ON_ERROR) ?>,
        tagsInputId: 'id-tags',
        tagsList: <?= json_encode($tagsList, JSON_THROW_ON_ERROR) ?>,
        sUrl: '<?php echo $basePath; ?>/_admin/ajax.php?',
        datalists: <?= json_encode($isPost ? [[
            'inputName' => 'series',
            'listId' => 'post-labels-datalist',
        ]] : [[
            'inputName' => 'template',
            'listId' => 'article-templates-datalist',
            'placeholder' => $trans('inherited'),
        ]], JSON_THROW_ON_ERROR) ?>
    };

</script>
<script type="module" src="<?php echo $basePath; ?>/_admin/js/editor/entry.js"></script>
