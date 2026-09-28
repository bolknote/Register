import assert from 'node:assert/strict';
import {formData, holdRequests} from './save-tests.mjs';

const source = 'A sentense.';
const corrected = 'A sentence.';
const tail = 'Keep this closing paragraph.';
const initial = `${source}\n${source}\n${tail}`;

async function select(page, from, to = from) {
    await page.evaluate(({from, to}) => {
        const editor = document.querySelector('.CodeMirror').CodeMirror;
        editor.setSelection(from, to);
        editor.focus();
    }, {from, to});
}

export async function runAdminAiTargetRegressions(browser, origin) {
    const scenarios = [
        ['deleted-source', 'proofread'], ['changed-source', 'proofread'], ['undo-source', 'proofread'],
        ['identical-prefix', 'proofread'], ['paste-prefix', 'proofread'], ['prefix', 'proofread'], ['deleted-prefix', 'proofread'],
        ['suffix', 'proofread'], ['whole-body', 'proofread'], ['failure', 'proofread'],
        ['save', 'proofread'], ['deleted-source', 'title'], ['deleted-source', 'tags'],
    ];
    for (const [mode, action] of scenarios) {
        const page = await browser.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on('pageerror', error => errors.push(String(error)));
        try {
            const requests = holdRequests(page, '**/admin-ai');
            const saves = holdRequests(page, '**/admin-save?id=9');
            await requests.installed;
            await saves.installed;
            await page.goto(origin + '/admin.html?id=9&codemirror=1&toolbar=1&ai=1');
            await page.waitForFunction(() => window.adminEditorReady);
            await page.evaluate(initial => window.adminEditor.setValue(initial, true), initial);
            const line = mode === 'deleted-prefix' ? 1 : 0;
            await select(page, {line, ch: 0}, {line, ch: mode === 'whole-body' ? 0 : source.length});
            await page.locator(`[data-ai-action="${action}"]`).click();
            const request = await requests.next();
            assert.equal((await formData(request)).get('text').replace(/\r\n/gu, '\n'), mode === 'whole-body' ? initial : source);
            let before = initial;
            let expected = `${corrected}\n${source}\n${tail}`;
            let stale = false;
            if (mode === 'deleted-source' || mode === 'deleted-prefix') {
                await select(page, {line: 0, ch: 0}, {line: 1, ch: 0});
                await page.keyboard.press('Backspace');
                before = `${source}\n${tail}`;
                stale = mode === 'deleted-source';
                expected = stale ? before : `${corrected}\n${tail}`;
            } else if (mode === 'changed-source' || mode === 'undo-source') {
                await select(page, {line: 0, ch: 0}, {line: 0, ch: source.length});
                await page.keyboard.insertText('Manually revised.');
                before = `Manually revised.\n${source}\n${tail}`;
                await page.waitForFunction(text => adminEditor.getValue() === text, before);
                if (mode === 'undo-source') {
                    const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                    await page.keyboard.press(`${modifier}+z`);
                    before = initial;
                }
                stale = true;
                expected = before;
            } else if (['identical-prefix', 'paste-prefix', 'prefix'].includes(mode)) {
                const prefix = (mode === 'prefix' ? 'New opening paragraph.' : source) + '\n';
                const modifier = await page.evaluate(() => /mac/i.test(navigator.platform) ? 'Meta' : 'Control');
                if (mode === 'paste-prefix') {
                    await select(page, {line: 0, ch: 0}, {line: 1, ch: 0});
                    await page.keyboard.press(`${modifier}+c`);
                }
                await select(page, {line: 0, ch: 0});
                if (mode === 'paste-prefix') await page.keyboard.press(`${modifier}+v`);
                else await page.keyboard.insertText(prefix);
                before = prefix + initial;
                // DOM input may report the duplicate at the opposite end of
                // the source. Reject that ambiguous reply; direct clipboard
                // edits still track the original occurrence precisely.
                stale = mode === 'identical-prefix';
                expected = stale ? before : prefix + expected;
            } else if (mode === 'suffix') {
                await select(page, {line: 0, ch: source.length});
                await page.keyboard.insertText(' Appended.');
                before = `${source} Appended.\n${source}\n${tail}`;
                expected = `${corrected} Appended.\n${source}\n${tail}`;
            } else if (mode === 'whole-body') {
                expected = initial.replaceAll(source, corrected);
            }
            await page.waitForFunction(text => adminEditor.getValue() === text, before);
            if (mode === 'save') {
                await page.getByRole('button', {name: 'Save', exact: true}).click();
                const save = await saves.next();
                assert.equal((await formData(save)).get('body').replace(/\r\n/gu, '\n'), before);
                await save.fulfill({status: 503, json: {success: false, message: 'Try again'}});
                expected = before;
            }
            await request.fulfill(mode === 'failure'
                ? {status: 503, json: {success: false, message: 'AI failed'}}
                : {json: {success: true, result: mode === 'whole-body' ? expected : corrected}});
            await page.waitForFunction(() => document.getElementById('content-editor-ai-tools').getAttribute('aria-busy') === 'false');
            if (mode === 'failure') expected = before;
            assert.equal(await page.evaluate(() => adminEditor.getValue()), expected, `${mode}/${action}: AI must edit only its original source`);
            assert.equal(await page.locator('#ai-tools-status').textContent(), stale ? 'The source text has changed.' : mode === 'failure' ? 'AI failed' : '');
            assert.equal(await page.locator('[name="title"]').inputValue(), 'Server title');
            assert.equal(await page.locator('[name="tags"]').inputValue(), 'old');
            assert.equal(await page.evaluate(() => document.querySelector('.CodeMirror').CodeMirror.getAllMarks()
                .filter(marker => !marker.className).length), 0, 'Completion, rejection, failure and save must release the tracked range');

            if (!stale && !['save', 'failure'].includes(mode)) {
                await page.getByRole('button', {name: 'Undo', exact: true}).click();
                assert.equal(await page.evaluate(() => adminEditor.getValue()), before, 'Undo must keep surrounding edits');
                await page.getByRole('button', {name: 'Redo', exact: true}).click();
                assert.equal(await page.evaluate(() => adminEditor.getValue()), expected);
            }
            await page.getByRole('button', {name: 'Save', exact: true}).click();
            const save = await saves.next();
            assert.equal((await formData(save)).get('body').replace(/\r\n/gu, '\n'), expected);
            await save.fulfill({json: {revision: 2, urlStatus: 'ok', urlTitle: '', url: '/post'}});
            assert.deepEqual(errors, []);
        } catch (error) {
            throw new Error(`admin AI ${mode}/${action}: ${error.message}`, {cause: error});
        } finally {
            await page.close();
        }
    }
    console.log('admin AI: tracked selections survive surrounding edits, reject deleted/replaced sources, and release on success, failure and save; undo/redo and saved HTML stay correct');
}
