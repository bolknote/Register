import test from 'node:test';
import assert from 'node:assert/strict';
import {copyFile, mkdir, mkdtemp, readFile, rm, writeFile} from 'node:fs/promises';
import {tmpdir} from 'node:os';
import {resolve} from 'node:path';
import {pathToFileURL} from 'node:url';
import {Worker as NodeWorker} from 'node:worker_threads';
import {DOMParser} from '@xmldom/xmldom';
import {sanitizeHdrJpeg} from '../../_assets/register/image-optimizer/js/hdr-jpeg.js';
import {sanitizeJpeg, createSanitizedSourceCandidate} from '../../_assets/register/image-optimizer/js/source-candidate.js';
import {planHdrImageDimensions} from '../../_assets/register/image-optimizer/js/policy.js';
import {optimizeImage} from '../../_assets/register/image-optimizer/js/optimizer.js';
import {hdrFixture, jpegImages, baseJpeg, gainJpeg, gainMetadata} from '../_support/hdr-jpeg-fixture.mjs';
import {minifyAsset} from '../../tools/assets/optimizer.mjs';

globalThis.DOMParser = DOMParser;

class CodecWorker {
    constructor(url) {
        assert.match(url, /hdr-optimize-worker\.js$/);
        this.worker = new NodeWorker(new URL('../_support/hdr-worker.mjs', import.meta.url), {workerData: {url}});
        this.worker.on('message', data => this.onmessage?.({data}));
        this.worker.on('error', error => this.onerror?.(error));
    }
    postMessage(message, transfer) { this.worker.postMessage(message, transfer); }
    terminate() { return this.worker.terminate(); }
}

async function sanitized(input) {
    return Buffer.from(await sanitizeHdrJpeg(input).blob.arrayBuffer());
}

test('HDR keeps both compressed images and ICC while removing private metadata', async () => {
    const output = await sanitized(hdrFixture());
    const [base, gain] = jpegImages(output);
    assert.ok(gain.subarray(0, 2).equals(Buffer.from([255, 216])));
    assert.ok(output.includes(Buffer.from('ICC_PROFILE\0')));
    assert.ok(output.includes(Buffer.from('GainMap')));
    assert.ok(!output.includes(Buffer.from('PRIVATE_')));
    assert.throws(() => sanitizeJpeg(base), /gain-map location/); // A detached HDR base must not silently become SDR.
    // Decode-independent comparison: the original entropy streams and coding tables remain identical.
    const scan = image => image.subarray(image.indexOf(Buffer.from([255, 218])));
    assert.deepEqual(scan(base), scan(baseJpeg));
    assert.deepEqual(scan(gain), scan(gainJpeg));
});

test('MPF offsets and GContainer lengths point at the sanitized gain image', async () => {
    const output = await sanitized(hdrFixture());
    const [base, gain] = jpegImages(output);
    const tiff = output.indexOf(Buffer.from('MPF\0')) + 4;
    const table = tiff + output.readUInt32LE(tiff + 42);
    assert.equal(output.readUInt32LE(table + 4), base.length);
    assert.equal(output.readUInt32LE(table + 20), gain.length);
    assert.equal(tiff + output.readUInt32LE(table + 24), base.length);
    assert.match(gainMetadata(base), new RegExp(`Item:Length="${gain.length}"`));
    assert.deepEqual(await sanitized(output), output);
});

test('big-endian source indexes and alternate XMP prefixes preserve gain coefficients', async () => {
    const output = await sanitized(hdrFixture({little: false, prefix: 'brightness', values: {GainMapMax: [1, 2, 3], Gamma: [1, 1.2, 1.4]}}));
    const [, gain] = jpegImages(output);
    const document = new DOMParser().parseFromString(gainMetadata(gain), 'application/xml');
    const values = name => Array.from(document.getElementsByTagNameNS('http://ns.adobe.com/hdr-gain-map/1.0/', name)[0]
        .getElementsByTagNameNS('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'li'), item => Number(item.textContent));
    assert.deepEqual(values('GainMapMax'), [1, 2, 3]);
    assert.deepEqual(values('Gamma'), [1, 1.2, 1.4]);
});

test('HDR retains only EXIF orientation, including rotated portrait dimensions', async () => {
    const input = hdrFixture({orientation: 6});
    const candidate = sanitizeHdrJpeg(input);
    assert.equal(candidate.width, 18);
    assert.equal(candidate.height, 32);
    const output = Buffer.from(await candidate.blob.arrayBuffer());
    const exif = output.indexOf(Buffer.from('Exif\0\0'));
    assert.ok(exif > 0);
    assert.equal(output.readUInt16LE(exif + 24), 6);
    assert.ok(!output.includes(Buffer.from('PRIVATE_')));
});

test('ISO gain-map metadata survives alongside Adobe metadata and on its own', async () => {
    for (const adobe of [true, false]) {
        const input = hdrFixture({iso: true, adobe});
        const output = await sanitized(input);
        for (const image of jpegImages(output)) assert.ok(image.includes(Buffer.from('urn:iso:std:iso:ts:21496:-1\0')));
        assert.ok(!output.includes(Buffer.from('PRIVATE_')));
    }
});

test('HDR is detected by JPEG bytes even when the clipboard provides no MIME type', async () => {
    const candidate = await createSanitizedSourceCandidate(new Blob([hdrFixture()]));
    assert.equal(candidate.preserveHdr, true);
    assert.equal(candidate.extension, 'jpg');
    assert.equal(candidate.width, 32);
});

test('missing, truncated or overlapping gain maps fail instead of falling back to SDR', () => {
    const input = hdrFixture();
    assert.throws(() => sanitizeHdrJpeg(input.subarray(0, input.length - 1)), /gain-map location/);
    const overlap = Buffer.from(input);
    const tiff = overlap.indexOf(Buffer.from('MPF\0')) + 4;
    overlap.writeUInt32LE(0, tiff + 74);
    assert.throws(() => sanitizeHdrJpeg(overlap), /gain-map location/);
    const missing = Buffer.from(input);
    missing.write('NOPE', missing.indexOf(Buffer.from('MPF\0')));
    assert.throws(() => sanitizeHdrJpeg(missing), /index is missing/);
});

test('invalid HDR coefficients are not silently removed or normalized', () => {
    for (const values of [{Gamma: '0'}, {GainMapMax: '-1'}, {HDRCapacityMax: '0'}, {GainMapMax: 'NaN'}, {Version: '2.0'}, {OffsetSDR: '-1'}]) {
        assert.throws(() => sanitizeHdrJpeg(hdrFixture({values})), /HDR JPEG/);
    }
});

test('HDR follows the ordinary Retina size policy and applies decoder limits to both layers', () => {
    assert.deepEqual(planHdrImageDimensions(4096, 2304, 2048, 1152), {
        retina: true, targetWidth: 2000, targetHeight: 1125, displayWidth: 1000, displayHeight: 562, resized: true,
    });
    assert.equal(planHdrImageDimensions(6000, 4000, 1500, 1000).targetWidth, 2000);
    assert.throws(() => planHdrImageDimensions(6000, 4001, 1500, 1000), /too large/);
    assert.throws(() => planHdrImageDimensions(32, 18, 32768, 1), /too large/);
    assert.throws(() => planHdrImageDimensions(2000, 4001, 500, 1000), /too large/);
    assert.equal(sanitizeHdrJpeg(baseJpeg), null);
});

test('the public optimizer uses its real HDR codec without an SDR canvas and keeps compact sources', async (context) => {
    const oldImage = globalThis.Image;
    const oldDocument = globalThis.document;
    const oldWorker = globalThis.Worker;
    context.after(() => {
        globalThis.Image = oldImage;
        globalThis.document = oldDocument;
        globalThis.Worker = oldWorker;
    });
    globalThis.Image = class { constructor() { throw new Error('HDR must not be decoded to SDR'); } };
    globalThis.document = {createElement() { throw new Error('HDR must not allocate an SDR canvas'); }};
    globalThis.Worker = CodecWorker;
    const result = await optimizeImage(new Blob([hdrFixture()], {type: 'image/jpeg'}));
    assert.equal(result.preserveHdr, true);
    assert.equal(result.selectedType, 'original-hdr');
    assert.equal(result.resized, false);
    assert.equal(result.width, 32);
    assert.ok(Buffer.from(await result.blob.arrayBuffer()).includes(Buffer.from('GainMap')));
});

test('a large HDR upload is recompressed to the normal Retina dimensions with a complete gain map', async (context) => {
    const oldWorker = globalThis.Worker;
    context.after(() => { globalThis.Worker = oldWorker; });
    globalThis.Worker = CodecWorker;
    const input = await readFile(new URL('../_resources/image-optimizer/hdr-wide.jpg', import.meta.url));
    const result = await optimizeImage(new Blob([input], {type: 'image/jpeg'}));
    assert.equal(result.selectedType, 'hdr-jpeg');
    assert.equal(result.resized, true);
    assert.deepEqual([result.width, result.height, result.displayWidth, result.displayHeight], [2000, 1125, 1000, 562]);
    assert.ok(result.blob.size < input.length);
    assert.ok(result.ssim >= 0.97);
    const output = Buffer.from(await result.blob.arrayBuffer());
    const hdr = sanitizeHdrJpeg(output);
    assert.deepEqual([hdr.width, hdr.height, hdr.gainWidth, hdr.gainHeight], [2000, 1125, 333, 187]);
    assert.ok(output.includes(Buffer.from('ICC_PROFILE\0')));
    assert.ok(output.includes(Buffer.from('urn:iso:std:iso:ts:21496:-1\0')));
    assert.ok(!output.includes(Buffer.from('PRIVATE_')));
    assert.ok(!output.includes(Buffer.from('Exif\0\0')));
});

test('rotation is applied to HDR pixels before encoding and private EXIF is removed', async (context) => {
    const oldWorker = globalThis.Worker;
    context.after(() => { globalThis.Worker = oldWorker; });
    globalThis.Worker = CodecWorker;
    const result = await optimizeImage(new Blob([hdrFixture({orientation: 6})], {type: 'image/jpeg'}));
    assert.equal(result.selectedType, 'hdr-jpeg');
    assert.deepEqual([result.width, result.height], [18, 32]);
    const output = Buffer.from(await result.blob.arrayBuffer());
    assert.equal(sanitizeHdrJpeg(output).orientation, 1);
    assert.ok(!output.includes(Buffer.from('Exif\0\0')));
    assert.ok(result.ssim >= 0.985);
});

test('the actual HDR codec reads ISO-only and Adobe RGB gain-map coefficients', async (context) => {
    const oldWorker = globalThis.Worker;
    context.after(() => { globalThis.Worker = oldWorker; });
    globalThis.Worker = CodecWorker;
    for (const input of [hdrFixture({iso: true, adobe: false}),
        hdrFixture({values: {GainMapMax: [2, 2.5, 3], Gamma: [1, 1.1, 1.2]}})]) {
        const result = await optimizeImage(new Blob([input], {type: 'image/jpeg'}));
        assert.equal(result.preserveHdr, true);
        assert.ok(result.ssim >= 0.985);
        assert.ok(sanitizeHdrJpeg(new Uint8Array(await result.blob.arrayBuffer())));
    }
});

test('re-encoding a Display P3 HDR input keeps its gamut and brightness through rotation', async (context) => {
    const oldWorker = globalThis.Worker;
    context.after(() => { globalThis.Worker = oldWorker; });
    globalThis.Worker = CodecWorker;
    const base = await readFile(new URL('../_resources/image-optimizer/hdr-base-p3.jpg', import.meta.url));
    const input = hdrFixture({base, orientation: 6});
    const result = await optimizeImage(new Blob([input], {type: 'image/jpeg'}));
    assert.equal(result.selectedType, 'hdr-jpeg');
    assert.ok(result.ssim >= 0.985);
    const output = Buffer.from(await result.blob.arrayBuffer());
    assert.ok(output.includes(Buffer.from('Display P3 Gamut with sRGB Transfer', 'utf16le').swap16()));
    assert.ok(sanitizeHdrJpeg(output));
});

test('cancelling an HDR optimization terminates the worker and never flattens the file', async (context) => {
    const oldWorker = globalThis.Worker;
    context.after(() => { globalThis.Worker = oldWorker; });
    const controller = new AbortController();
    let terminated = false;
    globalThis.Worker = class {
        postMessage() { controller.abort(); }
        terminate() { terminated = true; }
    };
    await assert.rejects(optimizeImage(new Blob([hdrFixture()]), {signal: controller.signal}), {name: 'AbortError'});
    assert.equal(terminated, true);
});

test('a codec download failure stays retryable and never falls back to an SDR codec', async (context) => {
    const oldWorker = globalThis.Worker;
    const oldDocument = globalThis.document;
    context.after(() => { globalThis.Worker = oldWorker; globalThis.document = oldDocument; });
    let workers = 0;
    globalThis.document = {createElement() { throw new Error('A failed HDR codec must not use SDR'); }};
    globalThis.Worker = class {
        constructor() { workers++; }
        postMessage() { queueMicrotask(() => this.onmessage({data: {type: 'error', message: 'Codec download failed'}})); }
        terminate() {}
    };
    for (let attempt = 0; attempt < 2; attempt++) {
        await assert.rejects(optimizeImage(new Blob([hdrFixture()])), /Codec download failed/);
    }
    assert.equal(workers, 2);
});

test('release minification preserves the HDR worker, factory and native codec exports', async (context) => {
    const root = await mkdtemp(resolve(tmpdir(), 'register-hdr-minified-'));
    context.after(() => rm(root, {recursive: true, force: true}));
    await mkdir(resolve(root, 'js'));
    await mkdir(resolve(root, 'lib'));
    const directory = new URL('../../_assets/register/image-optimizer/', import.meta.url);
    for (const path of ['js/hdr-optimize-worker.js', 'lib/register-ultrahdr.js']) {
        await writeFile(resolve(root, path), await minifyAsset(path, await readFile(new URL(path, directory))));
    }
    await copyFile(new URL('lib/register-ultrahdr.wasm', directory), resolve(root, 'lib/register-ultrahdr.wasm'));
    const worker = new CodecWorker(pathToFileURL(resolve(root, 'js/hdr-optimize-worker.js')).href);
    context.after(() => worker.terminate());
    const data = await sanitizeHdrJpeg(hdrFixture()).blob.arrayBuffer();
    const result = await new Promise((resolve, reject) => {
        worker.onerror = reject;
        worker.onmessage = ({data: message}) => {
            if (message.type === 'error') reject(new Error(message.message));
            if (message.type === 'optimized') resolve(message);
        };
        worker.postMessage({data, width: 32, height: 18, orientation: 1,
            minQuality: 75, maxQuality: 95, minSsim: 0.985, searchSteps: 6}, [data]);
    });
    assert.ok(result.ssim >= 0.985);
    assert.ok(sanitizeHdrJpeg(new Uint8Array(result.data)));
});
