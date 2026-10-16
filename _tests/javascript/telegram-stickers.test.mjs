import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test from 'node:test';
import vm from 'node:vm';

const source = await readFile(new URL('../../_assets/register/telegram/stickers.js', import.meta.url), 'utf8');
const filename = '01-0123456789abcdef0123.json';

async function loadSticker(path) {
    const requests = [];
    const rendered = [];
    const data = {v: '5.5.2', w: 512, h: 512, layers: []};
    const node = {dataset: {animation: path}, textContent: '✦', isConnected: true, setAttribute() {}};
    const animation = {addEventListener() {}, play() {}, pause() {}, goToAndStop() {}, destroy() {}};
    const document = {body: {}, hidden: false, addEventListener() {}, querySelectorAll: () => [node]};
    const window = {
        matchMedia: () => ({matches: false, addEventListener() {}}),
        lottie: {loadAnimation(options) { rendered.push(options); return animation; }},
    };
    vm.runInNewContext(source, {
        window, document, URL, AbortController,
        location: {href: 'https://example.test/blog/all/note', origin: 'https://example.test'},
        HTMLVideoElement: class {}, MutationObserver: class {observe() {}},
        fetch: async url => { requests.push(url.href); return {ok: true, json: async () => data}; },
    });
    await new Promise(resolve => setImmediate(resolve));
    return {requests, rendered, node, data};
}

for (const prefix of ['/_pictures/telegram/comments', '/blog/_pictures/telegram/comments',
    '/_pictures/example-site/comments/telegram', '/blog/_pictures/example-site/comments/telegram']) {
    test(`renders a managed Telegram animation at ${prefix}`, async () => {
        const path = `${prefix}/123/456/${filename}`;
        const result = await loadSticker(path);
        assert.deepEqual(result.requests, [`https://example.test${path}`]);
        assert.equal(result.rendered.length, 1);
        assert.equal(result.rendered[0].animationData, result.data);
        assert.equal(result.node.textContent, '');
    });
}

for (const path of [
    `https://other.test/_pictures/telegram/comments/123/456/${filename}`,
    `/_pictures/telegram/comments/0/456/${filename}`,
    `/_pictures/telegram/comments/123/456/not-an-animation.json`,
    `/_pictures/telegram/other/123/456/${filename}`,
    `/_pictures/telegram/comments/123/456/${filename}.html`,
    `/_pictures/telegram/comments/123/456/../${filename}`,
]) {
    test(`does not fetch or render an untrusted animation path: ${path}`, async () => {
        const result = await loadSticker(path);
        assert.deepEqual(result.requests, []);
        assert.equal(result.rendered.length, 0);
        assert.equal(result.node.textContent, '✦');
    });
}
