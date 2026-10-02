import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
import {chromium, firefox, webkit} from 'playwright';
import {createFixtureServer} from './server.mjs';

const image = '<img class="post-media-image" width="360" height="180" alt="Fixture image" '
    + 'src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22360%22 height=%22180%22%3E'
    + '%3Crect width=%22360%22 height=%22180%22 fill=%22gray%22/%3E%3C/svg%3E">';

async function settle(page) {
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
}

async function runImageEdgeRegressions(browser, page) {
    for (const edge of ['wrapper', 'image', 'link', 'picture', 'whitespace', 'after-whitespace',
        'pointer', 'pointer-middle', 'pointer-bottom']) {
        for (const firstAction of ['Enter', 'type']) {
            await page.evaluate(({image, edge}) => {
                const visual = edge === 'link' ? '<a href="https://example.com/">' + image + '</a>'
                    : edge === 'picture' ? '<picture>' + image + '</picture>'
                    : edge === 'whitespace' ? '\n' + image : edge === 'after-whitespace' ? image + '\n' : image;
                const state = window.setupEditorWorkflow('<p>Original prose.</p>'
                    + '<div class="post-picture post-media-picture">' + visual
                    + '<div class="post-caption">Original caption</div></div>');
                window.editorTest.prepareEditableMedia(state.body);
                state.history?.destroy();
                state.history = window.editorTest.createBodyHistory(state);
                window.mediaSpacingState = state;
                window.originalMedia = state.body.querySelector('.post-media-picture');
                const range = document.createRange();
                const target = edge === 'wrapper' || edge.startsWith('pointer') ? window.originalMedia
                    : edge === 'after-whitespace' ? window.originalMedia.childNodes[1] : window.originalMedia.firstChild;
                range.setStart(target, 0);
                range.collapse(true);
                state.body.focus();
                getSelection().removeAllRanges();
                getSelection().addRange(range);
            }, {image, edge});
            if (edge.startsWith('pointer')) {
                const bounds = await page.locator('.post-media-picture img').boundingBox();
                // Click the image's actual left edge, not the empty paragraph or
                // side gutter (which intentionally leads to the trailing line).
                const y = edge === 'pointer-middle' ? bounds.y + bounds.height / 2
                    : edge === 'pointer-bottom' ? bounds.y + bounds.height - 0.25 : bounds.y + 0.25;
                await page.mouse.click(bounds.x + 0.25, y);
                // Firefox selects the image object on some points of its edge.
                // The reported workflow uses a caret before it, not an explicit
                // selection to replace. Collapse that object selection using an
                // actual arrow key before testing Enter/typing at the boundary.
                if (await page.evaluate(() => !getSelection().getRangeAt(0).collapsed)) {
                    assert.equal(await page.evaluate(() => {
                        const selected = getSelection().getRangeAt(0).cloneContents();
                        return selected.childNodes.length === 1 && selected.firstChild?.nodeName === 'IMG';
                    }), true, `${edge}: the pointer selected only the image object`);
                    await page.keyboard.press('ArrowLeft');
                }
            }
            await settle(page);
            assert.equal(await page.evaluate(() => {
                const range = getSelection().getRangeAt(0);
                return range.collapsed && window.mediaSpacingState.body.contains(range.startContainer);
            }), true, `${edge}: keyboard input starts at an actual caret inside the editor`);
            const before = await geometry(page);
            const startsAfterImage = await page.evaluate(() => {
                const range = getSelection().getRangeAt(0);
                const prefix = range.cloneRange();
                prefix.setStart(window.mediaSpacingState.body, 0);
                return Boolean(prefix.cloneContents().querySelector('img'))
                    && range.startContainer.tagName !== 'IMG';
            });
            const context = `${browser.browserType().name()}: ${edge} edge, ${firstAction}`;
            await page.evaluate(context => { window.mediaSpacingCase = context; }, context);
            assert.ok(before.captionGap >= 0 && before.captionGap < 12, `${context}: caption has only its normal small gap`);
            if (firstAction === 'Enter') {
                await page.keyboard.press('Enter');
                await page.keyboard.press('Enter');
            }
            await page.keyboard.type('Text at image edge.');
            await settle(page);
            const after = await geometry(page);
            assert.equal(after.paragraphs.at(-1).text, 'Text at image edge.', `${context}: typing belongs to a body paragraph`);
            assert.equal(after.mediaCount, 1, `${context}: native editing must not clone the picture container`);
            assert.equal(after.caption, 'Original caption', `${context}: caption text survives`);
            assert.ok(Math.abs(after.captionGap - before.captionGap) < 1, `${context}: caption must not move away on body input`);
            assert.equal(await page.evaluate(startsAfterImage => {
                const state = window.mediaSpacingState;
                const media = state.body.querySelector('.post-media-picture');
                const typed = Array.from(state.body.querySelectorAll(':scope > p'))
                    .find(paragraph => paragraph.textContent === 'Text at image edge.');
                return media === window.originalMedia
                    && Boolean(media.compareDocumentPosition(typed) & (startsAfterImage
                        ? Node.DOCUMENT_POSITION_FOLLOWING : Node.DOCUMENT_POSITION_PRECEDING))
                    && media.querySelector('.post-caption')?.parentElement === media;
            }, startsAfterImage), true, `${context}: original image and caption stay together, beside the typed paragraph`);
            assert.ok(after.saved.includes('Original prose.') && after.saved.includes('Original caption')
                && after.saved.includes('Text at image edge.'), `${context}: saving must retain every text block`);
        }
    }
    console.log('media spacing: clicks and every leading DOM edge accept real typing/Enter outside the intact image and caption');
}

async function runLinkedImageCaptionRegression(page) {
    await page.evaluate(image => {
        const state = window.setupEditorWorkflow('<p>Original prose.</p>'
            + '<div class="post-picture post-media-picture">' + image
            + '<div class="post-caption">Original caption</div></div>');
        window.editorTest.prepareEditableMedia(state.body);
        window.mediaSpacingState = state;
    }, image);
    await settle(page);
    const before = await geometry(page);
    await page.locator('.post-media-picture img').click({button: 'right'});
    await page.locator('.post-editor-image-panel [data-context-action="open-link"]').click();
    const input = page.locator('.post-editor-link-panel [data-context-link-input]');
    await input.fill('https://example.com/image');
    await input.press('Enter');
    await settle(page);
    const linked = await geometry(page);
    assert.equal(await page.locator('.post-media-picture > a').getAttribute('href'), 'https://example.com/image');
    assert.ok(Math.abs(linked.captionGap - before.captionGap) < 1,
        'Adding an image link through the product menu must not add an image margin above the caption');
    assert.equal(linked.caption, 'Original caption');
}

async function runParagraphSpacingRegression(page) {
    const spacing = await page.evaluate(() => {
        const state = window.setupEditorWorkflow('<p>Text ending in a line break.<br></p><p>Another paragraph.</p>');
        window.editorTest.prepareEditableMedia(state.body);
        return Array.from(state.body.children, paragraph => ({
            margin: parseFloat(getComputedStyle(paragraph).marginBottom),
            empty: paragraph.classList.contains('post-editor-empty-paragraph'),
        }));
    });
    assert.equal(spacing[0].empty, false, 'A paragraph containing text and a BR is not an empty line');
    assert.ok(spacing[0].margin > 0, 'Nonempty paragraphs retain their reading gap');
    assert.equal(spacing[0].margin, spacing[1].margin, 'A trailing BR must not flatten paragraph spacing');
}

function geometry(page) {
    return page.evaluate(() => {
        const state = window.mediaSpacingState;
        const media = state.body.querySelector('.post-media-picture');
        const image = media.querySelector('img');
        const caption = media.querySelector('.post-caption');
        if (!image || !caption) throw new Error(`${window.mediaSpacingCase || 'spacing'}: broken media: ${state.body.innerHTML}`);
        return {
            imageTop: image.getBoundingClientRect().top + scrollY,
            captionGap: caption.getBoundingClientRect().top - image.getBoundingClientRect().bottom,
            lineHeight: parseFloat(getComputedStyle(state.body).lineHeight),
            paragraphs: Array.from(state.body.querySelectorAll(':scope > p'), paragraph => ({
                text: paragraph.textContent,
                height: paragraph.getBoundingClientRect().height,
                marginBottom: parseFloat(getComputedStyle(paragraph).marginBottom),
            })),
            mediaCount: state.body.querySelectorAll('.post-media-picture').length,
            caption: caption.textContent,
            saved: window.editorTest.editableBodyHtml(state),
        };
    });
}

export async function runMediaSpacingRegressions(browser, origin) {
    const page = await browser.newPage({viewport: {width: 1280, height: 1000}});
    const errors = [];
    page.on('pageerror', error => errors.push(String(error)));
    try {
        await page.goto(origin + '/');
        await page.waitForFunction(() => window.setupEditorWorkflow && window.editorTest);
        await runParagraphSpacingRegression(page);
        for (const preceding of ['', '<p>First paragraph.</p><p>Second paragraph.</p>']) {
            for (const caption of ['', 'Saved caption']) {
                await page.evaluate(({preceding, caption, image}) => {
                    const state = window.setupEditorWorkflow(preceding
                        + '<div class="post-picture post-media-picture">' + image
                        + '<div class="post-caption">' + caption + '</div></div>');
                    window.editorTest.prepareEditableMedia(state.body);
                    state.history?.destroy();
                    state.history = window.editorTest.createBodyHistory(state);
                    window.mediaSpacingState = state;
                    const range = document.createRange();
                    range.setStartBefore(state.body.querySelector('.post-media-picture'));
                    range.collapse(true);
                    state.body.focus();
                    getSelection().removeAllRanges();
                    getSelection().addRange(range);
                }, {preceding, caption, image});
                await settle(page);
                const initial = await geometry(page);
                let previous;
                for (let count = 1; count <= 4; ++count) {
                    await page.keyboard.press('Enter');
                    await settle(page);
                    const current = await geometry(page);
                    const context = `${browser.browserType().name()}: ${preceding ? 'middle' : 'leading'} image, Enter ${count}`;
                    assert.equal(current.paragraphs.length, initial.paragraphs.length + count, context);
                    assert.equal(current.mediaCount, 1, context);
                    assert.equal(current.caption, caption, context);
                    assert.ok(Math.abs(current.captionGap - initial.captionGap) < 1, `${context}: caption must stay attached`);
                    if (previous) {
                        const movement = current.imageTop - previous.imageTop;
                        assert.ok(Math.abs(movement - current.lineHeight) < 1,
                            `${context}: one Enter must move one line (${current.lineHeight}px), not ${movement}px`);
                    }
                    const emptyLines = current.paragraphs.slice(initial.paragraphs.length);
                    assert.ok(emptyLines.every(line => line.text === '' && line.marginBottom === 0),
                        `${context}: empty lines must not add paragraph margins; ${JSON.stringify(emptyLines)}`);
                    previous = current;
                }

                // Native keyboard input, not DOM mutation or simulated InputEvents.
                await page.keyboard.type('Typed before image.');
                await settle(page);
                const typed = await geometry(page);
                assert.equal(typed.paragraphs.at(-1).text, 'Typed before image.');
                assert.equal(typed.caption, caption);
                assert.ok(Math.abs(typed.captionGap - initial.captionGap) < 1, 'Body typing must not detach the caption');
                assert.equal(typed.mediaCount, 1);
                assert.equal(/post-editor-(body|empty)-paragraph/.test(typed.saved), false);

                const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                await page.keyboard.press(`${modifier}+z`);
                await settle(page);
                const undone = await geometry(page);
                assert.equal(undone.saved, previous.saved, 'Undo typing retains all four explicit empty lines and the image');
                await page.keyboard.press(`${modifier}+Shift+z`);
                await settle(page);
                assert.equal((await geometry(page)).saved, typed.saved, 'Redo restores the exact typed body and caption');

                const saved = typed.saved;
                await page.evaluate(saved => {
                    const state = window.setupEditorWorkflow(saved);
                    window.editorTest.prepareEditableMedia(state.body);
                    window.mediaSpacingState = state;
                }, saved);
                await settle(page);
                const reopened = await geometry(page);
                assert.equal(reopened.paragraphs.length, typed.paragraphs.length, 'Reopening must keep all paragraph boundaries');
                assert.equal(reopened.paragraphs.at(-1).text, 'Typed before image.');
                assert.ok(Math.abs(reopened.captionGap - initial.captionGap) < 1, 'Reopening must keep the caption attached');
                assert.ok(reopened.paragraphs.slice(initial.paragraphs.length, -1).every(line => line.marginBottom === 0),
                    'Stored empty lines must remain single lines after editor-only classes are removed');
            }
        }
        await runImageEdgeRegressions(browser, page);
        await runLinkedImageCaptionRegression(page);
        assert.deepEqual(errors, []);
        console.log('media spacing: repeated real Enter reserves single lines before images; typing, undo and reopening retain image/caption structure');
    } finally {
        await page.close();
    }
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
        for (const engine of [chromium, firefox, webkit]) {
            const browser = await engine.launch();
            try {
                await runMediaSpacingRegressions(browser, `http://127.0.0.1:${server.address().port}`);
            } finally {
                await browser.close();
            }
        }
    } finally {
        await new Promise(resolve => server.close(resolve));
    }
}
