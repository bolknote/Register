import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const source = await readFile(
    new URL('../../_assets/register/image-optimizer/js/source-candidate.js', import.meta.url),
    'utf8'
);
const {sanitizeJpeg, sanitizePng, sanitizeWebp} = await import(
    'data:text/javascript;base64,' + Buffer.from(source).toString('base64')
);

const encoder = new TextEncoder();

function concat(...parts) {
    const size = parts.reduce(function (sum, part) { return sum + part.length; }, 0);
    const result = new Uint8Array(size);
    let offset = 0;
    parts.forEach(function (part) {
        result.set(part, offset);
        offset += part.length;
    });
    return result;
}

function jpegSegment(marker, payload) {
    const segment = new Uint8Array(payload.length + 4);
    segment.set([0xff, marker, (payload.length + 2) >> 8, (payload.length + 2) & 0xff]);
    segment.set(payload, 4);
    return segment;
}

function pngChunk(type, payload = new Uint8Array()) {
    const chunk = new Uint8Array(payload.length + 12);
    const view = new DataView(chunk.buffer);
    view.setUint32(0, payload.length, false);
    chunk.set(encoder.encode(type), 4);
    chunk.set(payload, 8);
    return chunk;
}

function webpChunk(type, payload) {
    const chunk = new Uint8Array(8 + payload.length + (payload.length & 1));
    chunk.set(encoder.encode(type), 0);
    new DataView(chunk.buffer).setUint32(4, payload.length, true);
    chunk.set(payload, 8);
    return chunk;
}

function webpFile(...chunks) {
    const body = concat(...chunks);
    const header = new Uint8Array(12);
    header.set(encoder.encode('RIFF'), 0);
    new DataView(header.buffer).setUint32(4, body.length + 4, true);
    header.set(encoder.encode('WEBP'), 8);
    return concat(header, body);
}

function orientationTiff(orientation = 6) {
    return new Uint8Array([
        0x49, 0x49, 0x2a, 0, 8, 0, 0, 0,
        1, 0,
        0x12, 0x01, 3, 0, 1, 0, 0, 0, orientation, 0, 0, 0,
        0, 0, 0, 0
    ]);
}

test('JPEG keeps ICC but strips EXIF, XMP and comments without re-encoding', async function () {
    const bytes = concat(
        new Uint8Array([0xff, 0xd8]),
        jpegSegment(0xe1, encoder.encode('Exif\0\0private')),
        jpegSegment(0xe1, encoder.encode('http://ns.adobe.com/xap/1.0/\0private')),
        jpegSegment(0xe2, concat(encoder.encode('ICC_PROFILE\0'), new Uint8Array([1, 1, 7]))),
        jpegSegment(0xfe, encoder.encode('private comment')),
        new Uint8Array([0xff, 0xda, 0, 2, 7, 8, 9, 0xff, 0xd9])
    );
    const output = new Uint8Array(await sanitizeJpeg(bytes).arrayBuffer());
    const rendered = new TextDecoder('latin1').decode(output);
    assert.match(rendered, /ICC_PROFILE/);
    assert.doesNotMatch(rendered, /private|adobe\.com/);
    assert.deepEqual(Array.from(output.slice(-5)), [7, 8, 9, 0xff, 0xd9]);
});

test('JPEG with EXIF orientation is re-encoded instead of being copied unrotated', function () {
    const bytes = concat(
        new Uint8Array([0xff, 0xd8]),
        jpegSegment(0xe1, concat(encoder.encode('Exif\0\0'), orientationTiff())),
        new Uint8Array([0xff, 0xda, 0, 2, 1, 0xff, 0xd9])
    );
    assert.equal(sanitizeJpeg(bytes), null);
});

test('JPEG strips metadata between progressive scans', async function () {
    const bytes = concat(
        new Uint8Array([0xff, 0xd8, 0xff, 0xda, 0, 2, 1, 2]),
        jpegSegment(0xfe, encoder.encode('private comment')),
        jpegSegment(0xe1, encoder.encode('http://ns.adobe.com/xap/1.0/\0private')),
        new Uint8Array([0xff, 0xda, 0, 2, 3, 4, 0xff, 0xd9])
    );
    const output = new Uint8Array(await sanitizeJpeg(bytes).arrayBuffer());
    const rendered = new TextDecoder('latin1').decode(output);
    assert.doesNotMatch(rendered, /private|adobe\.com/);
    assert.deepEqual(Array.from(output), [
        0xff, 0xd8, 0xff, 0xda, 0, 2, 1, 2,
        0xff, 0xda, 0, 2, 3, 4, 0xff, 0xd9
    ]);
});

test('PNG keeps rendering and color chunks while removing text metadata', async function () {
    const bytes = concat(
        new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        pngChunk('IHDR', new Uint8Array(13)),
        pngChunk('iCCP', encoder.encode('profile')),
        pngChunk('tEXt', encoder.encode('private')),
        pngChunk('IDAT', new Uint8Array([1, 2, 3])),
        pngChunk('IEND')
    );
    const output = new Uint8Array(await sanitizePng(bytes).arrayBuffer());
    const rendered = new TextDecoder('latin1').decode(output);
    assert.match(rendered, /iCCP/);
    assert.match(rendered, /IDAT/);
    assert.doesNotMatch(rendered, /tEXt|private/);
});

test('animated PNG is rejected instead of being flattened', function () {
    const bytes = concat(
        new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        pngChunk('IHDR', new Uint8Array(13)),
        pngChunk('acTL', new Uint8Array(8)),
        pngChunk('IEND')
    );
    assert.throws(() => sanitizePng(bytes), /Animated PNG/);
});

test('PNG with EXIF orientation is re-encoded before metadata is removed', function () {
    const bytes = concat(
        new Uint8Array([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
        pngChunk('IHDR', new Uint8Array(13)),
        pngChunk('eXIf', orientationTiff()),
        pngChunk('IDAT', new Uint8Array([1, 2, 3])),
        pngChunk('IEND')
    );
    assert.equal(sanitizePng(bytes), null);
});

test('WebP keeps ICC and pixels, strips EXIF and XMP, and fixes feature flags', async function () {
    const features = new Uint8Array(10);
    features[0] = 0x2c;
    const bytes = webpFile(
        webpChunk('VP8X', features),
        webpChunk('ICCP', encoder.encode('profile')),
        webpChunk('EXIF', encoder.encode('private')),
        webpChunk('XMP ', encoder.encode('private')),
        webpChunk('VP8 ', new Uint8Array([1, 2, 3, 4]))
    );
    const output = new Uint8Array(await sanitizeWebp(bytes).arrayBuffer());
    const rendered = new TextDecoder('latin1').decode(output);
    assert.match(rendered, /ICCP/);
    assert.match(rendered, /VP8 /);
    assert.doesNotMatch(rendered, /EXIF|XMP |private/);
    assert.equal(output[20], 0x20);
    assert.equal(new DataView(output.buffer).getUint32(4, true), output.length - 8);
});

test('animated WebP is rejected instead of being flattened', function () {
    const features = new Uint8Array(10);
    features[0] = 0x02;
    const bytes = webpFile(
        webpChunk('VP8X', features),
        webpChunk('VP8 ', new Uint8Array([1, 2, 3, 4]))
    );
    assert.throws(() => sanitizeWebp(bytes), /Animated WebP/);
});

test('WebP with EXIF orientation is re-encoded before metadata is removed', function () {
    const bytes = webpFile(
        webpChunk('EXIF', concat(encoder.encode('Exif\0\0'), orientationTiff())),
        webpChunk('VP8 ', new Uint8Array([1, 2, 3, 4]))
    );
    assert.equal(sanitizeWebp(bytes), null);
});
