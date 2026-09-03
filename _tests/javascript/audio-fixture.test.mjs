import test from 'node:test';
import assert from 'node:assert/strict';
import {byteRange, sampleSize} from '../browser/audio-player/server.mjs';

test('the browser fixture implements whole-file, offset, bounded and suffix byte ranges', () => {
    assert.equal(sampleSize, 28800044);
    assert.deepEqual(byteRange(undefined), [0, sampleSize - 1]);
    assert.deepEqual(byteRange('bytes=0-'), [0, sampleSize - 1]);
    assert.deepEqual(byteRange('bytes=28320044-'), [28320044, sampleSize - 1]);
    assert.deepEqual(byteRange('bytes=0-43'), [0, 43]);
    assert.deepEqual(byteRange('bytes=-100'), [sampleSize - 100, sampleSize - 1]);
    assert.deepEqual(byteRange('bytes=100-999999999'), [100, sampleSize - 1]);
    for (const value of ['bytes=-0', 'bytes=44-12', 'bytes=28800044-', 'bytes=-', 'bytes=0-1,3-4', 'bad']) {
        assert.equal(byteRange(value), null, value);
    }
});
