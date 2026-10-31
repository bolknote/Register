import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {sanitizeGif} from '../../_assets/register/image-optimizer/js/source-candidate.js';
import {gifApplication, gifColorProfile, gifSubBlocks, gifWithPrivateMetadata} from '../_support/gif-fixture.mjs';

const fixture = name => new Uint8Array(readFileSync(new URL('../_resources/image-optimizer/' + name, import.meta.url)));
const output = async bytes => new Uint8Array(await sanitizeGif(bytes).arrayBuffer());
const concat = (...parts) => new Uint8Array(parts.flatMap(part => [...part]));
const headerEnd = bytes => 13 + (bytes[10] & 0x80 ? 3 * (1 << ((bytes[10] & 7) + 1)) : 0);

test('GIF removes comments, real XMP application data, unknown applications and trailing private data without changing animation', async () => {
    for (const name of ['animated.gif', 'animated-wide.gif', 'static.gif']) {
        const original = fixture(name);
        const {input, expected} = gifWithPrivateMetadata(original);
        assert.match(new TextDecoder().decode(input), /PRIVATE_/u);
        const cleaned = await output(input);
        assert.deepEqual(cleaned, expected, name + ': palettes, every compressed frame, disposal, delays and looping stay byte-exact');
        assert.doesNotMatch(new TextDecoder().decode(cleaned), /PRIVATE_|XMP DataXMP/u);
        assert.equal(sanitizeGif(input).type, 'image/gif');
    }
});

test('GIF retains multi-block ICC profiles alongside animation controls', async () => {
    assert.ok(gifColorProfile.length > 255);
    assert.equal(new TextDecoder().decode(gifColorProfile.subarray(36, 40)), 'acsp');
    const {input, expected} = gifWithPrivateMetadata(fixture('animated.gif'), gifColorProfile);
    assert.deepEqual(await output(input), expected);
    assert.match(new TextDecoder('latin1').decode(expected), /ICCRGBG1012/u);
});

test('GIF keeps the alternate ANIMEXTS loop extension and visible plain-text rendering blocks', async () => {
    const original = fixture('animated.gif');
    const offset = headerEnd(original);
    const text = concat(new Uint8Array([0x21, 0x01, 12]), new Uint8Array(12), gifSubBlocks(new TextEncoder().encode('VISIBLE')));
    const loop = gifApplication('ANIMEXTS1.0', new Uint8Array([1, 3, 0]));
    const source = concat(original.subarray(0, offset), loop, text, original.subarray(offset));
    assert.deepEqual(await output(source), source);
});

test('GIF metadata cleanup rejects every truncated prefix rather than returning a partial animation', () => {
    const original = gifWithPrivateMetadata(fixture('animated.gif')).input;
    const trailer = original.length - new TextEncoder().encode('PRIVATE_trailing_data').length;
    for (let length = 0; length < trailer; length++) {
        assert.throws(() => sanitizeGif(original.subarray(0, length)), /GIF/u, 'Prefix length ' + length);
    }
});

test('GIF cleanup rejects malformed graphic, application and plain-text blocks', () => {
    const original = fixture('animated.gif');
    const offset = headerEnd(original);
    for (const bad of [[0x21, 0xf9, 3, 0, 0, 0, 0], [0x21, 0xff, 1, 0, 0], [0x21, 1, 1, 0, 0], [0x7f]]) {
        const source = concat(original.subarray(0, offset), new Uint8Array(bad), original.subarray(offset));
        assert.throws(() => sanitizeGif(source), /GIF/u);
    }
});
