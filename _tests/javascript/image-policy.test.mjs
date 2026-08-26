import test, {afterEach} from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

const policySource = await readFile(
    new URL('../../_admin/js/editor/images/state.js', import.meta.url),
    'utf8'
);
const {
    chooseBestCandidate,
    effectivePublicationDate,
    extensionForCandidate,
    imageState,
    planImageDimensions,
    webpEncodeOptions
} = await import('data:text/javascript;base64,' + Buffer.from(policySource).toString('base64'));

afterEach(function () {
    imageState.editorForm = null;
    imageState.defaultPublicationDate = '';
});

test('2000px source becomes the only Retina output without upscaling', function () {
    assert.deepEqual(planImageDimensions(3000, 2001), {
        retina: true,
        targetWidth: 2000,
        targetHeight: 1334,
        displayWidth: 1000,
        displayHeight: 667,
        resized: true
    });
    assert.deepEqual(planImageDimensions(2000, 1125), {
        retina: true,
        targetWidth: 2000,
        targetHeight: 1125,
        displayWidth: 1000,
        displayHeight: 562,
        resized: false
    });
});

test('sub-2000px source stays at its natural dimensions', function () {
    assert.deepEqual(planImageDimensions(1999, 733), {
        retina: false,
        targetWidth: 1999,
        targetHeight: 733,
        displayWidth: 1999,
        displayHeight: 733,
        resized: false
    });
});

test('unsafe decoded and output dimensions are rejected', function () {
    assert.throws(
        () => planImageDimensions(10000, 8001),
        /too large to optimize safely/
    );
    assert.throws(
        () => planImageDimensions(2000, 11000),
        /too large to process safely/
    );
});

test('publication date comes from the active note field', function () {
    imageState.defaultPublicationDate = '2026-08-26';
    imageState.editorForm = {
        querySelector: function () {
            return {value: 'scheduled'};
        },
        elements: {
            namedItem: function (name) {
                return {value: name === 'scheduled_at' ? '1991-01-30T12:45' : '2040-12-01T00:00'};
            }
        }
    };
    assert.equal(effectivePublicationDate(), '1991-01-30');
});

test('smallest candidate wins while JPEG still respects its quality gate', function () {
    const blob = {};
    const selected = chooseBestCandidate({
        jpeg: {type: 'jpeg', blob: blob, size: 80, ssim: 0.99},
        webp: {type: 'webp', blob: blob, size: 90, ssim: 0.97},
        png24: {type: 'png24', blob: blob, size: 120}
    }, false);
    assert.equal(selected.type, 'jpeg');
    assert.equal(extensionForCandidate(selected), 'jpg');
});

test('sanitized source prevents an already efficient image from growing', function () {
    const blob = {};
    const selected = chooseBestCandidate({
        original: {type: 'original', extension: 'jpg', blob: blob, size: 60, ssim: 1},
        jpeg: {type: 'jpeg', blob: blob, size: 80, ssim: 0.99},
        webp: {type: 'webp', blob: blob, size: 70, ssim: 0.99}
    }, false);
    assert.equal(selected.type, 'original');
    assert.equal(extensionForCandidate(selected), 'jpg');
});

test('transparent content can choose WebP but never JPEG', function () {
    const blob = {};
    const selected = chooseBestCandidate({
        jpeg: {type: 'jpeg', blob: blob, size: 10, ssim: 1},
        webp: {type: 'webp', blob: blob, size: 50, ssim: 0.99},
        png24: {type: 'png24', blob: blob, size: 80}
    }, true);
    assert.equal(selected.type, 'webp');
    assert.equal(extensionForCandidate(selected), 'webp');
});

test('Retina candidates retain the historical 0.97 quality threshold', function () {
    const blob = {};
    const selected = chooseBestCandidate({
        webp: {type: 'webp', blob: blob, size: 80, ssim: 0.95},
        jpeg: {type: 'jpeg', blob: blob, size: 60, ssim: 0.975, minSsim: 0.97}
    }, false);
    assert.equal(selected.type, 'jpeg');
});

test('WebP keeps sharp YUV but does not confuse exact with gamma correction', function () {
    assert.deepEqual(webpEncodeOptions(82, false), {
        quality: 82,
        lossless: false,
        method: 6,
        alphaQuality: 100,
        nearLossless: 100,
        sharpYuv: true,
        exact: false
    });
    assert.equal(webpEncodeOptions(82, false, 4).method, 4);
});
