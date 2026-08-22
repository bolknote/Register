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
/** @var \Register\AdminYard\Form\Form $form */
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

if (!$isPost && $param('REGISTER_ADMIN_CUT')) {
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
$activityPubControls = $takeControls([
    'activitypub_publication',
    'activitypub_delivery',
    'activitypub_object_type',
    'activitypub_visibility',
    'activitypub_summary',
    'activitypub_language',
]);
$detailControls      = array_merge(
    $takeControls(['series', 'template', 'author_id', 'featured', 'comments_enabled', 'slug', 'updated_at']),
    $visibleControls,
);

$formData = $form->getData();
$publishedControl   = $publicationControls['published'] ?? null;
$scheduledControl   = $publicationControls['scheduled_at'] ?? null;
$publishedAtControl = $publicationControls['published_at'] ?? null;
$dateLabelControl   = $publicationControls['date_label'] ?? null;
$hasValue = static function (mixed $value): bool {
    if ($value instanceof \DateTimeInterface) {
        return $value->getTimestamp() > 0;
    }

    return $value !== null && $value !== '' && $value !== 0 && $value !== '0' && $value !== false;
};
$publicationState = $hasValue($formData['published'] ?? false)
    ? 'published'
    : ($hasValue($formData['scheduled_at'] ?? null) ? 'scheduled' : 'draft');
$publicationSubmitLabel = $isNew
    ? match ($publicationState) {
        'scheduled' => $trans('Schedule'),
        'published' => $trans('Publish'),
        default => $trans('Create draft'),
    }
    : $trans('Save');
$dateLabelHaveValue = trim((string)($formData['date_label'] ?? '')) !== '';
$activityPubHaveValue = false;
foreach (array_keys($activityPubControls) as $fieldName) {
    $value = trim((string)($formData[$fieldName] ?? ''));
    if ($value !== '' && $value !== 'inherit') {
        $activityPubHaveValue = true;
        break;
    }
}
$additionalHaveValue = false;
foreach (array_keys($additionalControls) as $fieldName) {
    if (trim((string)($formData[$fieldName] ?? '')) !== '') {
        $additionalHaveValue = true;
        break;
    }
}

$aiProvider = (string)$param(\Register\Ai\AiSettings::PROVIDER_CONFIG_KEY);
$aiModel = trim((string)$param(\Register\Ai\AiSettings::MODEL_CONFIG_KEY));
if ($aiModel === '') {
    $aiModel = \Register\Ai\AiSettings::defaultModelForProvider($aiProvider);
}
$aiConfigured = \Register\Ai\AiSettings::isSupportedProvider($aiProvider)
    && $aiProvider !== \Register\Ai\AiSettings::PROVIDER_DISABLED
    && trim((string)$param(\Register\Ai\AiSettings::API_KEY_CONFIG_KEY)) !== ''
    && $aiModel !== ''
    && ($aiProvider !== \Register\Ai\AiSettings::PROVIDER_YANDEX
        || trim((string)$param(\Register\Ai\AiSettings::FOLDER_ID_CONFIG_KEY)) !== '')
    && ($aiProvider !== \Register\Ai\AiSettings::PROVIDER_CLOUDFLARE
        || trim((string)$param(\Register\Ai\AiSettings::CLOUDFLARE_ACCOUNT_ID_CONFIG_KEY)) !== '');
$aiAutoAltSetting = \in_array(
    strtolower(trim((string)$param(\Register\Ai\AiSettings::AUTO_ALT_CONFIG_KEY))),
    ['1', 'true', 'yes', 'on'],
    true,
);
$aiAutoAltEnabled = $aiConfigured
    && $aiAutoAltSetting
    && \Register\Ai\AiSettings::supportsImageInputFor($aiProvider, $aiModel);
$aiProviderLabel = match ($aiProvider) {
    \Register\Ai\AiSettings::PROVIDER_GEMINI => 'Gemini',
    \Register\Ai\AiSettings::PROVIDER_GROQ => 'Groq',
    \Register\Ai\AiSettings::PROVIDER_OPENROUTER => 'OpenRouter',
    \Register\Ai\AiSettings::PROVIDER_MISTRAL => 'Mistral',
    \Register\Ai\AiSettings::PROVIDER_CLOUDFLARE => 'Cloudflare Workers AI',
    \Register\Ai\AiSettings::PROVIDER_YANDEX => 'Yandex AI Studio',
    \Register\Ai\AiSettings::PROVIDER_GIGACHAT => 'GigaChat',
    default => '',
};

$editorConfig = [
    'formName'      => 'article-form',
    'entityName'    => $entityName,
    'textareaName'  => 'body',
    'slugFieldName' => 'slug',
    'templateId'    => $isPost ? 'blog.php' : null,
    'templateScope' => $contentType,
    'statusData'    => $statusData,
    'tags'          => [
        'inputId'          => 'id-tags',
        'suggestions'      => $tagsList,
        'suggestionsUrl'   => $basePath . '/_admin/ajax.php?action=register_tag_suggestions',
        'placeholder'      => $trans('Tags'),
        'removeLabel'      => $trans('Remove tag'),
        'suggestionsLabel' => $trans('Tag suggestions'),
    ],
    'sUrl'                   => $basePath . '/_admin/ajax.php?',
    'previewErrorStylesheet' => $basePath . '/_admin/css/editor-preview-error.css',
    'imageOverlayStylesheet' => $basePath . '/_admin/css/editor-image-overlay.css',
    'datalists'     => $isPost ? [[
        'inputName' => 'series',
        'listId'    => 'post-labels-datalist',
    ]] : [[
        'inputName'   => 'template',
        'listId'      => 'article-templates-datalist',
        'placeholder' => $trans('inherited'),
    ]],
    'ai'            => [
        'enabled'          => $aiConfigured,
        'url'              => $basePath . '/_admin/ajax.php?action=register_ai_generate',
        'contentId'        => isset($primaryKey['id']) ? (int)$primaryKey['id'] : 0,
        'working'          => $trans('AI working'),
        'requestFailed'    => $trans('AI request failed'),
        'unchanged'        => $trans('AI result unchanged'),
        'sourceChanged'    => $trans('AI source changed'),
        'emptyText'        => $trans('AI empty text'),
        'proofreadClean'   => $trans('AI proofreading clean'),
        'alt'              => [
            'enabled'       => $aiAutoAltEnabled,
            'url'           => $basePath . '/_admin/ajax.php?action=register_ai_generate_alt',
            'generating'    => $trans('AI alt generating'),
            'requestFailed' => $trans('AI alt failed'),
            'retry'         => $trans('AI alt retry'),
            'regenerate'    => $trans('AI alt regenerate'),
            'edit'          => $trans('AI alt edit'),
            'empty'         => $trans('AI alt empty'),
            'preview'       => $trans('AI alt preview'),
        ],
    ],
    'activityPub'   => [
        'enabled'   => $activityPubControls !== [],
        'url'       => $basePath . '/_admin/ajax.php?action=register_activitypub_content_preview',
        'contentId' => isset($primaryKey['id']) ? (int)$primaryKey['id'] : 0,
        'working'   => $trans('ActivityPub preview working'),
        'failed'    => $trans('ActivityPub preview failed'),
        'noObject'  => $trans('ActivityPub preview no object'),
    ],
];
$editorConfigJson = htmlspecialchars(
    json_encode($editorConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR),
    ENT_QUOTES,
    'UTF-8',
);

$renderControl = static function (
    string $fieldName,
    object $control,
    bool $showLabel = true,
    ?string $labelOverride = null,
) use (
    $commentsNum,
    $contentType,
    $header,
    $hint,
    $primaryKey,
    $trans,
): string {
    $controlClass = strtolower(basename(strtr(get_class($control), ['\\' => '/'])));
    $fieldLabel = $labelOverride ?? $header[$fieldName];
    ob_start();
    if ($control instanceof \Register\AdminYard\Form\Checkbox) {
        ?>
        <div class="editor-field editor-checkbox-field article-form-control form-control-<?= $controlClass ?> field-<?= $fieldName ?>">
            <label class="field-name" title="<?= htmlspecialchars($hint[$fieldName] ?? '', ENT_QUOTES, 'UTF-8') ?>" for="id-<?= $fieldName ?>">
                <?= $control->getHtml('id-' . $fieldName) ?>
                <span><?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?></span>
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
    } elseif ($control instanceof \Register\Core\AdminYard\Form\HtmlTextarea) {
        ?>
        <div id="id-article-editor-block" class="article-form-control form-control-<?= $controlClass ?> field-<?= $fieldName ?>" data-html-editor-root>
            <?= $control->getHtmlWithWrapper($trans, 'id-' . $fieldName, $fieldLabel) ?>
        </div>
        <?php
    } else {
        $controlHtml = $control->getHtml('id-' . $fieldName);
        if (!$showLabel) {
            $placeholder = htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8');
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
                    <?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?>
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

<section class="edit-content large-edit-form content-edit-content <?= $isPost ? 'post' : 'article' ?>-edit-content <?= $isNew ? 'is-new' : 'is-edit' ?>"
         data-editor-config="<?= $editorConfigJson ?>">
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
            <div class="article-form-column article-form-column-1 editor-main-column">
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
                            <?php if ($isGranted(\Register\Core\Model\PermissionChecker::PERMISSION_VIEW_HIDDEN)): ?>
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
            </div>

            <aside class="article-form-column article-form-column-2 editor-sidebar-column">
                <section class="editor-sidebar-card editor-publication-card">
                    <h2><?= $trans('Publication') ?></h2>
                    <p class="article-form-buttons">
                        <button
                            type="submit"
                            title="<?= $trans('Save info') ?>"
                            data-publication-submit
                            data-draft-label="<?= htmlspecialchars($isNew ? $trans('Create draft') : $trans('Save'), ENT_QUOTES, 'UTF-8') ?>"
                            data-scheduled-label="<?= htmlspecialchars($isNew ? $trans('Schedule') : $trans('Save'), ENT_QUOTES, 'UTF-8') ?>"
                            data-published-label="<?= htmlspecialchars($isNew ? $trans('Publish') : $trans('Save'), ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?= htmlspecialchars($publicationSubmitLabel, ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </p>
                    <?php if ($isNew): ?>
                        <p class="content-editor-note"><?= $trans('Preview after first save') ?></p>
                    <?php else: ?>
                        <p class="editor-preview-link">
                            <a target="_blank" title="<?= $trans('Preview published') ?>" href="#" id="preview_link" <?= $publicationState === 'published' ? '' : 'hidden' ?>><?= $trans('Preview ready') ?></a>
                        </p>
                    <?php endif; ?>
                    <?php if ($publicationControls !== []): ?>
                        <div class="editor-publication-fields">
                            <?php if ($publishedControl !== null): ?>
                                <fieldset
                                    class="editor-publication-state"
                                    data-publication-state
                                    data-state="<?= $publicationState ?>"
                                >
                                    <legend><?= $trans('Publication status') ?></legend>
                                    <div class="editor-publication-options">
                                        <?php foreach ([
                                            'draft' => ['Draft', 'Draft publication help'],
                                            'scheduled' => ['Scheduled', 'Scheduled publication state help'],
                                            'published' => ['Published', 'Published publication help'],
                                        ] as $stateValue => [$stateLabel, $stateHelp]): ?>
                                            <?php $stateHelpId = 'publication-state-' . $stateValue . '-help'; ?>
                                            <label class="editor-publication-option">
                                                <input
                                                    type="radio"
                                                    name="_publication_state"
                                                    value="<?= $stateValue ?>"
                                                    data-publication-state-input
                                                    aria-describedby="<?= $stateHelpId ?>"
                                                    <?= $publicationState === $stateValue ? 'checked' : '' ?>
                                                >
                                                <span>
                                                    <strong><?= $trans($stateLabel) ?></strong>
                                                    <small id="<?= $stateHelpId ?>"><?= $trans($stateHelp) ?></small>
                                                </span>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                </fieldset>

                                <div data-publication-native-control hidden aria-hidden="true">
                                    <?= $publishedControl->getHtml('id-published') ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($scheduledControl !== null): ?>
                                <div data-publication-scheduled <?= $publicationState === 'scheduled' ? '' : 'hidden' ?>>
                                    <?= $renderControl('scheduled_at', $scheduledControl) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($publishedAtControl !== null): ?>
                                <div data-publication-published-at <?= $publicationState === 'published' ? '' : 'hidden' ?>>
                                    <?= $renderControl('published_at', $publishedAtControl, true, $trans('Publication date')) ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($dateLabelControl !== null): ?>
                                <details class="editor-publication-display" <?= $dateLabelHaveValue ? 'open' : '' ?>>
                                    <summary>
                                        <span><?= $trans('Display date settings') ?></span>
                                        <small><?= $trans('Optional field') ?></small>
                                    </summary>
                                    <div class="editor-publication-display-fields">
                                        <?= $renderControl('date_label', $dateLabelControl) ?>
                                    </div>
                                </details>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>

                <?php if ($activityPubControls !== []): ?>
                    <details
                        class="editor-sidebar-card editor-details-card editor-activitypub-card"
                        data-activitypub-editor-panel
                        <?= $activityPubHaveValue ? 'open' : '' ?>
                    >
                        <summary>ActivityPub</summary>
                        <div class="editor-detail-fields">
                            <p class="editor-panel-description">
                                <?= $trans('ActivityPub editor panel help') ?>
                            </p>
                            <?php foreach ($activityPubControls as $fieldName => $control): ?>
                                <?= $renderControl($fieldName, $control) ?>
                            <?php endforeach; ?>
                            <div class="activitypub-editor-preview">
                                <button type="button" data-activitypub-preview-button>
                                    <?= $trans('ActivityPub build preview') ?>
                                </button>
                                <p class="activitypub-editor-preview-status" data-activitypub-preview-status aria-live="polite"></p>
                                <div data-activitypub-preview-result hidden>
                                    <p class="activitypub-editor-preview-metadata" data-activitypub-preview-metadata></p>
                                    <p class="activitypub-editor-preview-provisional" data-activitypub-preview-provisional hidden></p>
                                    <iframe
                                        class="activitypub-editor-preview-frame"
                                        data-activitypub-preview-frame
                                        sandbox=""
                                        title="<?= $trans('ActivityPub content preview') ?>"
                                    ></iframe>
                                    <details class="activitypub-editor-preview-json">
                                        <summary><?= $trans('ActivityPub exact JSON') ?></summary>
                                        <pre data-activitypub-preview-json></pre>
                                    </details>
                                </div>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>

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
    <button type="button" class="picture-dialog-close">&times;</button>
    <iframe src="" frameborder="0" id="picture_frame" name="picture_frame"></iframe>
</dialog>
<script type="module" src="<?php echo $basePath; ?>/_admin/js/editor/entry.js"></script>
