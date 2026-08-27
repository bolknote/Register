/** libwebp 1.6 encoder worker. Pixel sessions avoid copying the source for every quality probe. */

importScripts('../lib/register-webp.js');

const sessions = new Map();
const moduleReady = self.RegisterWebPModule({
    locateFile: function (path) {
        return new URL('../lib/' + path, self.location.href).toString();
    }
});

function releaseSession(module, sessionId) {
    const session = sessions.get(sessionId);
    if (!session) {
        return;
    }
    module._free(session.pointer);
    sessions.delete(sessionId);
}

self.onmessage = async function (event) {
    const message = event.data || {};
    let module;
    try {
        module = await moduleReady;
        if (message.type === 'init') {
            releaseSession(module, message.sessionId);
            const pixels = new Uint8Array(message.data);
            const pointer = module._malloc(pixels.byteLength);
            if (!pointer) {
                throw new Error('Unable to allocate WebP input memory.');
            }
            module.HEAPU8.set(pixels, pointer);
            sessions.set(message.sessionId, {
                pointer: pointer,
                width: message.width,
                height: message.height
            });
            self.postMessage({type: 'initialized', id: message.id, sessionId: message.sessionId});
            return;
        }
        if (message.type === 'release') {
            releaseSession(module, message.sessionId);
            return;
        }
        if (message.type !== 'encode') {
            return;
        }

        const session = sessions.get(message.sessionId);
        if (!session) {
            throw new Error('WebP pixel session is unavailable.');
        }
        const sizePointer = module._malloc(4);
        if (!sizePointer) {
            throw new Error('Unable to allocate WebP output metadata.');
        }
        module.HEAPU32[sizePointer >>> 2] = 0;
        const options = message.options || {};
        const outputPointer = module._register_webp_encode(
            session.pointer,
            session.width,
            session.height,
            Number(options.quality ?? 82),
            options.lossless ? 1 : 0,
            Number(options.method ?? 6),
            Number(options.alphaQuality ?? 100),
            Number(options.nearLossless ?? 100),
            options.sharpYuv === false ? 0 : 1,
            options.exact ? 1 : 0,
            sizePointer
        );
        const outputSize = module.HEAPU32[sizePointer >>> 2];
        module._free(sizePointer);
        if (!outputPointer || !outputSize) {
            throw new Error('libwebp did not produce an image.');
        }

        const output = module.HEAPU8.slice(outputPointer, outputPointer + outputSize);
        module._register_webp_free(outputPointer);
        const buffer = output.buffer.slice(output.byteOffset, output.byteOffset + output.byteLength);
        self.postMessage({
            type: 'encoded',
            id: message.id,
            sessionId: message.sessionId,
            data: buffer,
            quality: options.quality,
            lossless: !!options.lossless
        }, [buffer]);
    } catch (error) {
        self.postMessage({
            type: 'error',
            id: message.id,
            sessionId: message.sessionId,
            message: error && error.message ? error.message : 'WebP encoding failed.'
        });
    }
};

moduleReady.then(function (module) {
    self.postMessage({type: 'ready', version: module._register_webp_version()});
}).catch(function (error) {
    self.postMessage({
        type: 'init-error',
        message: error && error.message ? error.message : 'libwebp initialization failed.'
    });
});
