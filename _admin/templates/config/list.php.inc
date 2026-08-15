<?php

/** @var string $basePath */
/** @var callable $trans */

?>
<link rel="stylesheet" href="<?= $basePath ?>/_admin/css/config-ai-help.css">
<script src="<?= $basePath ?>/_admin/js/config-secret.js"></script>
<?php

require __DIR__ . '/../../../_vendor/s2/admin-yard/templates/list.php.inc';

$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

?>
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

        <footer class="ai-key-help-footer">
            <p><span aria-hidden="true">⌾</span> <?= $escape($trans('AI key help security note')) ?></p>
            <button type="button" class="secondary" data-ai-key-help-paste><?= $escape($trans('Paste API key')) ?></button>
        </footer>
    </div>
</dialog>
<script src="<?= $basePath ?>/_admin/js/config-ai-help.js"></script>
<script src="js/ajax.js"></script>
