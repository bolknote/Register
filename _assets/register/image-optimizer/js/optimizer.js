/**
 * Automatic optimization for post images, preserving authored SVG and GIF animation.
 *
 * @copyright 2026 Register contributors
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

import {runOptipng} from './png-optimize.js';
import {
    computeCandidateSsimScore,
    decodeImageToSrgb,
    findJpegCandidateForSsim,
    fileToImage,
    imageDataToBlob,
} from './image-utils.js';
import {resizeImageDataLinear} from './resize.js';
import {createImageQualityScorer} from './image-quality.js';
import {createWebpEncoder} from './webp-encode.js';
import {createSanitizedSourceCandidate, sanitizeGif} from './source-candidate.js';
import {optimizeHdrImage} from './hdr-optimize.js';
import {
    chooseBestCandidate,
    extensionForCandidate,
    imagePolicy,
    planImageDimensions,
    planHdrImageDimensions,
    webpEncodeOptions,
} from './policy.js';

function throwIfAborted(signal) {
    if (signal?.aborted) {
        throw new DOMException('The image optimization was cancelled.', 'AbortError');
    }
}

function report(onProgress, stage) {
    if (typeof onProgress === 'function') {
        onProgress(stage);
    }
}

function analyzePixels(imageData) {
    let hasAlpha = false;
    for (let index = 3; index < imageData.data.length; index += 4) {
        if (imageData.data[index] < 255) {
            hasAlpha = true;
            break;
        }
    }

    return {
        data: imageData.data,
        width: imageData.width,
        height: imageData.height,
        hasAlpha,
    };
}

function optimizePng(pngSource, options) {
    return new Promise((resolve) => {
        runOptipng(pngSource, (blob, metadata) => {
            resolve({blob: blob || pngSource, metadata: metadata || null});
        }, options);
    });
}

async function buildPng24Candidate(candidates, pngSource) {
    const result = await optimizePng(pngSource, {
        quantize: false,
        optLevel: imagePolicy.png24OptLevel,
    });
    const candidate = {type: 'png24', blob: result.blob, size: result.blob.size, ssim: 1};
    candidates.png24 = candidate;
    return candidate;
}

async function buildPng8Candidate(candidates, pngSource, scoreCandidate) {
    const result = await optimizePng(pngSource, {
        quantize: true,
        minPsnr: imagePolicy.png8MinPsnr,
        optLevel: imagePolicy.png8OptLevel,
        requireQuantized: true,
    });
    const quant = result.metadata?.quantResult;
    if (!quant?.accepted || !result.blob || result.blob === pngSource) {
        candidates.png8 = false;
        return null;
    }

    const score = await scoreCandidate(result.blob);
    const candidate = {
        type: 'png8',
        blob: result.blob,
        size: result.blob.size,
        ssim: score.score,
        psnr: quant.psnr,
    };
    candidates.png8 = candidate;
    return candidate;
}

async function buildJpegCandidate(candidates, pngSource, analysis, minSsim, scoreCandidate) {
    if (analysis.hasAlpha) {
        candidates.jpeg = false;
        return null;
    }

    const candidate = await findJpegCandidateForSsim(
        pngSource,
        analysis,
        {
            jpegQuality: imagePolicy.jpegQuality,
            jpegMinQuality: imagePolicy.jpegMinQuality,
            jpegMinSsim: minSsim,
            jpegQualitySearchSteps: imagePolicy.jpegQualitySearchSteps,
        },
        '#ffffff',
        false,
        null,
        scoreCandidate,
    );
    if (!candidate?.blob) {
        candidates.jpeg = false;
        return null;
    }

    const normalized = {
        ...candidate,
        type: 'jpeg',
        quality: candidate.quality * 100,
        minSsim,
    };
    candidates.jpeg = normalized;
    return normalized;
}

async function buildWebpCandidates(candidates, imageData, scoreCandidate, includeLossless) {
    let encoder = null;
    try {
        encoder = await createWebpEncoder(imageData);
        const tasks = [
            encoder.encode(webpEncodeOptions(imagePolicy.webpQuality, false)).then(async (blob) => {
                const score = await scoreCandidate(blob);
                const candidate = {
                    type: 'webp',
                    blob,
                    size: blob.size,
                    ssim: score.score,
                    quality: imagePolicy.webpQuality,
                };
                candidates.webp = candidate;
                return candidate;
            }),
        ];
        if (includeLossless) {
            tasks.push(encoder.encode(webpEncodeOptions(100, true)).then((blob) => {
                const candidate = {
                    type: 'webp-lossless',
                    blob,
                    size: blob.size,
                    ssim: 1,
                };
                candidates.webpLossless = candidate;
                return candidate;
            }));
        }

        const results = await Promise.allSettled(tasks);
        if (results[0].status === 'rejected') {
            candidates.webp = false;
        }
        if (includeLossless && results[1].status === 'rejected') {
            candidates.webpLossless = false;
        }
    } catch (error) {
        console.warn('WebP encoder unavailable:', error);
        candidates.webp = false;
        if (includeLossless) {
            candidates.webpLossless = false;
        }
    } finally {
        encoder?.close();
    }
}

async function buildWebpLosslessCandidate(candidates, imageData) {
    let encoder = null;
    try {
        encoder = await createWebpEncoder(imageData);
        const blob = await encoder.encode(webpEncodeOptions(100, true));
        const candidate = {type: 'webp-lossless', blob, size: blob.size, ssim: 1};
        candidates.webpLossless = candidate;
        return candidate;
    } catch (error) {
        console.warn('Lossless WebP encoder unavailable:', error);
        candidates.webpLossless = false;
        return null;
    } finally {
        encoder?.close();
    }
}

function candidateSummary(candidates) {
    const summary = {};
    Object.entries(candidates).forEach(([name, candidate]) => {
        summary[name] = candidate ? {
            size: candidate.size,
            ssim: candidate.ssim,
            psnr: candidate.psnr,
            quality: candidate.quality,
        } : null;
    });
    return summary;
}

async function originalSvgImage(file, signal) {
    const bytes = new Uint8Array(await file.arrayBuffer());
    let encoding;
    if ((bytes[0] === 0xff && bytes[1] === 0xfe) || (bytes[0] === 0x3c && bytes[1] === 0)) encoding = 'utf-16le';
    else if ((bytes[0] === 0xfe && bytes[1] === 0xff) || (bytes[0] === 0 && bytes[1] === 0x3c)) encoding = 'utf-16be';
    else encoding = new TextDecoder().decode(bytes.subarray(0, 256)).match(/<\?xml\s[^?]*encoding\s*=\s*["']([^"']+)["']/iu)?.[1] || 'utf-8';
    const source = new TextDecoder(encoding, {fatal: true}).decode(bytes);
    const document = new DOMParser().parseFromString(source, 'image/svg+xml');
    const root = document.documentElement;
    if (root?.localName !== 'svg' || root.namespaceURI !== 'http://www.w3.org/2000/svg'
        || document.getElementsByTagNameNS('http://www.mozilla.org/newlayout/xml/parsererror.xml', 'parsererror').length) {
        throw new Error('The SVG document is invalid.');
    }
    const length = value => {
        const match = value.trim().match(/^([+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?)(px|in|cm|mm|q|pt|pc)?$/iu);
        const factors = {in: 96, cm: 96 / 2.54, mm: 96 / 25.4, q: 96 / 101.6, pt: 96 / 72, pc: 16};
        return match ? Number(match[1]) * (factors[match[2]?.toLowerCase()] ?? 1) : null;
    };
    let width = length(root.getAttribute('width') || '');
    let height = length(root.getAttribute('height') || '');
    if ((width !== null && width <= 0) || (height !== null && height <= 0)) {
        throw new Error('The SVG image dimensions are invalid.');
    }
    if (root.hasAttribute('viewBox')) {
        const parts = root.getAttribute('viewBox').trim().split(/[\s,]+/u);
        const values = parts.map(Number);
        if (parts.length !== 4 || parts.some(value => !/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/iu.test(value)) || values.some(value => !Number.isFinite(value))
            || values[2] <= 0 || values[3] <= 0) throw new Error('The SVG viewBox is invalid.');
        if (width === null && height === null) [width, height] = [values[2], values[3]];
        else if (width === null) width = height * values[2] / values[3];
        else if (height === null) height = width * values[3] / values[2];
    }
    width = Math.max(1, Math.round(width ?? 300));
    height = Math.max(1, Math.round(height ?? 150));
    if (!Number.isSafeInteger(width) || !Number.isSafeInteger(height) || width > 32767 || height > 32767) {
        throw new Error('The SVG image dimensions are too large.');
    }
    throwIfAborted(signal);
    return {
        blob: file.type === 'image/svg+xml' ? file : new Blob([file], {type: 'image/svg+xml'}),
        extension: 'svg', mimeType: 'image/svg+xml', width, height,
        displayWidth: width, displayHeight: height,
        retina: false, resized: false, selectedType: 'original',
    };
}

export async function optimizeImage(file, {signal = null, onProgress = null} = {}) {
    if (!(file instanceof Blob) || file.size <= 0) {
        throw new Error('No image file was provided.');
    }
    throwIfAborted(signal);
    report(onProgress, 'decoding');
    if (String(file.type || '').toLowerCase() === 'image/svg+xml' || /\.svg$/iu.test(file.name || '')) {
        const result = await originalSvgImage(file, signal);
        report(onProgress, 'ready');
        throwIfAborted(signal);
        return result;
    }
    const signature = String.fromCharCode(...new Uint8Array(await file.slice(0, 6).arrayBuffer()));
    const isGif = signature === 'GIF87a' || signature === 'GIF89a';
    if (isGif || String(file.type || '').toLowerCase() === 'image/gif') {
        if (!isGif) throw new Error('The GIF file is invalid.');
        // Canvas encoders only retain one GIF frame. Remove private metadata at
        // the container level, preserving compressed frames, timing and color.
        // Keep natural dimensions: an unchanged file is neither resized nor @2x.
        const blob = sanitizeGif(new Uint8Array(await file.arrayBuffer()));
        const image = await fileToImage(blob);
        const width = image.naturalWidth;
        const height = image.naturalHeight;
        planImageDimensions(width, height);
        if (width * height > imagePolicy.maxOutputPixels) {
            throw new Error('The image dimensions are too large to process safely in the browser.');
        }
        throwIfAborted(signal);
        report(onProgress, 'ready');
        return {
            blob, extension: 'gif', mimeType: 'image/gif', width, height,
            displayWidth: width, displayHeight: height,
            retina: false, resized: false, selectedType: 'original',
        };
    }
    throwIfAborted(signal);
    const sourceCandidate = await createSanitizedSourceCandidate(file);
    if (sourceCandidate?.preserveHdr) {
        const plan = planHdrImageDimensions(sourceCandidate.width, sourceCandidate.height,
            sourceCandidate.gainWidth, sourceCandidate.gainHeight);
        const result = await optimizeHdrImage(sourceCandidate, plan, {signal, policy: imagePolicy, onProgress});
        throwIfAborted(signal);
        report(onProgress, 'ready');
        return {
            ...result, extension: 'jpg', mimeType: 'image/jpeg',
            width: plan.targetWidth, height: plan.targetHeight,
            displayWidth: plan.displayWidth, displayHeight: plan.displayHeight,
            retina: plan.retina, resized: plan.resized, preserveHdr: true,
        };
    }
    const decoded = await decodeImageToSrgb(file, imagePolicy);
    const plan = planImageDimensions(decoded.width, decoded.height);
    throwIfAborted(signal);

    let pixels = decoded.imageData;
    if (plan.resized) {
        report(onProgress, 'resizing');
        pixels = await resizeImageDataLinear(pixels, plan.targetWidth, plan.targetHeight);
        throwIfAborted(signal);
    }

    const analysis = analyzePixels(pixels);
    const candidates = {
        original: plan.resized || !sourceCandidate ? false : sourceCandidate,
        webp: null,
        webpLossless: null,
        jpeg: null,
        png8: null,
        png24: null,
    };
    const pngSource = await imageDataToBlob(pixels, 'image/png');
    let qualityScorer = null;
    try {
        qualityScorer = await createImageQualityScorer(pixels);
    } catch (error) {
        console.warn('Offscreen image quality analysis unavailable:', error);
    }
    const scoreCandidate = qualityScorer
        ? qualityScorer.score
        : (blob) => computeCandidateSsimScore(blob, analysis, imagePolicy);

    try {
        report(onProgress, 'comparing');
        const includeLosslessInitially = analysis.hasAlpha || file.type === 'image/png';
        const minSsim = plan.retina ? imagePolicy.retinaMinSsim : imagePolicy.jpegMinSsim;
        const primaryTasks = [
            buildWebpCandidates(candidates, pixels, scoreCandidate, includeLosslessInitially),
            buildJpegCandidate(candidates, pngSource, analysis, minSsim, scoreCandidate),
            buildPng8Candidate(candidates, pngSource, scoreCandidate),
        ];
        if (includeLosslessInitially) {
            primaryTasks.push(buildPng24Candidate(candidates, pngSource));
        }
        await Promise.allSettled(primaryTasks);
        throwIfAborted(signal);

        if (!chooseBestCandidate(candidates, analysis.hasAlpha)) {
            await Promise.allSettled([
                candidates.webpLossless === null
                    ? buildWebpLosslessCandidate(candidates, pixels)
                    : Promise.resolve(),
                candidates.png24 === null
                    ? buildPng24Candidate(candidates, pngSource)
                    : Promise.resolve(),
            ]);
        }
        if (candidates.webpLossless === null) {
            candidates.webpLossless = false;
        }
        if (candidates.png24 === null) {
            candidates.png24 = false;
        }

        const selected = chooseBestCandidate(candidates, analysis.hasAlpha);
        if (!selected?.blob) {
            throw new Error('No safe image candidate passed the quality threshold.');
        }
        const extension = extensionForCandidate(selected);
        const result = {
            blob: selected.blob,
            extension,
            mimeType: extension === 'jpg' ? 'image/jpeg' : `image/${extension}`,
            width: plan.targetWidth,
            height: plan.targetHeight,
            displayWidth: plan.displayWidth,
            displayHeight: plan.displayHeight,
            retina: plan.retina,
            resized: plan.resized,
            selectedType: selected.type,
        };

        console.info('Automatic image optimization summary', JSON.stringify({
            original: {width: decoded.width, height: decoded.height, size: file.size},
            output: {
                width: result.width,
                height: result.height,
                displayWidth: result.displayWidth,
                displayHeight: result.displayHeight,
                size: result.blob.size,
                resized: result.resized,
            },
            retina: result.retina,
            selected: result.selectedType,
            candidates: candidateSummary(candidates),
        }));

        report(onProgress, 'ready');
        return result;
    } finally {
        qualityScorer?.close();
    }
}
