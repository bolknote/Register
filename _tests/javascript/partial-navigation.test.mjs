import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import {readFile} from 'node:fs/promises';

const source = await readFile(new URL('../../_assets/register/partial-navigation.js', import.meta.url), 'utf8');
const helpers = source.slice(source.indexOf('    function assetsMatch('), source.indexOf('    function parseReplacement('));
const context = vm.createContext({
    baselineAssets: ['/site.css'],
    normalizeAssets: assets => assets,
});
vm.runInContext(helpers, context);

function payload(fragment) {
    return {
        version: 1,
        title: 'Post',
        lang: 'en',
        bodyClass: 'blog',
        head: '',
        fragment,
        assets: ['/site.css'],
    };
}

test('partial navigation falls back to a full page for trusted inline code or styles', () => {
    assert.equal(context.validPayload(payload('<main>Plain post</main>')), true);
    assert.equal(context.validPayload(payload('<main><script>run()</script></main>')), false);
    assert.equal(context.validPayload(payload('<main><STYLE>.post { color: red }</STYLE></main>')), false);
});
