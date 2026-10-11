/** libultrahdr 1.5.0 preserves HDR through linear-light resize and JPEG quality search. */

self.onmessage = async function (event) {
    const message = event.data || {};
    let module;
    let input = 0;
    let output = 0;
    let metadata = 0;
    try {
        importScripts('../lib/register-ultrahdr.js');
        module = await self.RegisterUltraHdrModule({
            locateFile: path => new URL('../lib/' + path, self.location.href).toString(),
        });
        const bytes = new Uint8Array(message.data);
        input = module._malloc(bytes.byteLength);
        metadata = module._malloc(12);
        if (!input || !metadata) throw new Error('Unable to allocate HDR image memory.');
        module.HEAPU8.set(bytes, input);
        module.HEAPU8.fill(0, metadata, metadata + 12);
        self.postMessage({type: 'progress', stage: 'encoding'});
        output = module._register_hdr_optimize(input, bytes.byteLength, message.orientation,
            message.width, message.height, message.minQuality, message.maxQuality,
            message.minSsim, message.searchSteps, metadata, metadata + 4, metadata + 8);
        const size = module.HEAPU32[metadata >>> 2];
        if (!output || !size) throw new Error(module.UTF8ToString(module._register_hdr_error()) || 'HDR encoding failed.');
        const data = module.HEAPU8.slice(output, output + size).buffer;
        self.postMessage({type: 'optimized', data, width: message.width, height: message.height,
            ssim: module.HEAPF32[(metadata + 4) >>> 2], quality: module.HEAPU32[(metadata + 8) >>> 2]}, [data]);
    } catch (error) {
        self.postMessage({type: 'error', message: error?.message || 'HDR image optimization failed.'});
    } finally {
        if (module) {
            if (input) module._free(input);
            if (output) module._free(output);
            if (metadata) module._free(metadata);
        }
    }
};
