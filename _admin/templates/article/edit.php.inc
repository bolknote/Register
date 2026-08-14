<?php

declare(strict_types=1);

/** @var string $basePath */
/** @var array $tagsList */
/** @var array $templateList */
/** @var array $labelList */
/** @var ?array $statusData Info about URL status */
/** @var callable $trans */
/** @var callable $param */
/** @var callable $isGranted */
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
$visibleControls = $form->getVisibleControls();

if (!$isPost && $param('S2_ADMIN_CUT')) {
    unset($visibleControls['excerpt']);
}

$takeControls = static function (array $fieldNames) use (&$visibleControls): array {
    $result = [];
    foreach ($fieldNames as $fieldName) {
        if (isset($visibleControls[$fieldName])) {
            $result[$fieldName] = $visibleControls[$fieldName];
            unset($visibleControls[$fieldName]);
        }
    }

    return $result;
};

$titleControls       = $takeControls(['title']);
$bodyControls        = $takeControls(['body']);
$additionalControls  = $takeControls(['excerpt', 'meta_description', 'meta_keywords']);
$tagControls         = $takeControls(['tags']);
$publicationControls = $takeControls(['published', 'scheduled_at', 'published_at', 'date_label']);
$detailControls      = array_merge(
    $takeControls(['series', 'template', 'author_id', 'featured', 'comments_enabled', 'slug', 'updated_at']),
    $visibleControls,
);

$formData = $form->getData();
$additionalHaveValue = false;
foreach (array_keys($additionalControls) as $fieldName) {
    if (trim((string)($formData[$fieldName] ?? '')) !== '') {
        $additionalHaveValue = true;
        break;
    }
}

$aiProvider = (string)$param(\Register\Ai\AiSettings::PROVIDER_CONFIG_KEY);
$aiConfigured = \Register\Ai\AiSettings::isSupportedProvider($aiProvider)
    && $aiProvider !== \Register\Ai\AiSettings::PROVIDER_DISABLED
    && trim((string)$param(\Register\Ai\AiSettings::API_KEY_CONFIG_KEY)) !== '';
$aiProviderLabel = match ($aiProvider) {
    \Register\Ai\AiSettings::PROVIDER_GEMINI => 'Gemini',
    \Register\Ai\AiSettings::PROVIDER_GROQ => 'Groq',
    default => '',
};

$renderControl = static function (string $fieldName, object $control, bool $showLabel = true) use (
    $commentsNum,
    $contentType,
    $header,
    $hint,
    $primaryKey,
    $trans,
): string {
    $controlClass = strtolower(basename(strtr(get_class($control), ['\\' => '/'])));
    ob_start();
    if ($control instanceof \S2\AdminYard\Form\Checkbox) {
        ?>
        <div class="editor-field editor-checkbox-field article-form-control form-control-<?= $controlClass ?> field-<?= $fieldName ?>">
            <label class="field-name" title="<?= htmlspecialchars($hint[$fieldName] ?? '', ENT_QUOTES, 'UTF-8') ?>" for="id-<?= $fieldName ?>">
                <?= $control->getHtml('id-' . $fieldName) ?>
                <span><?= htmlspecialchars($header[$fieldName], ENT_QUOTES, 'UTF-8') ?></span>
            </label>
            <?php foreach ($control->getValidationErrors() as $error): ?>
                <span class="validation-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            <?php endforeach; ?>
            <?php if ($fieldName === 'comments_enabled'): ?>
                <div class="editor-comments-link">
                    <?php if ($commentsNum > 0 && isset($primaryKey['id'])): ?>
                        <a href="?<?= http_build_query([
                            'entity'       => 'Comment',
                            'action'       => 'list',
                            'content_type' => $contentType,
                            'content_id'   => $primaryKey['id'],
                            'apply_filter' => 1,
                        ]) ?>"><?= $trans('N comments', ['{{ count }}' => $commentsNum, '%count%' => $commentsNum]) ?> &rarr;</a>
                    <?php else: ?>
                        <?= $trans('No comments') ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    } elseif ($control instanceof \S2\Cms\AdminYard\Form\HtmlTextarea) {
        ?>
        <div id="id-article-editor-block" class="article-form-control form-control-<?= $controlClass ?> field-<?= $fieldName ?>">
            <?= $control->getHtmlWithWrapper($trans, 'id-' . $fieldName) ?>
        </div>
        <?php
    } else {
        $controlHtml = $control->getHtml('id-' . $fieldName);
        if (!$showLabel) {
            $placeholder = htmlspecialchars($header[$fieldName], ENT_QUOTES, 'UTF-8');
            $controlHtml = preg_replace(
                '/<input\b/',
                '<input placeholder="' . $placeholder . '" aria-label="' . $placeholder . '"',
                $controlHtml,
                1,
            ) ?? $controlHtml;
        }
        ?>
        <div class="editor-field editor-field-<?= $fieldName ?><?= $showLabel ? '' : ' editor-placeholder-field' ?>">
            <?php if ($showLabel): ?>
                <label class="field-name" title="<?= htmlspecialchars($hint[$fieldName] ?? '', ENT_QUOTES, 'UTF-8') ?>" for="id-<?= $fieldName ?>">
                    <?= htmlspecialchars($header[$fieldName], ENT_QUOTES, 'UTF-8') ?>
                </label>
            <?php endif; ?>
            <div class="article-form-control form-control-<?= $controlClass ?> field-<?= $fieldName ?>">
                <?= $controlHtml ?>
                <?php foreach ($control->getValidationErrors() as $error): ?>
                    <span class="validation-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    return (string)ob_get_clean();
};

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
            <main class="article-form-column article-form-column-1 editor-main-column">
                <div class="editor-title-block">
                    <?php foreach ($titleControls as $fieldName => $control): ?>
                        <?= $renderControl($fieldName, $control, false) ?>
                    <?php endforeach; ?>
                </div>

                <section class="content-editor-ai-tools <?= $aiConfigured ? 'is-configured' : 'is-unconfigured' ?>" id="content-editor-ai-tools">
                    <div class="ai-tools-heading">
                        <span class="ai-tools-title"><span aria-hidden="true">✦</span> <?= $trans('AI assistant') ?></span>
                        <?php if ($aiConfigured): ?>
                            <span class="ai-provider-badge"><?= htmlspecialchars($aiProviderLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="ai-tools-hint"><?= $trans('AI selection hint') ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($aiConfigured): ?>
                        <div class="ai-tool-actions" role="group" aria-label="<?= $trans('AI assistant') ?>">
                            <button type="button" data-ai-action="proofread"><?= $trans('Proofread') ?></button>
                            <button type="button" data-ai-action="improve"><?= $trans('Improve text') ?></button>
                            <button type="button" data-ai-action="shorten"><?= $trans('Shorten text') ?></button>
                            <button type="button" data-ai-action="title"><?= $trans('Suggest title') ?></button>
                            <?php if ($tagControls !== []): ?>
                                <button type="button" data-ai-action="tags"><?= $trans('Suggest tags') ?></button>
                            <?php endif; ?>
                        </div>
                        <div class="ai-tools-status" id="ai-tools-status" aria-live="polite"></div>
                    <?php else: ?>
                        <p class="ai-setup-note">
                            <?= $trans('AI setup required') ?>
                            <?php if ($isGranted(\S2\Cms\Model\PermissionChecker::PERMISSION_VIEW_HIDDEN)): ?>
                                <a href="?entity=Config&amp;action=list"><?= $trans('Configure AI') ?> &rarr;</a>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </section>

                <div class="editor-body-block">
                    <?php foreach ($bodyControls as $fieldName => $control): ?>
                        <?= $renderControl($fieldName, $control) ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($additionalControls !== []): ?>
                    <details class="editor-secondary-panel" <?= $additionalHaveValue ? 'open' : '' ?>>
                        <summary>
                            <span><?= $trans('SEO and excerpt') ?></span>
                            <small><?= $trans('Optional field') ?></small>
                        </summary>
                        <div class="editor-secondary-fields">
                            <?php foreach ($additionalControls as $fieldName => $control): ?>
                                <?= $renderControl($fieldName, $control) ?>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>

                <?php if ($tagControls !== []): ?>
                    <div class="editor-tags-block">
                        <?php foreach ($tagControls as $fieldName => $control): ?>
                            <?= $renderControl($fieldName, $control, false) ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>

            <aside class="article-form-column article-form-column-2 editor-sidebar-column">
                <section class="editor-sidebar-card editor-publication-card">
                    <h2><?= $trans('Publication') ?></h2>
                    <p class="article-form-buttons">
                        <button type="submit" title="<?= $trans('Save info') ?>">
                            <?= $trans($isNew ? 'Create draft' : 'Save') ?>
                        </button>
                    </p>
                    <?php if ($isNew): ?>
                        <p class="content-editor-note"><?= $trans('Preview after first save') ?></p>
                    <?php else: ?>
                        <p class="editor-preview-link">
                            <a target="_blank" title="<?= $trans('Preview published') ?>" href="#" id="preview_link"><?= $trans('Preview ready') ?></a>
                        </p>
                    <?php endif; ?>
                    <?php if ($publicationControls !== []): ?>
                        <div class="editor-publication-fields">
                            <?php foreach ($publicationControls as $fieldName => $control): ?>
                                <?= $renderControl($fieldName, $control) ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($detailControls !== []): ?>
                    <details class="editor-sidebar-card editor-details-card">
                        <summary><?= $trans('Additional settings') ?></summary>
                        <div class="editor-detail-fields">
                            <?php foreach ($detailControls as $fieldName => $control): ?>
                                <?= $renderControl($fieldName, $control) ?>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </aside>
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
<script>
    window.S2_EDITOR_CONFIG = {
        formName: 'article-form',
        entityName: <?= json_encode($entityName, JSON_THROW_ON_ERROR) ?>,
        textareaName: 'body',
        slugFieldName: 'slug',
        templateId: <?= json_encode($isPost ? 'blog.php' : null, JSON_THROW_ON_ERROR) ?>,
        templateScope: <?= json_encode($contentType, JSON_THROW_ON_ERROR) ?>,
        statusData: <?= json_encode($statusData, JSON_THROW_ON_ERROR) ?>,
        tags: <?= json_encode([
            'inputId'          => 'id-tags',
            'suggestions'      => $tagsList,
            'suggestionsUrl'   => $basePath . '/_admin/ajax.php?action=register_tag_suggestions',
            'placeholder'      => $trans('Tags'),
            'removeLabel'      => $trans('Remove tag'),
            'suggestionsLabel' => $trans('Tag suggestions'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>,
        sUrl: '<?php echo $basePath; ?>/_admin/ajax.php?',
        datalists: <?= json_encode($isPost ? [[
            'inputName' => 'series',
            'listId' => 'post-labels-datalist',
        ]] : [[
            'inputName' => 'template',
            'listId' => 'article-templates-datalist',
            'placeholder' => $trans('inherited'),
        ]], JSON_THROW_ON_ERROR) ?>,
        ai: <?= json_encode([
            'enabled'       => $aiConfigured,
            'url'           => $basePath . '/_admin/ajax.php?action=register_ai_generate',
            'contentId'     => isset($primaryKey['id']) ? (int)$primaryKey['id'] : 0,
            'working'       => $trans('AI working'),
            'requestFailed' => $trans('AI request failed'),
            'unchanged'     => $trans('AI result unchanged'),
            'sourceChanged' => $trans('AI source changed'),
            'emptyText'     => $trans('AI empty text'),
            'proofreadClean'   => $trans('AI proofreading clean'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?>
    };

</script>
<script type="module" src="<?php echo $basePath; ?>/_admin/js/editor/entry.js"></script>
