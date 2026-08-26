/** Visible-pixel SSIM worker for editor image candidates. */

self.window = self;

try {
    importScripts('../lib/image-q.min.js');
} catch (error) {
    self.postMessage({type: 'init-error', message: error?.message || 'Failed to load image-q.'});
}

const imageQ = self['image-q'];
const sessions = new Map();

function toLumaRgba(data, background) {
    const output = new Uint8ClampedArray(data.length);
    for (let index = 0; index < data.length; index += 4) {
        const alpha = data[index + 3] / 255;
        const red = data[index] * alpha + background * (1 - alpha);
        const green = data[index + 1] * alpha + background * (1 - alpha);
        const blue = data[index + 2] * alpha + background * (1 - alpha);
        const luma = Math.round(0.2126 * red + 0.7152 * green + 0.0722 * blue);
        output[index] = luma;
        output[index + 1] = luma;
        output[index + 2] = luma;
        output[index + 3] = 255;
    }
    return output;
}

function calculateSsim(reference, candidate, width, height) {
    function score(background) {
        const referenceLuma = toLumaRgba(reference, background);
        const candidateLuma = toLumaRgba(candidate, background);
        const referenceContainer = imageQ.utils.PointContainer.fromUint8Array(referenceLuma, width, height);
        const candidateContainer = imageQ.utils.PointContainer.fromUint8Array(candidateLuma, width, height);
        return imageQ.quality.ssim(referenceContainer, candidateContainer);
    }
    return Math.min(score(0), score(255));
}

async function decodeCandidate(blob, width, height) {
    const bitmap = await createImageBitmap(blob, {
        colorSpaceConversion: 'default',
        imageOrientation: 'from-image'
    });
    try {
        if (bitmap.width !== width || bitmap.height !== height) {
            throw new Error('The candidate dimensions changed during encoding.');
        }
        const canvas = new OffscreenCanvas(width, height);
        let context = null;
        try {
            context = canvas.getContext('2d', {colorSpace: 'srgb', willReadFrequently: true});
        } catch (error) {
            context = null;
        }
        context = context || canvas.getContext('2d', {willReadFrequently: true});
        if (!context) {
            throw new Error('Unable to create an offscreen sRGB canvas.');
        }
        context.drawImage(bitmap, 0, 0);
        try {
            return context.getImageData(0, 0, width, height, {colorSpace: 'srgb'}).data;
        } catch (error) {
            return context.getImageData(0, 0, width, height).data;
        }
    } finally {
        bitmap.close();
    }
}

self.onmessage = async function (event) {
    const message = event.data || {};
    try {
        if (message.type === 'init') {
            sessions.set(message.sessionId, {
                data: new Uint8ClampedArray(message.data),
                width: message.width,
                height: message.height
            });
            self.postMessage({type: 'initialized', id: message.id});
            return;
        }
        if (message.type === 'release') {
            sessions.delete(message.sessionId);
            return;
        }
        if (message.type !== 'score') {
            return;
        }
        const session = sessions.get(message.sessionId);
        if (!session) {
            throw new Error('The image-quality session has expired.');
        }
        const candidate = await decodeCandidate(message.blob, session.width, session.height);
        const score = calculateSsim(session.data, candidate, session.width, session.height);
        self.postMessage({type: 'scored', id: message.id, score: score});
    } catch (error) {
        self.postMessage({
            type: 'error',
            id: message.id,
            message: error?.message || 'Image quality analysis failed.'
        });
    }
};

if (imageQ && typeof createImageBitmap === 'function' && typeof OffscreenCanvas === 'function') {
    self.postMessage({type: 'ready'});
} else {
    self.postMessage({type: 'init-error', message: 'Offscreen image quality analysis is unavailable.'});
}
