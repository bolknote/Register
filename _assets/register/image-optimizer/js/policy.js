/**
 * Deterministic policy for the public post image optimizer.
 *
 * @copyright 2025-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

const imagePolicy = Object.freeze({
    retinaSourceWidth: 2000,
    maxDecodedPixels: 80 * 1000 * 1000,
    maxOutputPixels: 20 * 1000 * 1000,
    maxDecodedEdge: 32767,
    jpegMinSsim: 0.985,
    retinaMinSsim: 0.97,
    png8MinSsim: 0.98,
    png8MinPsnr: 40,
    jpegQuality: 0.95,
    jpegMinQuality: 0.75,
    jpegQualitySearchSteps: 6,
    webpQuality: 82,
    png24OptLevel: 2,
    png8OptLevel: 2,
});

function planImageDimensions(width, height) {
    if (
        !Number.isInteger(width)
        || !Number.isInteger(height)
        || width <= 0
        || height <= 0
        || width > imagePolicy.maxDecodedEdge
        || height > imagePolicy.maxDecodedEdge
        || width * height > imagePolicy.maxDecodedPixels
    ) {
        throw new Error('The image dimensions are too large to optimize safely in the browser.');
    }

    const retina = width >= imagePolicy.retinaSourceWidth;
    const targetWidth = retina ? imagePolicy.retinaSourceWidth : width;
    const targetHeight = retina
        ? Math.max(1, Math.round(height * targetWidth / width))
        : height;
    if (targetWidth * targetHeight > imagePolicy.maxOutputPixels) {
        throw new Error('The optimized image dimensions are too large to process safely in the browser.');
    }

    return {
        retina,
        targetWidth,
        targetHeight,
        displayWidth: retina ? Math.max(1, Math.floor(targetWidth / 2)) : targetWidth,
        displayHeight: retina ? Math.max(1, Math.floor(targetHeight / 2)) : targetHeight,
        resized: targetWidth !== width || targetHeight !== height,
    };
}

function candidateIsAccepted(candidate, hasAlpha) {
    if (!candidate || !candidate.blob || typeof candidate.size !== 'number') {
        return false;
    }

    switch (candidate.type) {
        case 'jpeg':
            return !hasAlpha && candidate.ssim >= (candidate.minSsim ?? imagePolicy.jpegMinSsim);
        case 'webp':
            return true;
        case 'png8':
            return candidate.ssim >= imagePolicy.png8MinSsim
                && candidate.psnr >= imagePolicy.png8MinPsnr;
        case 'webp-lossless':
        case 'png24':
        case 'original':
            return true;
        default:
            return false;
    }
}

function chooseBestCandidate(candidates, hasAlpha) {
    const accepted = Object.values(candidates || {}).filter((candidate) => (
        candidateIsAccepted(candidate, hasAlpha)
    ));
    accepted.sort((left, right) => left.size - right.size);
    return accepted[0] || null;
}

function extensionForCandidate(candidate) {
    switch (candidate?.type) {
        case 'jpeg':
            return 'jpg';
        case 'png8':
        case 'png24':
            return 'png';
        case 'webp':
        case 'webp-lossless':
            return 'webp';
        case 'original':
            if (['jpg', 'png', 'webp'].includes(candidate.extension)) {
                return candidate.extension;
            }
            break;
        default:
            break;
    }

    throw new Error('Unsupported optimized image format.');
}

function webpEncodeOptions(quality, lossless, method = 6) {
    return {
        quality,
        lossless: Boolean(lossless),
        method,
        alphaQuality: 100,
        nearLossless: 100,
        sharpYuv: true,
        // `exact` preserves invisible RGB below alpha=0; it is unrelated to gamma.
        exact: false,
    };
}

export {
    imagePolicy,
    planImageDimensions,
    candidateIsAccepted,
    chooseBestCandidate,
    extensionForCandidate,
    webpEncodeOptions,
};
