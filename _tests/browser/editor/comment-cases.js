/* Real contenteditable and asynchronous comment-save regressions. */
const cases = [];
const test = (name, run) => cases.push({name, run});
const tick = () => new Promise(resolve => setTimeout(resolve, 0));
const nativeFetch = window.fetch.bind(window);

function equal(actual, expected, message = '') {
    if (actual !== expected) throw new Error(`${message}\nExpected: ${expected}\nActual: ${actual}`);
}

function ok(value, message) {
    if (!value) throw new Error(message);
}

function setupComment(editorHtml, bodyHtml = 'Старый текст') {
    const fixture = document.getElementById('fixture');
    window.RegisterCommentEditor.destroy(fixture);
    fixture.innerHTML = `<article class="comment-item is-editing" data-comment-id="19">
        <nav class="comment-moderation">
            <button class="comment-tools-menu-toggle" type="button" aria-expanded="false"></button>
            <button class="comment-edit-start" type="button">Edit</button>
        </nav>
        <div class="comment-body">
            <a class="comment-addressee" href="#18">Адресат,</a>
            ${bodyHtml}
            <div class="comment-reaction-summary"><span>👍</span></div>
        </div>
        <div class="comment-actions"><button class="comment-reply" type="button">Reply</button></div>
        <form class="comment-edit-form" method="post" action="/comment-edit">
            <div class="comment-editor" data-comment-editor>
                <div class="comment-editor-toolbar" role="toolbar" hidden></div>
                <div class="comment-editor-link-panel" data-comment-link-panel hidden>
                    <input type="url" data-comment-link-input>
                    <button type="button" data-comment-link-remove>Remove</button>
                </div>
                <div class="comment-editor-surface" role="textbox" contenteditable="true" hidden></div>
                <textarea class="comment-editor-source" name="text"></textarea>
            </div>
            <input type="hidden" name="moderation_action" value="edit">
            <input type="hidden" name="comment_anchor" value="19">
            <button type="submit">Save</button>
            <button class="comment-edit-cancel" type="button">Cancel</button>
        </form>
    </article>`;
    const form = fixture.querySelector('.comment-edit-form');
    form.querySelector('.comment-editor-source').value = editorHtml;
    window.RegisterCommentEditor.enhance(fixture);
    window.commentFlowTest.initCommentModeration(fixture);

    return {
        fixture,
        item: fixture.querySelector('.comment-item'),
        body: fixture.querySelector('.comment-body'),
        form,
        source: form.querySelector('.comment-editor-source'),
        surface: form.querySelector('.comment-editor-surface'),
    };
}

function selectEnd(element) {
    element.focus();
    const range = document.createRange();
    range.selectNodeContents(element);
    range.collapse(false);
    getSelection().removeAllRanges();
    getSelection().addRange(range);
}

test('an old browser placeholder is editable and saves without the accidental blank paragraph', () => {
    const comment = setupComment('Первая строка<p><br></p><p>Вторая строка</p>');
    equal(comment.surface.innerHTML, 'Первая строка<p>Вторая строка</p>');
    equal(comment.source.value, 'Первая строка<p>Вторая строка</p>');
});

test('Enter inserts exactly one visible line break', async () => {
    const comment = setupComment('Первая строка');
    selectEnd(comment.surface);
    const enter = new KeyboardEvent('keydown', {key: 'Enter', bubbles: true, cancelable: true});
    comment.surface.dispatchEvent(enter);
    equal(enter.defaultPrevented, true, 'The editor handles Enter itself');
    document.execCommand('insertText', false, 'Вторая строка');
    await tick();
    equal(comment.source.value, 'Первая строка<br>Вторая строка');
});

test('saving immediately replaces the editor with an optimistic comment preview', async () => {
    const comment = setupComment('Старый текст');
    comment.surface.innerHTML = '<strong>Новый текст</strong>';
    comment.surface.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'insertText'}));

    let finishRequest;
    window.fetch = () => new Promise(resolve => { finishRequest = resolve; });
    try {
        comment.form.requestSubmit();
        await tick();
        equal(comment.form.hidden, true, 'The editor is hidden while the request is pending');
        equal(comment.item.classList.contains('is-editing'), false);
        equal(comment.body.querySelector('strong')?.textContent, 'Новый текст');
        equal(comment.body.querySelector('.comment-addressee')?.textContent, 'Адресат,');
        equal(comment.body.querySelector('.comment-reaction-summary')?.textContent, '👍');
        equal(comment.item.getAttribute('aria-busy'), 'true');

        finishRequest({ok: true, json: async () => ({success: true, action: 'edit'})});
        await tick();
        await tick();
        equal(comment.form.hidden, true);
        equal(comment.item.hasAttribute('aria-busy'), false);
    } finally {
        window.fetch = nativeFetch;
    }
});

test('a failed save restores the editor and the original rendered comment', async () => {
    const comment = setupComment('Старый текст');
    comment.surface.textContent = 'Несохранённый текст';
    comment.surface.dispatchEvent(new InputEvent('input', {bubbles: true, inputType: 'insertText'}));
    window.fetch = async () => ({
        ok: false,
        json: async () => ({success: false, message: 'Ошибка сохранения'}),
    });
    try {
        comment.form.requestSubmit();
        await tick();
        await tick();
        equal(comment.form.hidden, false);
        equal(comment.item.classList.contains('is-editing'), true);
        ok(comment.body.textContent.includes('Старый текст'), 'The original comment is restored');
        equal(comment.surface.textContent, 'Несохранённый текст');
        equal(comment.fixture.querySelector('.comment-moderation-error')?.textContent, 'Ошибка сохранения');
    } finally {
        window.fetch = nativeFetch;
    }
});

test('revealing a comment releases the focused moderation menu for a live update', async () => {
    const fixture = document.getElementById('fixture');
    window.RegisterCommentEditor.destroy(fixture);
    fixture.innerHTML = `<div class="live-comments-region" data-live-region="comments:post:1">
        <article class="comment-item is-hidden" data-moderation-state="hidden">
            <nav class="comment-moderation is-menu-open">
                <button class="comment-tools-menu-toggle" type="button" aria-expanded="true">Menu</button>
                <div class="comment-tools-overflow">
                    <form class="comment-moderation-action" method="post" action="/comment-moderate" data-moderation-action="show">
                        <input type="hidden" name="moderation_action" value="show">
                        <button type="submit">Reveal</button>
                    </form>
                </div>
            </nav>
            <header class="comment-meta"><span class="comment-state-mark">Hidden</span></header>
            <div class="comment-body">Comment text</div>
            <div class="comment-actions"><a class="comment-reply" href="#add-comment" hidden>Reply</a></div>
        </article>
    </div>`;
    window.commentFlowTest.initCommentModeration(fixture);

    const region = fixture.querySelector('.live-comments-region');
    const item = fixture.querySelector('.comment-item');
    const menu = fixture.querySelector('.comment-moderation');
    const toggle = fixture.querySelector('.comment-tools-menu-toggle');
    const form = fixture.querySelector('.comment-moderation-action');
    let refreshes = 0;
    const onRefresh = () => { refreshes += 1; };
    document.addEventListener('register:live-refresh', onRefresh);
    toggle.style.display = 'block';
    toggle.focus();
    equal(document.activeElement, toggle, 'The menu retains focus as it can on mobile browsers');

    window.fetch = async () => ({
        ok: true,
        json: async () => ({success: true, action: 'show'}),
    });
    try {
        form.requestSubmit();
        await tick();
        await tick();
        equal(item.classList.contains('is-hidden'), false);
        equal(item.querySelector('.comment-state-mark'), null);
        equal(menu.classList.contains('is-menu-open'), false, 'The completed action closes its menu');
        ok(!region.contains(document.activeElement), 'Menu focus must not block the server-rendered update');
        equal(refreshes, 1);
    } finally {
        document.removeEventListener('register:live-refresh', onRefresh);
        window.fetch = nativeFetch;
    }
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
    window.commentEditorTestResults = results;
    document.getElementById('run').disabled = false;
});
