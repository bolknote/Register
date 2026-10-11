/** HDR processing runs in an isolated worker; aborting also releases its native memory. */

const workerUrl = new URL('hdr-optimize-worker.js', import.meta.url).toString();

export async function optimizeHdrImage(source, plan, {signal = null, policy, onProgress = null} = {}) {
    const data = await source.blob.arrayBuffer();
    if (signal?.aborted) throw new DOMException('The image optimization was cancelled.', 'AbortError');
    const worker = new Worker(workerUrl);
    try {
        return await new Promise((resolve, reject) => {
            let settled = false;
            const deadline = setTimeout(() => finish(new Error('HDR image optimization timed out. Please try again.')), 300000);
            const finish = (error, result) => {
                if (settled) return;
                settled = true;
                clearTimeout(deadline);
                signal?.removeEventListener('abort', abort);
                worker.terminate();
                if (error) reject(error);
                else resolve(result);
            };
            const abort = () => finish(new DOMException('The image optimization was cancelled.', 'AbortError'));
            signal?.addEventListener('abort', abort, {once: true});
            worker.onerror = event => finish(new Error(event.message || 'The HDR image worker failed.'));
            worker.onmessage = event => {
                const message = event.data || {};
                if (message.type === 'progress') {
                    try {
                        if (typeof onProgress === 'function') onProgress(message.stage);
                    } catch (error) {
                        finish(error);
                    }
                } else if (message.type === 'error') {
                    finish(new Error(message.message || 'HDR image optimization failed.'));
                } else if (message.type === 'optimized') {
                    const blob = new Blob([message.data], {type: 'image/jpeg'});
                    if (!blob.size || message.width !== plan.targetWidth || message.height !== plan.targetHeight
                        || !Number.isFinite(message.ssim) || message.ssim < (plan.retina ? policy.retinaMinSsim : policy.jpegMinSsim)) {
                        finish(new Error('The HDR image result could not be verified.'));
                        return;
                    }
                    // Already compact HDR sources compete too, but rotated or resized inputs
                    // require normalized pixels before the server validates their dimensions.
                    const original = !plan.resized && source.orientation === 1 && source.size <= blob.size;
                    finish(null, {
                        blob: original ? source.blob : blob,
                        selectedType: original ? 'original-hdr' : 'hdr-jpeg',
                        quality: original ? null : message.quality,
                        ssim: original ? 1 : message.ssim,
                    });
                }
            };
            if (signal?.aborted) { abort(); return; }
            try {
                worker.postMessage({data, width: plan.targetWidth, height: plan.targetHeight,
                    orientation: source.orientation,
                    minQuality: Math.round(policy.jpegMinQuality * 100), maxQuality: Math.round(policy.jpegQuality * 100),
                    minSsim: plan.retina ? policy.retinaMinSsim : policy.jpegMinSsim,
                    searchSteps: policy.jpegQualitySearchSteps}, [data]);
            } catch (error) {
                finish(error);
            }
        });
    } finally {
        worker.terminate();
    }
}
