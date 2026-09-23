import {chromium, firefox} from 'playwright';
import assert from 'node:assert/strict';
import {createFixtureServer} from './server.mjs';
import {runRecoveryRegressions} from './recovery-tests.mjs';

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
        } finally {
            await browser.close();
        }
    }
} finally {
    server.closeAllConnections();
    await new Promise(resolve => server.close(resolve));
}
