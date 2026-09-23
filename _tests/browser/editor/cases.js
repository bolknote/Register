/* Real DOM/Selection/execCommand regressions, also runnable without a test driver. */
const api = window.editorTest;
const cases = [];
const test = (name, run) => cases.push({name, run});
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
const frame = () => new Promise(resolve => requestAnimationFrame(resolve));
function equal(actual, expected, message = '') {
    if (actual !== expected) throw new Error(`${message}\nExpected: ${expected}\nActual: ${actual}`);
}
function ok(value, message) { if (!value) throw new Error(message); }

let previous;
function setup(html = '<p><br></p>') {
    if (previous) {
        previous.mediaControllers.forEach(controller => controller.abort());
        previous.imageCaptionEditor?.controller.abort();
        previous.history?.destroy();
        previous.fieldSurfaces?.destroy();
        api.editorStates.delete(previous.card);
    }
    document.getElementById('fixture').innerHTML = `<article class="post-card is-editing" data-post-id="1">
        <h2 contenteditable="true">Test title</h2>
        <div class="post body" data-post-inplace-body contenteditable="true" role="textbox" aria-label="Post text"></div>
        <form class="post-inplace-edit-form" action="/upload"><input name="inplace_token" value="fixture"></form>
        <p class="post-inplace-status" hidden></p>
    </article>`;
    const card = document.querySelector('#fixture article');
    const body = card.querySelector('.body');
    body.innerHTML = html;
    const state = {card, body, title: card.querySelector('h2'), form: card.querySelector('form'),
        originalPublishedAt: 1788696000, bodyDirty: false, dateDirty: false, contextMenu: null,
        mediaControllers: new Set(), mediaUploads: new Set(), uploadedMediaIds: new Set(),
        mediaCaptionEditors: new Map(), imageUploadTail: Promise.resolve(), imageCaptionEditor: null};
    api.editorStates.set(card, state);
    document.execCommand('defaultParagraphSeparator', false, 'p');
    select(state, body.lastElementChild?.tagName === 'P' ? body.lastElementChild : body, true);
    state.history = api.createBodyHistory?.(state);
    previous = state;
    return state;
}
function select(state, element = state.body, end = false) {
    state.body.focus();
    const range = document.createRange();
    range.selectNodeContents(element);
    if (end) range.collapse(false);
    getSelection().removeAllRanges();
    getSelection().addRange(range);
    return range;
}
async function type(state, text) {
    // execCommand really dispatches input and records native history in the browser.
    document.execCommand('insertText', false, text);
    await tick();
}
async function action(state, name, extra = {}) {
    state.contextMenu = {range: getSelection().getRangeAt(0).cloneRange(), anchor: document.createElement('div'), ...extra};
    api.handleContextAction(state, name);
    await tick();
}
async function undo(state, redo = false) {
    state.body.focus();
    if (!getSelection().rangeCount || !state.body.contains(getSelection().anchorNode)) select(state, state.body, true);
    state.body.dispatchEvent(new KeyboardEvent('keydown', {
        key: 'z', code: 'KeyZ', ctrlKey: true, shiftKey: redo, bubbles: true, cancelable: true,
    }));
    await tick();
}
const plain = state => state.body.textContent;

test('new post fields stay separated and fixed after the first character', async () => {
    const s = setup();
    s.creating = true;
    s.card.classList.add('is-creating');
    s.card.style.setProperty('--post-editor-field-padding', '8px');
    s.card.style.setProperty('--post-editor-field-surface', '#24221f');
    const foot = document.createElement('div');
    foot.className = 'post foot';
    foot.innerHTML = `<div class="post-foot-meta"><span class="post-foot-tags">
        <span class="post-tag-values"><span class="post-tags-editor">
            <span class="post-tags-surface"><input aria-label="Tags"></span>
        </span></span>
    </span></div>`;
    s.card.append(foot);
    s.tags = foot.querySelector('.post-tag-values');
    const next = document.createElement('article');
    next.className = 'post-card';
    next.textContent = 'Next post';
    s.card.after(next);
    s.fieldSurfaces = api.createEditorFieldSurfaces(s);
    await frame();

    const surface = s.card.querySelector('[data-editor-field-surface="body"]');
    const tagSurface = s.card.querySelector('[data-editor-field-surface="tags"]');
    const fieldGap = Number(tagSurface.getAttribute('y'))
        - Number(surface.getAttribute('y'))
        - Number(surface.getAttribute('height'));
    ok(fieldGap >= 3.5, `Body and tag fields need a visible gap; actual: ${fieldGap}px`);
    const before = {
        y: surface.getAttribute('y'),
        height: surface.getAttribute('height'),
        bodyTop: s.body.getBoundingClientRect().top,
        bodyHeight: s.body.getBoundingClientRect().height,
        nextTop: next.getBoundingClientRect().top,
    };
    select(s, s.body, true);
    await type(s, 'И');
    await frame();

    equal(surface.getAttribute('y'), before.y, 'The painted top edge must stay fixed');
    equal(surface.getAttribute('height'), before.height, 'The painted field must not shrink');
    equal(s.body.getBoundingClientRect().top, before.bodyTop, 'The editable text must not move');
    equal(s.body.getBoundingClientRect().height, before.bodyHeight, 'The editable body must keep its height');
    equal(next.getBoundingClientRect().top, before.nextTop, 'The following post must not move');
});

test('inline code undo/redo preserves the text and selection', async () => {
    const s = setup();
    await type(s, 'Альфа бета гамма');
    select(s);
    await action(s, 'inline-code');
    equal(s.body.querySelector('tt')?.textContent, 'Альфа бета гамма');
    await undo(s);
    equal(plain(s), 'Альфа бета гамма');
    equal(s.body.querySelector('tt'), null);
    equal(getSelection().toString(), 'Альфа бета гамма', 'Undo restores the selected text');
    await undo(s, true);
    equal(s.body.querySelector('tt')?.textContent, 'Альфа бета гамма');
});

test('typing inside an AI correction keeps the caret at the chosen offset', async () => {
    for (const offset of [0, 2, 5]) {
        const s = setup('<p>One error here.</p>');
        api.markAiChanges(Array.from(s.body.childNodes), 'One eror here.');
        const marked = s.body.querySelector('.post-editor-ai-change');
        equal(marked?.textContent, 'error', 'The corrected word is marked');

        const range = document.createRange();
        range.setStart(marked.firstChild, offset);
        range.collapse(true);
        getSelection().removeAllRanges();
        getSelection().addRange(range);

        const expected = `One ${'error'.slice(0, offset)}XY${'error'.slice(offset)} here.`;
        await type(s, 'X');
        await type(s, 'Y');
        equal(plain(s), expected, `Both characters stay at the chosen offset ${offset}`);
        equal(s.body.querySelector('.post-editor-ai-change'), null, 'The transient mark is removed');
    }
});

test('partial inline-code removal, undo and redo keep surrounding code', async () => {
    const s = setup('<p><tt>Альфа бета гамма</tt></p>');
    const text = s.body.querySelector('tt').firstChild;
    const range = document.createRange();
    range.setStart(text, 6); range.setEnd(text, 10);
    getSelection().removeAllRanges(); getSelection().addRange(range);
    await action(s, 'inline-code');
    equal(s.body.querySelectorAll('tt').length, 2);
    await undo(s);
    equal(s.body.innerHTML, '<p><tt>Альфа бета гамма</tt></p>');
    await undo(s, true);
    equal(s.body.innerHTML, '<p><tt>Альфа </tt>бета<tt> гамма</tt></p>');
});

test('unlink is its own undo step after creating a link', async () => {
    const s = setup();
    await type(s, 'Контроль ссылки'); select(s);
    api.runFormattingCommand(s, 'createLink', 'https://example.com/?a=1&b=2');
    await tick();
    select(s, s.body.querySelector('a'));
    await action(s, 'remove-link', {targetLink: s.body.querySelector('a')});
    equal(s.body.querySelector('a'), null, `After unlink: ${s.body.innerHTML}`);
    await undo(s);
    equal(s.body.querySelector('a')?.getAttribute('href'), 'https://example.com/?a=1&b=2');
    await undo(s, true);
    equal(s.body.querySelector('a'), null, `After redo unlink: ${s.body.innerHTML}`);
    equal(plain(s), 'Контроль ссылки');
});

test('overlay caption commit and styling undo as one operation', async () => {
    const s = setup('<p>Before</p><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="fixture">');
    api.beginImageCaptionEditing(s, s.body.querySelector('img'), 'Caption');
    await tick();
    const caption = s.imageCaptionEditor.caption;
    caption.focus();
    document.execCommand('insertText', false, 'Первая строка\nВторая строка');
    caption.dataset.captionFont = 'serif';
    api.finishImageCaptionEditing(s, true, true);
    await tick();
    equal(s.body.querySelector('.post-media-overlay-caption')?.textContent, 'Первая строка\nВторая строка');
    await undo(s);
    equal(s.body.querySelector('.post-media-overlay-caption'), null);
    equal(s.body.querySelectorAll('img').length, 1);
    await undo(s, true);
    equal(s.body.querySelector('.post-media-overlay-caption')?.dataset.captionFont, 'serif');
});

for (const kind of ['ordered-list', 'unordered-list']) {
    test(`${kind} to paragraph removes lists, with undo/redo`, async () => {
        const s = setup('<p>Один</p><p>Два</p><p>Три</p>');
        select(s); await action(s, kind);
        ok(s.body.querySelector('li'), 'List created');
        ok(api.contextBlockStyle(s.body, getSelection().getRangeAt(0)) !== 'paragraph', 'List must not be reported as a paragraph');
        await action(s, 'paragraph');
        equal(s.body.querySelector('ol, ul, li'), null);
        equal(Array.from(s.body.querySelectorAll('p'), p => p.textContent).join('|'), 'Один|Два|Три', s.body.innerHTML);
        await undo(s); equal(s.body.querySelectorAll('li').length, 3);
        await undo(s, true); equal(s.body.querySelector('ol, ul, li'), null);
    });
}

function deferredUpload(s, kind = 'audio', initialRange = null) {
    let finish;
    const response = new Promise(resolve => { finish = resolve; });
    window.fetch = async () => response;
    const range = initialRange instanceof Range
        ? initialRange
        : select(s, s.body, true);
    const file = kind === 'audio' ? new File(['RIFF'], 'test.wav', {type: 'audio/wav'})
        : new File(['fixture'], 'test.png', {type: 'image/png'});
    api.insertMediaFiles(s, [file], range);
    return async (success = true) => {
        finish({ok: success, json: async () => ({
            success,
            action: 'media',
            kind,
            media_id: 42,
            url: kind === 'audio' ? '/test.wav'
                : 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7',
            ...(kind === 'image' ? {width: 1, height: 1} : {}),
        })});
        await Promise.all([...s.mediaUploads]); await tick();
    };
}
test('partial list conversion preserves neighbouring items and their numbers', async () => {
    const s = setup('<ol start="5"><li>First</li><li>Middle</li><li>Last</li></ol>');
    select(s, s.body.querySelectorAll('li')[1]);
    await action(s, 'paragraph');
    equal(s.body.querySelectorAll('li').length, 2);
    equal(s.body.querySelector('p')?.textContent, 'Middle');
    equal(s.body.querySelectorAll('ol')[1]?.getAttribute('start'), '7');
    equal(getSelection().toString(), 'Middle');
    await undo(s); equal(s.body.querySelectorAll('li').length, 3);
});
test('mixed and nested lists become valid paragraphs', async () => {
    const s = setup('<ol><li>One<ul><li>Nested</li></ul></li><li>Two</li></ol><ul><li>Three</li></ul>');
    select(s); await action(s, 'paragraph');
    equal(s.body.querySelector('ol, ul, li, p p'), null);
    equal(Array.from(s.body.children, node => node.textContent).join('|'), 'One|Nested|Two|Three');
    await undo(s); equal(s.body.querySelectorAll('li').length, 4);
    await undo(s, true); equal(s.body.querySelector('ol, ul, li, p p'), null);
});
test('paragraph converts headings together with lists and supports a caret in one item', async () => {
    const s = setup('<h2>Heading</h2><ol><li><h3>One</h3></li><li>Two</li></ol>');
    select(s); await action(s, 'paragraph');
    equal(s.body.querySelector('ol, ul, li, h2, h3, p p'), null);
    equal(Array.from(s.body.children, node => node.textContent).join('|'), 'Heading|One|Two');
    await undo(s);
    select(s, s.body.querySelectorAll('li')[1], true); await action(s, 'paragraph');
    equal(s.body.querySelectorAll('li').length, 1);
    equal(s.body.querySelector('p')?.textContent, 'Two');
    equal(getSelection().isCollapsed, true);
});
test('completed audio upload undo removes only audio; redo restores it', async () => {
    const s = setup(); await type(s, 'Перед аудио');
    const finish = deferredUpload(s); await finish();
    equal(s.body.querySelectorAll('audio').length, 1);
    await undo(s); equal(s.body.querySelector('audio'), null); equal(plain(s), 'Перед аудио');
    await undo(s, true); equal(s.body.querySelectorAll('audio').length, 1);
    equal(s.body.querySelector('.post-media-upload'), null);
});
test('undo during upload, completion, redo: no stuck placeholder or second request', async () => {
    const s = setup(); await type(s, 'До загрузки');
    const finish = deferredUpload(s); await tick();
    await undo(s); equal(s.body.querySelector('.post-media-upload'), null);
    await finish(); equal(s.body.querySelector('audio'), null);
    await undo(s, true); equal(s.body.querySelectorAll('audio').length, 1);
    equal(s.body.querySelector('.post-media-upload'), null);
    equal(s.uploadedMediaIds.has(42), true, 'Undone uploads are still tracked for cleanup');
});
test('text typed while uploading has its own undo before audio removal', async () => {
    const s = setup('<p>Before</p>');
    const finish = deferredUpload(s); await tick();
    select(s, s.body, true); await type(s, 'After');
    await finish();
    await undo(s); equal(s.body.querySelectorAll('audio').length, 1); equal(plain(s), 'Before');
    await undo(s); equal(s.body.querySelector('audio'), null); equal(plain(s), 'Before');
    await undo(s, true); await undo(s, true);
    equal(s.body.querySelectorAll('audio').length, 1); equal(plain(s), 'BeforeAfter');
});
test('failed upload never reappears as an unfinished placeholder on redo', async () => {
    const s = setup('<p>Before</p>'); const finish = deferredUpload(s); await tick();
    await finish(false); await undo(s); await undo(s, true);
    equal(s.body.querySelector('.post-media-upload'), null);
    equal(plain(s), 'Before');
});

test('image completion after undo restores a finished image on redo', async () => {
    const s = setup('<p>Image test</p>');
    const finish = deferredUpload(s, 'image'); await tick();
    await undo(s); equal(s.body.querySelector('img'), null);
    await finish(); equal(s.body.querySelector('img'), null);
    await undo(s, true); equal(s.body.querySelectorAll('img').length, 1);
    equal(s.body.querySelector('img').getAttribute('width'), '1');
    equal(s.body.querySelector('img').getAttribute('height'), '1');
    equal(s.body.querySelector('.is-processing, [data-post-history-upload]'), null);
    ok(s.body.querySelector('.is-inline-caption-entry'), 'Finished image has its caption control');
});

test('cancelling the editor during upload releases the file even if response wins abort', async () => {
    const s = setup('<p>Cancel test</p>'); const finish = deferredUpload(s); await tick();
    api.editorStates.delete(s.card); s.history?.destroy(); s.card.remove();
    const releases = [];
    window.fetch = async (_url, options) => { releases.push(options.body.get('inplace_action')); return {}; };
    await finish();
    equal(releases.join(','), 'media_release');
    equal(s.uploadedMediaIds.size, 0);
});

test('caption cancellation adds no undo step', async () => {
    const s = setup('<p>Before</p><img alt="fixture">');
    api.beginImageCaptionEditing(s, s.body.querySelector('img'), 'Caption'); await tick();
    s.imageCaptionEditor.caption.textContent = 'Discard me';
    api.finishImageCaptionEditing(s, false, true); await tick();
    select(s, s.body, true); await type(s, 'After');
    await undo(s); equal(plain(s), 'Before');
    equal(s.body.querySelector('.post-media-overlay-caption'), null);
});

test('inline image caption commits and undoes as one operation', async () => {
    const s = setup('<div class="post-media-picture"><img alt="fixture"><div class="post-caption"></div></div>');
    api.prepareEditableMedia(s.body);
    s.history?.destroy(); s.history = api.createBodyHistory?.(s);
    const caption = s.body.querySelector('.post-caption');
    api.beginInlineMediaCaption(s, caption); select(s, caption); caption.focus();
    document.execCommand('insertText', false, 'Image caption');
    equal(caption.textContent, 'Image caption', `Caption input: ${s.body.innerHTML}; selection=${getSelection().anchorNode?.nodeName}; focus=${document.activeElement?.outerHTML}`);
    api.finishInlineMediaCaption(s, caption, true); await tick();
    await undo(s); equal(s.body.querySelector('.post-caption')?.textContent, '');
    await undo(s, true); equal(s.body.querySelector('.post-caption')?.textContent, 'Image caption');
});

test('ordinary Enter advances the caret by exactly one visible text line', async () => {
    const s = setup('<p>First line</p>');
    const first = s.body.firstElementChild;
    select(s, first, true);
    document.execCommand('insertParagraph');
    await frame();

    const second = first.nextElementSibling;
    equal(second?.tagName, 'P', `Enter must create one paragraph: ${s.body.innerHTML}`);
    equal(s.body.children.length, 2, `Enter must create only one paragraph: ${s.body.innerHTML}`);
    const firstStyle = getComputedStyle(first);
    equal(firstStyle.marginBottom, '0px', 'The editor must not add a paragraph gap to the caret step');
    const lineHeight = parseFloat(firstStyle.lineHeight);
    const step = second.getBoundingClientRect().top - first.getBoundingClientRect().top;
    ok(Math.abs(step - lineHeight) < 1.5, `One Enter must move one line; line=${lineHeight}px, step=${step}px`);
});

test('Enter leaves a legacy nested image caption after the complete top-level media block', async () => {
    const s = setup('<div class="post-picture post-media-picture"><div class="post-picture post-media-picture"><img alt="nested"><div class="post-caption">Nested caption</div></div><img alt="outer"></div>');
    api.prepareEditableMedia(s.body);
    const outer = s.body.firstElementChild;
    const nestedCaption = outer.querySelector('.post-media-picture > .post-caption');
    api.beginInlineMediaCaption(s, nestedCaption);
    select(s, nestedCaption, true);
    nestedCaption.focus();

    const leave = new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true});
    nestedCaption.dispatchEvent(leave);
    equal(leave.defaultPrevented, true, 'Enter must leave the malformed legacy caption');
    const paragraph = outer.nextElementSibling;
    equal(paragraph?.tagName, 'P', `A body paragraph must follow the top-level media: ${s.body.innerHTML}`);
    equal(paragraph?.parentElement, s.body, 'The caret paragraph must not be nested in either media wrapper');
    ok(paragraph?.contains(getSelection().anchorNode), 'The caret moves into the visible paragraph after the media');
    await type(s, 'Body text');
    equal(paragraph.textContent, 'Body text');
});

test('dropping an image onto an existing image inserts a sibling instead of nested media', async () => {
    const s = setup('<div class="post-picture post-media-picture"><img alt="existing"><div class="post-caption"></div></div><p>After</p>');
    const existing = s.body.firstElementChild;
    const caption = existing.querySelector('.post-caption');
    const range = document.createRange();
    range.selectNodeContents(caption);
    range.collapse(true);

    const finish = deferredUpload(s, 'image', range);
    await finish();
    const media = Array.from(s.body.children).filter((element) => element.matches('.post-media-picture'));
    equal(media.length, 2, `Both images must be top-level siblings: ${s.body.innerHTML}`);
    equal(media[0], existing);
    equal(media[1].parentElement, s.body);
    equal(existing.querySelector('.post-media-picture'), null, 'The existing image must never contain the dropped one');
    equal(media[1].nextElementSibling?.textContent, 'After');
});

test('Enter leaves the last image caption in an ordinary body paragraph while Shift+Enter inserts a caption line break', async () => {
    const s = setup('<p>Reference body text</p><div class="post-media-picture"><img alt="fixture"><div class="post-caption"></div></div><p><br></p>');
    api.prepareEditableMedia(s.body);
    const media = s.body.querySelector('.post-media-picture');
    const caption = s.body.querySelector('.post-caption');
    api.beginInlineMediaCaption(s, caption); select(s, caption); caption.focus();
    document.execCommand('insertText', false, 'First line');

    const lineBreak = new KeyboardEvent('keydown', {
        key: 'Enter', shiftKey: true, bubbles: true, cancelable: true,
    });
    caption.dispatchEvent(lineBreak);
    document.execCommand('insertText', false, 'Second line');
    equal(caption.innerText, 'First line\nSecond line');
    ok(caption.classList.contains('is-editing-inline-caption'), 'Shift+Enter keeps caption editing active');

    const leave = new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true});
    caption.dispatchEvent(leave);
    equal(leave.defaultPrevented, true, 'Enter is handled by the caption editor');
    equal(s.mediaCaptionEditors.size, 0, 'Caption editing finishes on Enter');
    ok(caption.classList.contains('is-inline-caption-entry'), 'Caption returns to its finished state');
    const paragraph = media.nextElementSibling;
    equal(paragraph?.tagName, 'P', `A paragraph must be available after the last image: ${s.body.innerHTML}`);
    equal(paragraph?.parentElement, s.body, 'The paragraph must be outside the compact caption block');
    ok(paragraph?.classList.contains('post-editor-body-paragraph'), 'The reused trailing block has explicit body typography');
    ok(paragraph?.contains(getSelection().anchorNode), 'The caret moves into the paragraph after the image');
    ok(paragraph?.classList.contains('has-leading-boundary-caret'), 'The first Enter paints a visible caret in the empty body paragraph');
    ok(s.body.classList.contains('uses-synthetic-boundary-caret'), 'The unreliable native empty-paragraph caret is hidden');

    document.execCommand('insertText', false, 'Text after image');
    equal(paragraph.textContent, 'Text after image');
    equal(paragraph.classList.contains('has-leading-boundary-caret'), false, 'Typing restores the native caret');
    equal(caption.textContent, 'First line\nSecond line');
    const paragraphStyle = getComputedStyle(paragraph);
    const referenceStyle = getComputedStyle(s.body.firstElementChild);
    const captionStyle = getComputedStyle(caption);
    equal(paragraphStyle.fontFamily, referenceStyle.fontFamily, 'The new paragraph uses the article font');
    equal(paragraphStyle.fontSize, referenceStyle.fontSize, 'The new paragraph uses the article font size');
    equal(paragraphStyle.lineHeight, referenceStyle.lineHeight, 'The new paragraph uses the article line height');
    ok(parseFloat(paragraphStyle.fontSize) > parseFloat(captionStyle.fontSize), 'Body text is visibly larger than a caption');
    equal(api.editableBodyHtml(s).includes('post-editor-body-paragraph'), false, 'Editor-only typography is never saved');
});

test('clicking an empty last-image caption then Enter starts an ordinary body paragraph', async () => {
    const s = setup('<p>Reference body text</p><div class="post-picture post-media-picture"><img alt="fixture"></div>');
    api.prepareEditableMedia(s.body);
    const media = s.body.querySelector('.post-media-picture');
    const caption = s.body.querySelector('.post-caption');

    caption.click();
    await frame();
    ok(caption.contains(getSelection().anchorNode), 'Clicking the placeholder must select the nested caption editor');
    equal(s.body.getAttribute('contenteditable'), 'true', 'The surrounding post remains editable');

    const leave = new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true});
    document.activeElement.dispatchEvent(leave);
    equal(leave.defaultPrevented, true, 'The first Enter after clicking the empty caption must be handled');
    equal(caption.textContent, '', 'Leaving an empty caption does not create caption content');
    ok(caption.classList.contains('is-inline-caption-entry'), 'The empty caption remains available as a placeholder');

    const paragraph = media.nextElementSibling;
    equal(paragraph?.tagName, 'P', `Enter must create a paragraph after the image: ${s.body.innerHTML}`);
    equal(paragraph?.parentElement, s.body, 'The new paragraph must be outside the image and caption');
    ok(paragraph?.contains(getSelection().anchorNode), 'The caret must move into the new body paragraph');
    ok(paragraph?.classList.contains('has-leading-boundary-caret'), 'The empty paragraph must show a caret after one Enter');
    await type(s, 'Ordinary body text');
    equal(paragraph.textContent, 'Ordinary body text');

    const paragraphStyle = getComputedStyle(paragraph);
    const referenceStyle = getComputedStyle(s.body.firstElementChild);
    const captionStyle = getComputedStyle(caption);
    equal(paragraphStyle.fontFamily, referenceStyle.fontFamily, 'Text after the image uses the article font');
    equal(paragraphStyle.fontSize, referenceStyle.fontSize, 'Text after the image uses the article font size');
    equal(paragraphStyle.lineHeight, referenceStyle.lineHeight, 'Text after the image uses the article line height');
    ok(parseFloat(paragraphStyle.fontSize) > parseFloat(captionStyle.fontSize), 'Text after the image is visibly larger than a caption');
});

test('leaving a caption restores a previously collapsed body line in one Enter', async () => {
    const s = setup('<div class="post-picture post-media-picture"><img alt="fixture"><div class="post-caption">Image caption</div></div><p class="post-editor-body-paragraph post-editor-collapsed-boundary-paragraph"><br></p>');
    api.prepareEditableMedia(s.body);
    const caption = s.body.querySelector('.post-caption');
    const paragraph = s.body.lastElementChild;

    caption.click();
    await frame();
    const leave = new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true});
    document.activeElement.dispatchEvent(leave);

    equal(leave.defaultPrevented, true, 'Enter must leave the caption');
    equal(paragraph.classList.contains('post-editor-collapsed-boundary-paragraph'), false, 'The body line is visible immediately');
    ok(paragraph.contains(getSelection().anchorNode), 'The caret moves into the restored line');
    ok(paragraph.classList.contains('has-leading-boundary-caret'), 'The restored line paints its caret immediately');
});

test('one Enter before leading media creates exactly one empty paragraph', async () => {
    const s = setup('<div class="post-picture post-media-picture"><img alt="fixture"><div class="post-caption"></div></div>');
    const media = s.body.firstElementChild;
    const range = document.createRange();
    range.setStart(s.body, 0);
    range.collapse(true);
    s.body.focus();
    getSelection().removeAllRanges();
    getSelection().addRange(range);

    const enter = new InputEvent('beforeinput', {
        inputType: 'insertParagraph', bubbles: true, cancelable: true,
    });
    s.body.dispatchEvent(enter);
    await tick();

    equal(enter.defaultPrevented, true, 'The browser must not split the prepared paragraph a second time');
    equal(s.body.children.length, 2, `One paragraph and the media must remain: ${s.body.innerHTML}`);
    const paragraph = s.body.firstElementChild;
    equal(paragraph?.tagName, 'P');
    equal(paragraph?.nextElementSibling, media);
    equal(paragraph?.childNodes.length, 1);
    equal(paragraph?.firstChild?.tagName, 'BR');
    ok(paragraph?.contains(getSelection().anchorNode), 'The caret stays in the one new paragraph');
    equal(s.bodyDirty, true, 'The manual paragraph insertion follows the normal input path');

    await undo(s);
    equal(s.body.children.length, 1, 'Undo removes the one inserted paragraph');
    ok(s.body.firstElementChild?.matches('.post-picture.post-media-picture'), 'Undo restores the original leading media');
});

test('Backspace after an image removes only the empty line and preserves its caption', async () => {
    const s = setup('<div class="post-picture post-media-picture"><img alt="fixture"><div class="post-caption">Image caption</div></div><p class="post-editor-body-paragraph"><br></p>');
    api.prepareEditableMedia(s.body);
    s.history?.destroy();
    s.history = api.createBodyHistory?.(s);
    const paragraph = s.body.lastElementChild;
    const range = document.createRange();
    range.setStart(paragraph, 0);
    range.collapse(true);
    s.body.focus();
    getSelection().removeAllRanges();
    getSelection().addRange(range);

    const backspace = new InputEvent('beforeinput', {
        inputType: 'deleteContentBackward', bubbles: true, cancelable: true,
    });
    s.body.dispatchEvent(backspace);
    await tick();

    equal(backspace.defaultPrevented, true, 'The browser must not merge the paragraph into the media wrapper');
    equal(s.body.children.length, 2, `The media and its safe text boundary must remain: ${s.body.innerHTML}`);
    equal(s.body.querySelector('.post-media-picture')?.parentElement, s.body);
    equal(s.body.querySelector('.post-caption')?.textContent, 'Image caption');
    ok(paragraph.classList.contains('post-editor-collapsed-boundary-paragraph'), 'The deleted line is visually collapsed');
    equal(paragraph.getBoundingClientRect().height, 0, 'The deleted line takes no vertical space');
    equal(getComputedStyle(paragraph).marginTop, '0px');
    equal(getComputedStyle(paragraph).marginBottom, '0px');
    equal(s.bodyDirty, true, 'Deleting the line follows the normal input path');
    ok(paragraph.contains(getSelection().anchorNode), 'The caret remains safely after the image');
    equal(api.editableBodyHtml(s).includes('<p'), false, 'The deleted line is not saved');
    ok(api.editableBodyHtml(s).includes('Image caption'), 'Serializing the deletion keeps the caption');

    document.execCommand('insertText', false, 'Text after image');
    await tick();
    equal(paragraph.textContent, 'Text after image', 'Typing after Delete stays after the image');
    equal(paragraph.classList.contains('post-editor-collapsed-boundary-paragraph'), false, 'Typing restores ordinary paragraph layout');
    equal(s.body.querySelector('.post-caption')?.textContent, 'Image caption');

    await undo(s);
    ok(s.body.lastElementChild?.classList.contains('post-editor-collapsed-boundary-paragraph'), 'Undo typing restores the collapsed boundary');
    await undo(s);
    equal(s.body.children.length, 2, 'Undo Delete restores the empty line');
    equal(s.body.lastElementChild?.classList.contains('post-editor-collapsed-boundary-paragraph'), false);
    equal(s.body.querySelector('.post-caption')?.textContent, 'Image caption');
});

test('native history beforeinput is handled without touching title history', async () => {
    const s = setup('<p>Body</p>'); select(s); await action(s, 'inline-code');
    const undoEvent = new InputEvent('beforeinput', {inputType: 'historyUndo', bubbles: true, cancelable: true});
    s.body.dispatchEvent(undoEvent); await tick();
    equal(undoEvent.defaultPrevented, true); equal(s.body.querySelector('tt'), null);
    const titleUndo = new InputEvent('beforeinput', {inputType: 'historyUndo', bubbles: true, cancelable: true});
    s.title.dispatchEvent(titleUndo); equal(titleUndo.defaultPrevented, false);
});

test('typing is grouped, cursor movement splits groups, history is bounded', async () => {
    const s = setup();
    for (const letter of 'abc') {
        s.body.dispatchEvent(new InputEvent('beforeinput', {inputType: 'insertText', bubbles: true, cancelable: true}));
        await type(s, letter);
    }
    await undo(s); equal(plain(s), '');
    await undo(s, true); equal(plain(s), 'abc');
    select(s); await type(s, 'replacement');
    await undo(s); equal(plain(s), 'abc');
    for (let i = 0; i < 120; i++) {
        select(s); await action(s, i % 2 ? 'bold' : 'italic');
    }
    ok(s.history.length <= 100, 'History is bounded to 100 states');
    const text = plain(s); await undo(s); await undo(s, true); equal(plain(s), text);
});

test('multiline paste preserves data and undo restores the replaced selection', async () => {
    const s = setup('<p>Replace this</p>'); select(s);
    const clipboardData = new DataTransfer();
    clipboardData.setData('text/plain', 'Кириллица ё\n\n👩🏽‍💻 <tag>&');
    const event = new Event('paste', {bubbles: true, cancelable: true});
    // Firefox ignores ClipboardEventInit.clipboardData on synthetic events.
    // Supply only the fixture clipboard; the production paste handler and actual
    // insertHTML command still run unchanged in both browsers.
    Object.defineProperty(event, 'clipboardData', {value: clipboardData});
    s.body.dispatchEvent(event);
    await tick(); const inserted = api.editableBodyHtml(s);
    ok(plain(s).includes('<tag>&'), `Pasted markup is text: ${inserted}`);
    equal(s.body.querySelector('tag'), null);
    await undo(s); equal(plain(s), 'Replace this');
    await undo(s, true); equal(api.editableBodyHtml(s), inserted);
});

test('mixed native formatting, code, clear-format, undo/redo and a new branch', async () => {
    const s = setup(); await type(s, 'Текст ё 👩🏽‍💻 <>&'); select(s);
    await action(s, 'bold'); await action(s, 'inline-code'); await action(s, 'clear-format');
    equal(s.body.querySelector('b, strong, tt'), null, `After clear-format: ${s.body.innerHTML}`);
    await undo(s); ok(s.body.querySelector('tt'), 'Undo restores code');
    await undo(s); equal(s.body.querySelector('tt'), null); ok(s.body.querySelector('b, strong'), 'Bold retained');
    await undo(s); equal(s.body.querySelector('b, strong'), null);
    await undo(s, true); ok(s.body.querySelector('b, strong'), 'Redo bold');
    select(s, s.body, true); await type(s, ' Новая ветка');
    const html = s.body.innerHTML; await undo(s, true); equal(s.body.innerHTML, html);
});

document.getElementById('run').addEventListener('click', async () => {
    const output = document.getElementById('results');
    const results = [];
    document.getElementById('run').disabled = true;
    for (const {name, run} of cases) {
        try { await run(); results.push({name, passed: true}); }
        catch (error) { results.push({name, passed: false, error: String(error)}); }
        output.textContent = results.map(result => `${result.passed ? 'PASS' : 'FAIL'} ${result.name}${result.error ? `\n${result.error}` : ''}`).join('\n');
    }
    output.dataset.finished = 'true';
    output.dataset.failed = String(results.filter(result => !result.passed).length);
    window.editorTestResults = results;
    document.getElementById('run').disabled = false;
});
