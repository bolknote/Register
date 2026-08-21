<?php

declare(strict_types = 1);

/** @var string $basePath */
/** @var callable $trans */
/** @var string $title */
/** @var list<array<string, mixed>> $rows */

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

/** @var list<array{id: string, title: string, rows: list<array<string, mixed>>}> $sections */
$sections            = [];
$currentSectionIndex = -1;
$hasPendingSettings  = false;
foreach ($rows as $row) {
    if (($row['cells']['name']['type'] ?? null) === 'config-title') {
        $sections[] = [
            'id'    => (string)($row['config']['section_id'] ?? 'settings-section-' . count($sections)),
            'title' => (string)$row['cells']['name']['content'],
            'rows'  => [],
        ];
        $currentSectionIndex = array_key_last($sections);
        continue;
    }

    if ($currentSectionIndex < 0) {
        $sections[] = [
            'id'    => 'settings-other',
            'title' => $trans('Other settings'),
            'rows'  => [],
        ];
        $currentSectionIndex = 0;
    }

    $sections[$currentSectionIndex]['rows'][] = $row;
    $hasPendingSettings = $hasPendingSettings || !($row['config']['is_applied'] ?? true);
}

?>
<link rel="stylesheet" href="<?= $escape($basePath) ?>/_admin/css/config-ai-help.css">
<script src="<?= $escape($basePath) ?>/_admin/js/config-secret.js"></script>
<script src="<?= $escape($basePath) ?>/_admin/js/config-settings.js"></script>

<section class="config-page" aria-labelledby="settings-title">
    <header class="config-header">
        <div>
            <h1 id="settings-title"><?= $escape($title) ?></h1>
            <p><?= $escape($trans('Settings intro')) ?></p>
        </div>
        <p class="config-page-state" data-config-page-state
           data-applied-message="<?= $escape($trans('All settings applied')) ?>"
           data-pending-message="<?= $escape($trans('Some settings are not applied')) ?>"
           data-saving-message="<?= $escape($trans('Saving settings')) ?>"
           data-dirty-message="<?= $escape($trans('Settings have unsaved changes')) ?>"
           data-error-message="<?= $escape($trans('Some settings could not be applied')) ?>"
           data-state="<?= $hasPendingSettings ? 'pending' : 'applied' ?>"
           role="status" aria-live="polite">
            <?= $escape($trans($hasPendingSettings ? 'Some settings are not applied' : 'All settings applied')) ?>
        </p>
    </header>

    <?php if (count($sections) > 1): ?>
        <nav class="config-section-nav" aria-label="<?= $escape($trans('Settings sections')) ?>">
            <?php foreach ($sections as $section): ?>
                <a href="#<?= $escape($section['id']) ?>"><?= $escape($section['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="config-sections">
        <?php foreach ($sections as $section): ?>
            <section class="config-section" id="<?= $escape($section['id']) ?>">
                <header class="config-section-header">
                    <h2><?= $escape($section['title']) ?></h2>
                </header>
                <div class="config-setting-list">
                    <?php foreach ($section['rows'] as $row): ?>
                        <?php
                        /** @var array<string, mixed> $config */
                        $config     = $row['config'] ?? [];
                        $dependency = $config['dependency'] ?? null;
                        $editable   = (bool)($config['editable'] ?? false);
                        $name       = (string)$row['cells']['name']['content'];
                        $help       = html_entity_decode(
                            (string)$row['cells']['help']['content'],
                            ENT_QUOTES | ENT_HTML5,
                            'UTF-8',
                        );
                        ?>
                        <article class="config-setting"
                                 data-config-key="<?= $escape((string)($config['key'] ?? '')) ?>"
                            <?php if (is_array($dependency)): ?>
                                data-depends-on="<?= $escape((string)$dependency['key']) ?>"
                                data-depends-values="<?= $escape(implode(' ', $dependency['values'])) ?>"
                            <?php endif; ?>
                        >
                            <div class="config-setting-copy">
                                <?php if ($editable): ?>
                                    <label for="<?= $escape((string)$config['control_id']) ?>"><?= $escape($name) ?></label>
                                <?php else: ?>
                                    <h3><?= $escape($name) ?></h3>
                                <?php endif; ?>
                                <p id="<?= $escape((string)($config['help_id'] ?? '')) ?>"><?= $escape($help) ?></p>
                            </div>
                            <div class="config-setting-control">
                                <?= $editable
                                    ? (string)$row['cells']['value']['content']
                                    : $escape((string)$row['cells']['value']['content']) ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endforeach; ?>
    </div>
</section>

<dialog class="ai-key-help-dialog" id="ai-key-help-dialog" aria-labelledby="ai-key-help-title">
    <div class="ai-key-help-surface">
        <header class="ai-key-help-header">
            <div>
                <p class="ai-key-help-eyebrow"><?= $escape($trans('AI key help eyebrow')) ?></p>
                <h2 id="ai-key-help-title"><?= $escape($trans('AI key help title')) ?></h2>
                <p class="ai-key-help-intro"><?= $escape($trans('AI key help intro')) ?></p>
            </div>
            <button
                type="button"
                class="ai-key-help-close"
                data-ai-key-help-close
                aria-label="<?= $escape($trans('Close')) ?>">&times;</button>
        </header>

        <div class="ai-key-help-provider-tabs" role="tablist" aria-label="<?= $escape($trans('AI provider')) ?>">
            <button
                type="button"
                role="tab"
                id="ai-key-help-tab-gemini"
                data-ai-key-help-tab="gemini"
                aria-controls="ai-key-help-panel-gemini"
                aria-selected="true">
                <span class="ai-key-help-provider-mark" aria-hidden="true">G</span>
                <span><strong>Gemini</strong><small><?= $escape($trans('Free tier')) ?></small></span>
            </button>
            <button
                type="button"
                role="tab"
                id="ai-key-help-tab-groq"
                data-ai-key-help-tab="groq"
                aria-controls="ai-key-help-panel-groq"
                aria-selected="false"
                tabindex="-1">
                <span class="ai-key-help-provider-mark" aria-hidden="true">Q</span>
                <span><strong>Groq</strong><small><?= $escape($trans('Free tier')) ?></small></span>
            </button>
            <button
                type="button"
                role="tab"
                id="ai-key-help-tab-yandex"
                data-ai-key-help-tab="yandex"
                aria-controls="ai-key-help-panel-yandex"
                aria-selected="false"
                tabindex="-1">
                <span class="ai-key-help-provider-mark" aria-hidden="true">Я</span>
                <span><strong>Yandex</strong><small><?= $escape($trans('Starter grant')) ?></small></span>
            </button>
            <button
                type="button"
                role="tab"
                id="ai-key-help-tab-gigachat"
                data-ai-key-help-tab="gigachat"
                aria-controls="ai-key-help-panel-gigachat"
                aria-selected="false"
                tabindex="-1">
                <span class="ai-key-help-provider-mark" aria-hidden="true">G</span>
                <span><strong>GigaChat</strong><small><?= $escape($trans('Personal freemium')) ?></small></span>
            </button>
        </div>

        <section
            class="ai-key-help-panel"
            id="ai-key-help-panel-gemini"
            data-ai-key-help-panel="gemini"
            role="tabpanel"
            aria-labelledby="ai-key-help-tab-gemini">
            <p class="ai-key-help-lead"><?= $escape($trans('Gemini key help lead')) ?></p>
            <ol class="ai-key-help-steps">
                <li><?= $escape($trans('Gemini key step 1')) ?></li>
                <li><?= $escape($trans('Gemini key step 2')) ?></li>
                <li><?= $escape($trans('Gemini key step 3')) ?></li>
                <li><?= $escape($trans('AI key paste step')) ?></li>
            </ol>
            <p class="ai-key-help-note"><?= $escape($trans('AI key help model note')) ?></p>
            <div class="ai-key-help-links">
                <a class="ai-key-help-primary-link" href="https://aistudio.google.com/apikey" target="_blank" rel="noopener noreferrer">
                    <?= $escape($trans('Open key page')) ?> <span aria-hidden="true">↗</span>
                </a>
                <a href="https://ai.google.dev/gemini-api/docs/api-key" target="_blank" rel="noopener noreferrer">
                    <?= $escape($trans('Official instructions')) ?>
                </a>
            </div>
        </section>

        <section
            class="ai-key-help-panel"
            id="ai-key-help-panel-groq"
            data-ai-key-help-panel="groq"
            role="tabpanel"
            aria-labelledby="ai-key-help-tab-groq"
            hidden>
            <p class="ai-key-help-lead"><?= $escape($trans('Groq key help lead')) ?></p>
            <ol class="ai-key-help-steps">
                <li><?= $escape($trans('Groq key step 1')) ?></li>
                <li><?= $escape($trans('Groq key step 2')) ?></li>
                <li><?= $escape($trans('Groq key step 3')) ?></li>
                <li><?= $escape($trans('AI key paste step')) ?></li>
            </ol>
            <p class="ai-key-help-note"><?= $escape($trans('Groq key permissions note')) ?></p>
            <p class="ai-key-help-note"><?= $escape($trans('AI key help model note')) ?></p>
            <div class="ai-key-help-links">
                <a class="ai-key-help-primary-link" href="https://console.groq.com/keys" target="_blank" rel="noopener noreferrer">
                    <?= $escape($trans('Open key page')) ?> <span aria-hidden="true">↗</span>
                </a>
                <a href="https://console.groq.com/docs/quickstart" target="_blank" rel="noopener noreferrer">
                    <?= $escape($trans('Official instructions')) ?>
                </a>
            </div>
        </section>

        <section
            class="ai-key-help-panel"
            id="ai-key-help-panel-yandex"
            data-ai-key-help-panel="yandex"
            role="tabpanel"
            aria-labelledby="ai-key-help-tab-yandex"
            hidden>
            <p class="ai-key-help-lead"><?= $escape($trans('Yandex key help lead')) ?></p>
            <ol class="ai-key-help-steps">
                <li><?= $escape($trans('Yandex key step 1')) ?></li>
                <li><?= $escape($trans('Yandex key step 2')) ?></li>
                <li><?= $escape($trans('Yandex key step 3')) ?></li>
                <li><?= $escape($trans('Yandex key step 4')) ?></li>
            </ol>
            <p class="ai-key-help-note"><?= $escape($trans('Yandex billing note')) ?></p>
            <p class="ai-key-help-note"><?= $escape($trans('AI key help model note')) ?></p>
            <div class="ai-key-help-links">
                <a class="ai-key-help-primary-link" href="https://aistudio.yandex.ru/" target="_blank" rel="noopener noreferrer">
                    <?= $escape($trans('Open AI Studio')) ?> <span aria-hidden="true">↗</span>
                </a>
                <a href="https://aistudio.yandex.ru/docs/ru/ai-studio/quickstart/" target="_blank" rel="noopener noreferrer">
                    <?= $escape($trans('Official instructions')) ?>
                </a>
            </div>
        </section>

        <section
            class="ai-key-help-panel"
            id="ai-key-help-panel-gigachat"
            data-ai-key-help-panel="gigachat"
            role="tabpanel"
            aria-labelledby="ai-key-help-tab-gigachat"
            hidden>
            <p class="ai-key-help-lead"><?= $escape($trans('GigaChat key help lead')) ?></p>
            <ol class="ai-key-help-steps">
                <li><?= $escape($trans('GigaChat key step 1')) ?></li>
                <li><?= $escape($trans('GigaChat key step 2')) ?></li>
                <li><?= $escape($trans('GigaChat key step 3')) ?></li>
                <li><?= $escape($trans('GigaChat key step 4')) ?></li>
            </ol>
            <p class="ai-key-help-note"><?= $escape($trans('GigaChat certificate note')) ?></p>
            <p class="ai-key-help-note"><?= $escape($trans('AI key help model note')) ?></p>
            <div class="ai-key-help-links">
                <a class="ai-key-help-primary-link" href="https://developers.sber.ru/studio/" target="_blank" rel="noopener noreferrer">
                    <?= $escape($trans('Open Sber Studio')) ?> <span aria-hidden="true">↗</span>
                </a>
                <a href="https://developers.sber.ru/docs/ru/gigachat/individuals-quickstart" target="_blank" rel="noopener noreferrer">
                    <?= $escape($trans('Official instructions')) ?>
                </a>
                <a href="https://developers.sber.ru/docs/ru/gigachat/certificates" target="_blank" rel="noopener noreferrer">
                    <?= $escape($trans('Certificate instructions')) ?>
                </a>
            </div>
        </section>

        <footer class="ai-key-help-footer">
            <p><span aria-hidden="true">⌾</span> <?= $escape($trans('AI key help security note')) ?></p>
            <button type="button" class="secondary" data-ai-key-help-paste><?= $escape($trans('Paste API key')) ?></button>
        </footer>
    </div>
</dialog>
<script src="<?= $basePath ?>/_admin/js/config-ai-help.js"></script>
<script src="js/ajax.js"></script>
