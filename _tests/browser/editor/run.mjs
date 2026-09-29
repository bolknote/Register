import {chromium, firefox} from 'playwright';
import assert from 'node:assert/strict';
import {createFixtureServer} from './server.mjs';
import {runRecoveryRegressions} from './recovery-tests.mjs';
import {runSaveRegressions, runAdminDirtyFieldRegressions} from './save-tests.mjs';
import {runReviewRegressions} from './review-tests.mjs';
import {runRecoveryPreviewRegressions} from './recovery-preview-tests.mjs';
import {runAsyncPreviewRegressions} from './async-preview-tests.mjs';
import {runLifecycleRegressions} from './lifecycle-tests.mjs';
import {runConcurrencyRegressions} from './concurrency-tests.mjs';
import {runAdminSelectionRegressions} from './admin-selection-tests.mjs';
import {runAdminInputRegressions} from './admin-input-tests.mjs';
import {runAdminHtmlRegressions} from './admin-html-tests.mjs';
import {runCaptionInputRegressions} from './caption-input-tests.mjs';
import {runCaptionBoundaryRegressions} from './caption-boundary-tests.mjs';
import {runFieldHistoryRegressions} from './field-history-tests.mjs';
import {runMediaAiRegressions, runTagPasteRegressions} from './media-ai-tag-tests.mjs';
import {runClipboardUploadRegressions} from './clipboard-upload-tests.mjs';
import {runAdminSaveFailureRegressions, runSocialPreviewRegressions} from './admin-save-social-tests.mjs';
import {runMediaInsertionAltRegressions, runMediaInsertionCaretRegressions, runMixedMediaCaretRegressions, runRejectedMediaRegressions} from './media-insertion-tests.mjs';
import {runMediaDragRegressions} from './media-drag-tests.mjs';
import {runAdminAiTargetRegressions} from './admin-ai-target-tests.mjs';
import {runImageInsertionTextRegressions, runSmartParagraphRegressions, runSocialPreviewTextRegressions} from './admin-text-tests.mjs';
import {runAltLayoutRegressions, runTemplateFieldRegressions, runPreviewDocumentRegressions, runPreviewLineRegressions} from './admin-layout-preview-tests.mjs';
import {runAdminShortcutTargetRegressions, runAltCursorRegressions} from './admin-interaction-tests.mjs';
import {runAdminNativeShortcutRegressions, runSmartParagraphMarkupRegressions, runSmartParagraphTagRegressions, runSmartParagraphBreakRegressions, runSmartParagraphNoopRegressions, runParagraphAttributeRegressions,
    runCommentParagraphRegressions, runDuplicateLineRegressions, runParagraphCaretRegressions} from './admin-formatting-tests.mjs';
import {runAdminMediaPathRegressions, runAdminAudioInsertionRegressions, runAdminShortcutModifierRegressions} from './admin-media-shortcut-tests.mjs';
import {runAdminCompositionRegressions, runAdminNativeCompositionRegressions, runAdminTagNavigationRegressions} from './admin-composition-tests.mjs';
import {runMediaLibraryUploadRegressions, runMediaLibraryFailureRegressions, runMediaLibrarySortRegressions,
    runMediaLibraryFolderRegressions, runMediaLibraryFolderMutationRegressions, runMediaLibraryFolderFailureRegressions,
    runMediaLibraryFileSelectionRegressions, runMediaLibraryFileRenameRegressions, runMediaLibraryFileMoveRegressions,
    runMediaLibraryFileDeleteRegressions, runMediaLibraryFolderCreateRegressions,
    runMediaLibraryFilteredSelectionRegressions, runMediaLibraryFilteredKeyboardRegressions, runMediaLibraryHiddenFocusRegressions,
    runMediaLibraryFolderRollbackRegressions, runMediaLibraryLiteralNameRegressions} from './media-library-tests.mjs';

async function runAuthorWorkflowRegressions(browser, origin) {
    const page = await browser.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.goto(origin + '/');
        await page.waitForFunction(() => (
            typeof window.setupEditorWorkflow === 'function' && window.editorTest
        ));

        const pastedText = [
            'Первый абзац статьи.',
            '',
            'Второй абзац статьи.',
            '',
            'Третий абзац статьи.',
        ].join('\n');
        await page.evaluate((text) => {
            const state = window.setupEditorWorkflow('<p><br></p>');
            const range = document.createRange();
            range.selectNodeContents(state.body);
            state.body.focus();
            getSelection().removeAllRanges();
            getSelection().addRange(range);
            const clipboardData = new DataTransfer();
            clipboardData.setData('text/plain', text);
            const event = new Event('paste', {bubbles: true, cancelable: true});
            Object.defineProperty(event, 'clipboardData', {value: clipboardData});
            state.body.dispatchEvent(event);
            window.authorWorkflowState = state;
        }, pastedText);

        assert.deepEqual(await page.evaluate(() => ({
            tags: Array.from(window.authorWorkflowState.body.children, node => node.tagName),
            text: Array.from(window.authorWorkflowState.body.children, node => node.textContent),
            doubledBreaks: window.authorWorkflowState.body.querySelectorAll('br + br').length,
            saved: window.editorTest.editableBodyHtml(window.authorWorkflowState),
        })), {
            tags: ['P', 'P', 'P'],
            text: ['Первый абзац статьи.', 'Второй абзац статьи.', 'Третий абзац статьи.'],
            doubledBreaks: 0,
            saved: '<p>Первый абзац статьи.</p><p>Второй абзац статьи.</p><p>Третий абзац статьи.</p>',
        });

        await page.evaluate(() => {
            const paragraph = window.authorWorkflowState.body.firstElementChild;
            const range = document.createRange();
            range.selectNodeContents(paragraph);
            range.collapse(false);
            window.authorWorkflowState.body.focus();
            getSelection().removeAllRanges();
            getSelection().addRange(range);
        });
        await page.keyboard.press('Enter');
        await page.keyboard.insertText('Добавлено после одного Enter.');
        assert.deepEqual(await page.evaluate(() => ({
            tags: Array.from(window.authorWorkflowState.body.children, node => node.tagName),
            text: Array.from(window.authorWorkflowState.body.children, node => node.textContent),
            empty: Array.from(window.authorWorkflowState.body.children)
                .filter(node => node.tagName === 'P' && node.textContent.trim() === '').length,
            doubledBreaks: window.authorWorkflowState.body.querySelectorAll('br + br').length,
        })), {
            tags: ['P', 'P', 'P', 'P'],
            text: [
                'Первый абзац статьи.',
                'Добавлено после одного Enter.',
                'Второй абзац статьи.',
                'Третий абзац статьи.',
            ],
            empty: 0,
            doubledBreaks: 0,
        });
        console.log('editor workflow: a whole pasted article uses semantic paragraphs and one real Enter adds one line');

        await page.evaluate(() => {
            const state = window.setupEditorWorkflow(
                '<p>Текст до картинки.</p>'
                + '<div class="post-picture post-media-picture">'
                + '<img alt="fixture"><div class="post-caption"></div></div>',
            );
            window.editorTest.prepareEditableMedia(state.body);
            state.history?.destroy();
            state.history = window.editorTest.createBodyHistory?.(state);
            window.authorWorkflowState = state;
        });
        const caption = page.locator('.post-caption');
        await caption.click();
        await page.keyboard.press('Enter');
        const afterEnter = await page.evaluate(() => {
            const state = window.authorWorkflowState;
            const media = state.body.querySelector('.post-media-picture');
            const paragraph = media?.nextElementSibling;
            const selection = getSelection();
            return {
                paragraphTag: paragraph?.tagName || '',
                paragraphIsTopLevel: paragraph?.parentElement === state.body,
                selectionInParagraph: Boolean(selection?.anchorNode && paragraph?.contains(selection.anchorNode)),
                activeIsBody: document.activeElement === state.body,
                captionsEditing: state.mediaCaptionEditors.size,
            };
        });
        assert.deepEqual(afterEnter, {
            paragraphTag: 'P',
            paragraphIsTopLevel: true,
            selectionInParagraph: true,
            activeIsBody: true,
            captionsEditing: 0,
        });
        const textAfterImage = 'Обычный абзац после картинки.';
        await page.keyboard.insertText(textAfterImage);
        assert.deepEqual(await page.evaluate((expected) => {
            const state = window.authorWorkflowState;
            const media = state.body.querySelector('.post-media-picture');
            const paragraph = media?.nextElementSibling;
            return {
                paragraph: paragraph?.textContent || '',
                caption: media?.querySelector('.post-caption')?.textContent || '',
                savedEndsWithParagraph: window.editorTest.editableBodyHtml(state)
                    .endsWith(`<p>${expected}</p>`),
            };
        }, textAfterImage), {
            paragraph: textAfterImage,
            caption: '',
            savedEndsWithParagraph: true,
        });
        console.log('editor workflow: one real Enter leaves the last image caption and preserves following body text');

        await page.evaluate(() => {
            const state = window.setupEditorWorkflow(
                '<div class="post-picture post-media-picture">'
                + '<img alt="fixture"><div class="post-caption"></div></div>',
            );
            window.editorTest.prepareEditableMedia(state.body);
            const range = document.createRange();
            range.setStart(state.body, 0);
            range.collapse(true);
            state.body.focus();
            getSelection().removeAllRanges();
            getSelection().addRange(range);
            window.authorWorkflowState = state;
        });
        await page.keyboard.press('ArrowDown');
        assert.deepEqual(await page.evaluate(() => {
            const state = window.authorWorkflowState;
            const media = state.body.querySelector('.post-media-picture');
            const paragraph = media?.nextElementSibling;
            const selection = getSelection();
            return {
                paragraphTag: paragraph?.tagName || '',
                selectionInParagraph: Boolean(selection?.anchorNode && paragraph?.contains(selection.anchorNode)),
                visibleCaret: paragraph?.classList.contains('has-leading-boundary-caret') || false,
                captionIsEditing: state.mediaCaptionEditors.size > 0,
            };
        }), {
            paragraphTag: 'P',
            selectionInParagraph: true,
            visibleCaret: true,
            captionIsEditing: false,
        });
        await page.keyboard.insertText('Текст после стрелки вниз.');
        assert.equal(await page.evaluate(() => (
            window.authorWorkflowState.body.lastElementChild?.textContent
        )), 'Текст после стрелки вниз.');
        console.log('editor workflow: ArrowDown after an image creates a visible body caret and accepts text');

        await page.evaluate(() => {
            const state = window.setupEditorWorkflow(
                '<div class="post-picture post-media-picture">'
                + '<img alt="fixture"><div class="post-caption"></div></div>'
                + '<p class="post-editor-body-paragraph"><br></p>',
            );
            const paragraph = state.body.lastElementChild;
            const range = document.createRange();
            range.selectNodeContents(paragraph);
            range.collapse(true);
            state.body.focus();
            getSelection().removeAllRanges();
            getSelection().addRange(range);
            window.authorWorkflowState = state;
        });
        await page.keyboard.press('ArrowDown');
        assert.deepEqual(await page.evaluate(() => {
            const state = window.authorWorkflowState;
            const paragraph = state.body.lastElementChild;
            const selection = getSelection();
            return {
                selectionInParagraph: Boolean(selection?.anchorNode && paragraph.contains(selection.anchorNode)),
                visibleCaret: paragraph.classList.contains('has-leading-boundary-caret'),
                activeIsBody: document.activeElement === state.body,
            };
        }), {
            selectionInParagraph: true,
            visibleCaret: true,
            activeIsBody: true,
        });
        await page.keyboard.insertText('Курсор не пропал.');
        assert.equal(await page.evaluate(() => (
            window.authorWorkflowState.body.lastElementChild?.textContent
        )), 'Курсор не пропал.');
        console.log('editor workflow: ArrowDown on the final empty line keeps its caret visible');

        await page.evaluate(() => {
            const state = window.setupEditorWorkflow(
                '<div class="post-picture post-media-picture">'
                + '<img width="320" height="180" alt="fixture" '
                + 'src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==">'
                + '<div class="post-caption"></div></div>'
                + '<p class="post-editor-body-paragraph"><br></p>',
            );
            window.editorTest.prepareEditableMedia(state.body);
            window.authorWorkflowState = state;
        });
        const clickPoint = await page.evaluate(() => {
            const body = window.authorWorkflowState.body.getBoundingClientRect();
            const media = window.authorWorkflowState.body
                .querySelector('.post-media-picture').getBoundingClientRect();
            return {
                x: Math.max(media.right + 8, body.right - 12),
                y: media.top + Math.min(24, media.height / 2),
            };
        });
        await page.mouse.click(clickPoint.x, clickPoint.y);
        assert.deepEqual(await page.evaluate(() => {
            const state = window.authorWorkflowState;
            const media = state.body.querySelector('.post-media-picture');
            const paragraph = media?.nextElementSibling;
            const selection = getSelection();
            return {
                selectionInParagraph: Boolean(selection?.anchorNode && paragraph?.contains(selection.anchorNode)),
                visibleCaret: paragraph?.classList.contains('has-leading-boundary-caret') || false,
                activeIsBody: document.activeElement === state.body,
            };
        }), {
            selectionInParagraph: true,
            visibleCaret: true,
            activeIsBody: true,
        });
        await page.keyboard.insertText('Текст после клика рядом с картинкой.');
        assert.equal(await page.evaluate(() => (
            window.authorWorkflowState.body.lastElementChild?.textContent
        )), 'Текст после клика рядом с картинкой.');
        console.log('editor workflow: clicking beside a block image moves the caret below it');

        const lastParagraphText = 'Последний абзац.';
        await page.evaluate(() => {
            const state = window.setupEditorWorkflow(
                '<div class="post-picture post-media-picture">'
                + '<img alt="fixture"><div class="post-caption"></div></div>'
                + '<p class="post-editor-body-paragraph">Последний абзац.</p>',
            );
            const paragraph = state.body.lastElementChild;
            const range = document.createRange();
            range.selectNodeContents(paragraph);
            range.collapse(false);
            state.body.focus();
            getSelection().removeAllRanges();
            getSelection().addRange(range);
            window.authorWorkflowState = state;
        });
        await page.keyboard.press('Enter');
        assert.deepEqual(await page.evaluate(() => {
            const state = window.authorWorkflowState;
            const selection = getSelection();
            const paragraph = state.body.lastElementChild;
            return {
                text: Array.from(state.body.querySelectorAll(':scope > p'), node => node.textContent),
                selectionInLastParagraph: Boolean(
                    selection?.anchorNode && paragraph.contains(selection.anchorNode),
                ),
                paragraphCaret: paragraph.classList.contains('has-leading-boundary-caret'),
                syntheticCaret: state.body.classList.contains('uses-synthetic-boundary-caret'),
                caretContent: getComputedStyle(paragraph, '::before').content,
            };
        }), {
            text: [lastParagraphText, ''],
            selectionInLastParagraph: true,
            paragraphCaret: true,
            syntheticCaret: true,
            caretContent: '""',
        });
        await page.keyboard.insertText('Текст в новой строке.');
        assert.deepEqual(await page.evaluate(() => {
            const state = window.authorWorkflowState;
            return {
                text: state.body.lastElementChild?.textContent || '',
                paragraphCaret: state.body.lastElementChild?.classList
                    .contains('has-leading-boundary-caret'),
                syntheticCaret: state.body.classList.contains('uses-synthetic-boundary-caret'),
            };
        }), {
            text: 'Текст в новой строке.',
            paragraphCaret: false,
            syntheticCaret: false,
        });
        console.log('editor workflow: Enter after the final paragraph keeps a visible caret on the new line');

        assert.deepEqual(await page.evaluate(() => {
            const state = window.setupEditorWorkflow(
                '<div class="post-picture post-media-picture">'
                + '<img alt="fixture"><div class="post-caption"></div></div>'
                + '<p>Выделенный абзац после картинки.</p>',
            );
            const paragraph = state.body.lastElementChild;
            const range = document.createRange();
            range.selectNodeContents(paragraph);
            state.body.focus();
            getSelection().removeAllRanges();
            getSelection().addRange(range);
            const image = state.body.querySelector('img');
            image.dispatchEvent(new MouseEvent('contextmenu', {
                bubbles: true,
                cancelable: true,
                clientX: 20,
                clientY: 20,
            }));
            return {
                selected: state.contextMenu?.selected,
                targetImage: state.contextMenu?.targetImage !== null,
                mainHidden: state.contextMenu?.main.hidden,
                imageHidden: state.contextMenu?.imagePanel.hidden,
            };
        }), {
            selected: true,
            targetImage: false,
            mainHidden: false,
            imageHidden: true,
        });
        console.log('editor workflow: a text selection below an image opens paragraph tools, not image tools');

        await page.evaluate(() => {
            const state = window.setupEditorWorkflow(
                '<div class="post-picture post-media-picture">'
                + '<img alt="fixture"><div class="post-caption"></div></div>',
            );
            window.editorTest.prepareEditableMedia(state.body);
            const media = state.body.firstElementChild;
            const caption = media.querySelector('.post-caption');
            const paragraph = document.createElement('p');
            paragraph.innerHTML = '<span style="color: inherit; font-size: 1em; text-wrap-mode: initial;">'
                + 'Набранный вручную текст.</span>';
            media.insertBefore(paragraph, caption);
            const text = paragraph.querySelector('span').firstChild;
            const range = document.createRange();
            range.setStart(text, 9);
            range.collapse(true);
            state.body.focus();
            getSelection().removeAllRanges();
            getSelection().addRange(range);
            window.authorWorkflowState = state;
        });
        await page.keyboard.insertText('X');
        assert.deepEqual(await page.evaluate(() => {
            const state = window.authorWorkflowState;
            const media = state.body.querySelector('.post-media-picture');
            const paragraph = media.nextElementSibling;
            return {
                mediaText: media.textContent,
                paragraphTag: paragraph?.tagName || '',
                paragraphText: paragraph?.textContent || '',
                paragraphIsTopLevel: paragraph?.parentElement === state.body,
                selectionInParagraph: Boolean(
                    getSelection()?.anchorNode && paragraph?.contains(getSelection().anchorNode),
                ),
                saved: window.editorTest.editableBodyHtml(state),
            };
        }), {
            mediaText: '',
            paragraphTag: 'P',
            paragraphText: 'НабранныйX вручную текст.',
            paragraphIsTopLevel: true,
            selectionInParagraph: true,
            saved: '<div class="post-picture post-media-picture"><img alt="fixture"></div>'
                + '<p>НабранныйX вручную текст.</p>',
        });
        console.log('editor workflow: manually typed prose cannot remain inside an image or disappear on save');
        assert.deepEqual(errors, []);
    } finally {
        await page.close();
    }
}

const server = createFixtureServer();
await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
try {
    for (const engine of [chromium, firefox]) {
        const browser = await engine.launch();
        try {
            for (const fixture of ['/', '/comment.html', '/live.html']) {
                const page = await browser.newPage();
                const errors = [];
                page.on('pageerror', error => errors.push(String(error)));
                await page.goto(`http://127.0.0.1:${server.address().port}${fixture}`);
                await page.getByRole('button', {name: 'Run regressions', exact: true}).click();
                await page.locator('#results[data-finished="true"]').waitFor({timeout: 30000});
                const results = await page.locator('#results').textContent();
                console.log(`${engine.name()} ${fixture}\n${results}`);
                if (await page.locator('#results').getAttribute('data-failed') !== '0' || errors.length > 0) {
                    throw new Error(`${engine.name()} ${fixture} regressions failed.\n${errors.join('\n')}`);
                }
                await page.close();
            }
            await runAuthorWorkflowRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runRecoveryRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runSaveRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runReviewRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runRecoveryPreviewRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAsyncPreviewRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runLifecycleRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runConcurrencyRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminSelectionRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminInputRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminHtmlRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runCaptionInputRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runCaptionBoundaryRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runFieldHistoryRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaAiRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runTagPasteRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runClipboardUploadRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminSaveFailureRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runSocialPreviewRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaInsertionAltRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaInsertionCaretRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runRejectedMediaRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMixedMediaCaretRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaDragRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminAiTargetRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runImageInsertionTextRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runSmartParagraphRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runSocialPreviewTextRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAltLayoutRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runTemplateFieldRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runPreviewDocumentRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminShortcutTargetRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminNativeShortcutRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminMediaPathRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminAudioInsertionRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibrarySortRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryUploadRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFailureRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFolderRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFolderMutationRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFolderFailureRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryLiteralNameRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFileSelectionRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFilteredSelectionRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFilteredKeyboardRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryHiddenFocusRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFileRenameRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFileMoveRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFileDeleteRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFolderCreateRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runMediaLibraryFolderRollbackRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminDirtyFieldRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminShortcutModifierRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminCompositionRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminNativeCompositionRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAdminTagNavigationRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runSmartParagraphMarkupRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runSmartParagraphTagRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runSmartParagraphBreakRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runSmartParagraphNoopRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runParagraphAttributeRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runCommentParagraphRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runDuplicateLineRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runParagraphCaretRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runAltCursorRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            await runPreviewLineRegressions(browser, `http://127.0.0.1:${server.address().port}`);
        } finally {
            await browser.close();
        }
    }
} finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
}
