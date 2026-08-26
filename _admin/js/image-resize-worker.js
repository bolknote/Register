/** Linear-sRGB Lanczos3 resize worker for the editor image pipeline. */

import initResize, {resize as resizePixels} from '../lib/register-resize.js';

const ready = initResize(new URL('../lib/register-resize.wasm', import.meta.url));

self.onmessage = async function (event) {
    const message = event.data || {};
    if (message.type !== 'resize') {
        return;
    }

    try {
        await ready;
        const input = new Uint8Array(message.data);
        const output = resizePixels(
            input,
            message.width,
            message.height,
            message.targetWidth,
            message.targetHeight,
            3,
            true,
            true
        );
        const buffer = output.buffer.slice(output.byteOffset, output.byteOffset + output.byteLength);
        self.postMessage({type: 'done', id: message.id, data: buffer}, [buffer]);
    } catch (error) {
        self.postMessage({
            type: 'error',
            id: message.id,
            message: error && error.message ? error.message : 'Image resize failed.'
        });
    }
};

self.postMessage({type: 'ready'});
