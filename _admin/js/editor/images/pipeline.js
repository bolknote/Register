/**
 * Automatic, single-output image optimization for the Register editor.
 *
 * @copyright 2024-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

import {runOptipng} from '../../png-optimize-setup.js';
import {
    computeCandidateSsimScore,
    decodeImageToSrgb,
    findJpegCandidateForSsim,
    imageDataToBlob
} from '../../image_utils.js';
import {resizeImageDataLinear} from '../../resize-setup.js';
import {createImageQualityScorer} from '../../image-quality-setup.js';
import {createWebpEncoder} from '../../webp-encode-setup.js';
import {register_codemirror} from '../codemirror.js';
import {editorDeps} from '../deps.js';
import {sanitizeUrlForAttribute} from '../utils/escape.js';
import {
    chooseBestCandidate,
    effectivePublicationDate,
    extensionForCandidate,
    formatDimensionValue,
    imageState,
    logPipelineSummary,
    planImageDimensions,
    webpEncodeOptions
} from './state.js';
import {detachJobOverlay, renderImageOverlay, updateImageJobOverlay} from './overlay.js';
import {createSanitizedSourceCandidate} from './source-candidate.js';

function adminAjaxUrl(action) {
    const baseUrl = typeof editorDeps.sUrl === 'string' && editorDeps.sUrl !== ''
        ? editorDeps.sUrl
        : 'ajax.php?';
    const separator = baseUrl.includes('?')
        ? (baseUrl.endsWith('?') || baseUrl.endsWith('&') ? '' : '&')
        : '?';
    return baseUrl + separator + 'action=' + encodeURIComponent(action);
}

function setJobSrc(job, newSrc) {
    if (job.src && imageState.pasteImageBySrc.get(job.src) === job) {
        imageState.pasteImageBySrc.delete(job.src);
    }
    job.src = newSrc;
    if (newSrc) {
        imageState.pasteImageBySrc.set(newSrc, job);
    }
}

function findImageJobForPreview(img) {
    const src = img?.getAttribute('src');
    return src ? imageState.pasteImageBySrc.get(src) || null : null;
}

function insertImageTag(src, width, height, notifyImageInserted) {
    const safeSrc = sanitizeUrlForAttribute(src);
    document.dispatchEvent(new CustomEvent('insert_tag.register', {
        detail: {
            sStart: '<img src="' + safeSrc + '" width="' + formatDimensionValue(width)
                + '" height="' + formatDimensionValue(height) + '" loading="lazy" alt="',
            sEnd: '" />',
            imageSrc: notifyImageInserted ? safeSrc : null
        }
    }));
}

function replaceImageTagInEditor(oldSrc, newSrc, width, height) {
    if (!oldSrc) {
        return false;
    }
    const safeOld = sanitizeUrlForAttribute(oldSrc);
    const content = register_codemirror.getValue();
    const srcIndex = content.indexOf(safeOld);
    if (srcIndex === -1) {
        return false;
    }
    const start = content.lastIndexOf('<img', srcIndex);
    const end = content.indexOf('>', srcIndex);
    if (start === -1 || end === -1) {
        return false;
    }

    let tag = content.slice(start, end + 1);
    const safeNew = sanitizeUrlForAttribute(newSrc || oldSrc);
    function setAttribute(markup, name, value) {
        const expression = new RegExp(name + '="[^"]*"', 'i');
        if (expression.test(markup)) {
            return markup.replace(expression, name + '="' + value + '"');
        }
        return markup.replace(/<img\s*/i, '<img ' + name + '="' + value + '" ');
    }
    tag = setAttribute(tag, 'src', safeNew);
    tag = setAttribute(tag, 'width', formatDimensionValue(width));
    tag = setAttribute(tag, 'height', formatDimensionValue(height));
    register_codemirror.replaceRangeByIndex(tag, start, end + 1);
    return true;
}

function removeImageTagFromEditor(src) {
    const safeSrc = sanitizeUrlForAttribute(src);
    const content = register_codemirror.getValue();
    const srcIndex = content.indexOf(safeSrc);
    if (srcIndex === -1) {
        return;
    }
    const start = content.lastIndexOf('<img', srcIndex);
    const end = content.indexOf('>', srcIndex);
    if (start !== -1 && end !== -1) {
        register_codemirror.replaceRangeByIndex('', start, end + 1);
    }
}

function updatePreviewImage(oldSrc, newSrc) {
    if (!imageState.lastPreviewWrapper) {
        return;
    }
    imageState.lastPreviewWrapper.querySelectorAll('img').forEach(function (img) {
        if (img.getAttribute('src') === oldSrc) {
            img.setAttribute('src', newSrc);
            img.removeAttribute('aria-busy');
        }
    });
}

function markImageOperation(delta) {
    imageState.activeImageOperations = Math.max(0, imageState.activeImageOperations + delta);
    if (typeof editorDeps.loadingIndicator === 'function') {
        editorDeps.loadingIndicator(imageState.activeImageOperations > 0);
    }
}

function requestPictureCsrfToken(path) {
    if (imageState.pictureFolderCsrfTokens[path]) {
        return Promise.resolve(imageState.pictureFolderCsrfTokens[path]);
    }
    const params = new URLSearchParams();
    params.append('path', path);
    return fetch(adminAjaxUrl('picture_csrf_token'), {method: 'POST', body: params})
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            if (!data?.success) {
                throw new Error(data?.message || 'Unable to fetch the image-folder token.');
            }
            imageState.pictureFolderCsrfTokens[path] = data.csrf_token;
            return data.csrf_token;
        });
}

function reserveCanonicalImage(job, extension) {
    return requestPictureCsrfToken(job.dir).then(function (csrfToken) {
        const params = new URLSearchParams();
        params.append('publication_date', job.publicationDate);
        params.append('extension', extension);
        params.append('retina', job.retina ? '1' : '0');
        params.append('csrf_token', csrfToken);
        return fetch(adminAjaxUrl('reserve_editor_image'), {method: 'POST', body: params});
    }).then(function (response) {
        return response.json();
    }).then(function (data) {
        if (
            !data?.success
            || !data.file_path
            || typeof data.dir !== 'string'
            || !data.name
            || !data.token
        ) {
            throw new Error(data?.message || 'Unable to reserve the image name.');
        }
        // Reserving may create a previously absent directory, changing the inode-bound CSRF token.
        delete imageState.pictureFolderCsrfTokens[data.dir];
        return data;
    });
}

export function uploadBlobToPictureDir(blob, name, extension, dir, token) {
    const now = new Date();
    const uploadDir = typeof dir === 'string'
        ? dir
        : '/' + now.getFullYear() + '/' + String(now.getMonth() + 1).padStart(2, '0');
    const uploadName = typeof name === 'string'
        ? name
        : now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0') + '-'
            + String(now.getDate()).padStart(2, '0') + '_' + String(now.getHours()).padStart(2, '0')
            + String(now.getMinutes()).padStart(2, '0') + '.' + extension;

    return requestPictureCsrfToken(uploadDir).then(function (csrfToken) {
        const formData = new FormData();
        formData.append('pictures[]', blob, uploadName);
        formData.append('dir', uploadDir);
        formData.append('ajax', '1');
        formData.append('create_dir', '1');
        formData.append('return_image_info', '1');
        formData.append('csrf_token', csrfToken);
        if (token) {
            formData.append('token', token);
            formData.append('name', uploadName);
        }
        return fetch(adminAjaxUrl('upload'), {method: 'POST', body: formData});
    }).then(function (response) {
        return response.json();
    }).then(function (result) {
        if (result?.success === true && result.image_info) {
            return {res: result, width: result.image_info[0], height: result.image_info[1]};
        }
        throw new Error(result?.message || 'Image upload failed.');
    });
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
        hasAlpha: hasAlpha
    };
}

function minSsimForJob(job) {
    return job.retina ? imageState.policy.retinaMinSsim : imageState.policy.jpegMinSsim;
}

function optimizePng(pngSource, options) {
    return new Promise(function (resolve) {
        runOptipng(pngSource, function (blob, metadata) {
            resolve({blob: blob || pngSource, metadata: metadata || null});
        }, options);
    });
}

async function buildPng24Candidate(job, pngSource) {
    const result = await optimizePng(pngSource, {
        quantize: false,
        optLevel: imageState.policy.png24OptLevel
    });
    const candidate = {type: 'png24', blob: result.blob, size: result.blob.size};
    job.candidates.png24 = candidate;
    updateImageJobOverlay(job, overlayHandlers);
    return candidate;
}

async function buildPng8Candidate(job, pngSource, analysis, scoreCandidate) {
    const result = await optimizePng(pngSource, {
        quantize: true,
        minPsnr: imageState.policy.png8MinPsnr,
        optLevel: imageState.policy.png8OptLevel,
        requireQuantized: true
    });
    const quant = result.metadata?.quantResult;
    if (!quant?.accepted || !result.blob || result.blob === pngSource) {
        job.candidates.png8 = false;
        updateImageJobOverlay(job, overlayHandlers);
        return null;
    }
    const score = await scoreCandidate(result.blob);
    const candidate = {
        type: 'png8',
        blob: result.blob,
        size: result.blob.size,
        ssim: score.score,
        psnr: quant.psnr
    };
    job.candidates.png8 = candidate;
    updateImageJobOverlay(job, overlayHandlers);
    return candidate;
}

async function buildJpegCandidate(job, pngSource, analysis, scoreCandidate) {
    if (analysis.hasAlpha) {
        job.candidates.jpeg = false;
        updateImageJobOverlay(job, overlayHandlers);
        return null;
    }
    const minSsim = minSsimForJob(job);
    const candidate = await findJpegCandidateForSsim(
        pngSource,
        analysis,
        {
            jpegQuality: imageState.policy.jpegQuality,
            jpegMinQuality: imageState.policy.jpegMinQuality,
            jpegMinSsim: minSsim,
            jpegQualitySearchSteps: imageState.policy.jpegQualitySearchSteps
        },
        '#ffffff',
        false,
        function (progress) {
            job.candidates.jpeg = {
                type: 'jpeg',
                blob: null,
                size: progress.size,
                ssim: progress.ssim,
                minSsim: minSsim,
                quality: progress.quality * 100
            };
            updateImageJobOverlay(job, overlayHandlers);
        },
        scoreCandidate
    );
    if (!candidate?.blob) {
        job.candidates.jpeg = false;
        return null;
    }
    const normalized = Object.assign({}, candidate, {
        type: 'jpeg',
        quality: candidate.quality * 100,
        minSsim: minSsim
    });
    job.candidates.jpeg = normalized;
    updateImageJobOverlay(job, overlayHandlers);
    return normalized;
}

async function evaluateWebpCandidate(encoder, quality, analysis, scoreCandidate) {
    const blob = await encoder.encode(webpEncodeOptions(quality, false));
    const score = await scoreCandidate(blob);
    return {
        type: 'webp',
        blob: blob,
        size: blob.size,
        ssim: score.score,
        quality: quality
    };
}

async function findWebpCandidate(job, encoder, analysis, scoreCandidate) {
    const policy = imageState.policy;
    const candidate = await evaluateWebpCandidate(
        encoder,
        policy.webpQuality,
        analysis,
        scoreCandidate
    );
    job.candidates.webp = candidate;
    updateImageJobOverlay(job, overlayHandlers);
    return candidate;
}

async function buildWebpCandidates(job, imageData, analysis, scoreCandidate, includeLossless) {
    let encoder = null;
    try {
        encoder = await createWebpEncoder(imageData);
        const tasks = [findWebpCandidate(job, encoder, analysis, scoreCandidate)];
        if (includeLossless) {
            tasks.push(encoder.encode(webpEncodeOptions(100, true)).then(function (blob) {
                const candidate = {type: 'webp-lossless', blob: blob, size: blob.size, ssim: 1};
                job.candidates.webpLossless = candidate;
                updateImageJobOverlay(job, overlayHandlers);
                return candidate;
            }));
        }
        const results = await Promise.allSettled(tasks);
        if (results[0].status === 'rejected') {
            job.candidates.webp = false;
        }
        if (includeLossless && results[1].status === 'rejected') {
            job.candidates.webpLossless = false;
        }
    } catch (error) {
        console.warn('WebP encoder unavailable:', error);
        job.candidates.webp = false;
        if (includeLossless) {
            job.candidates.webpLossless = false;
        }
    } finally {
        encoder?.close();
        updateImageJobOverlay(job, overlayHandlers);
    }
}

async function buildWebpLosslessCandidate(job, imageData) {
    let encoder = null;
    try {
        encoder = await createWebpEncoder(imageData);
        const blob = await encoder.encode(webpEncodeOptions(100, true));
        const candidate = {type: 'webp-lossless', blob: blob, size: blob.size, ssim: 1};
        job.candidates.webpLossless = candidate;
        updateImageJobOverlay(job, overlayHandlers);
        return candidate;
    } catch (error) {
        console.warn('Lossless WebP encoder unavailable:', error);
        job.candidates.webpLossless = false;
        updateImageJobOverlay(job, overlayHandlers);
        return null;
    } finally {
        encoder?.close();
    }
}

function createImageJob(file) {
    const blobUrl = URL.createObjectURL(file);
    let resolveReservation;
    let rejectReservation;
    const reservationChoice = new Promise(function (resolve, reject) {
        resolveReservation = resolve;
        rejectReservation = reject;
    });
    const job = {
        id: ++imageState.pasteImageCounter,
        file: file,
        blobUrl: blobUrl,
        src: null,
        dir: imageState.contentImageDirectory,
        publicationDate: effectivePublicationDate(),
        original: {width: null, height: null, size: file.size},
        output: {width: null, height: null, resized: false},
        retina: false,
        candidates: {original: null, webp: null, webpLossless: null, jpeg: null, png8: null, png24: null},
        selected: null,
        status: 'starting',
        statusLabel: 'Preparing…',
        error: null,
        settled: false,
        overlay: null,
        overlayHidden: false,
        resolveReservation: resolveReservation,
        rejectReservation: rejectReservation,
        reservationResolved: false,
        promise: null
    };

    const previousReservation = imageState.reservationTail;
    job.reservationPromise = previousReservation
        .then(function () {
            return reservationChoice;
        })
        .then(function (extension) {
            return reserveCanonicalImage(job, extension);
        });
    imageState.reservationTail = job.reservationPromise.then(function () {}, function () {});

    imageState.pasteImageJobs.set(job.id, job);
    setJobSrc(job, blobUrl);
    insertImageTag(blobUrl, null, null, false);
    return job;
}

function resolveJobReservation(job, extension) {
    if (job.reservationResolved) {
        return;
    }
    job.reservationResolved = true;
    job.resolveReservation(extension);
}

function rejectJobReservation(job, error) {
    if (job.reservationResolved) {
        return;
    }
    job.reservationResolved = true;
    job.rejectReservation(error);
}

async function runImagePipeline(job) {
    let qualityScorer = null;
    try {
        job.status = 'analyzing';
        job.statusLabel = 'Decoding in sRGB…';
        updateImageJobOverlay(job, overlayHandlers);
        const sourceCandidate = await createSanitizedSourceCandidate(job.file);
        const decoded = await decodeImageToSrgb(job.file, imageState.policy);
        const plan = planImageDimensions(decoded.width, decoded.height);
        job.original.width = decoded.width;
        job.original.height = decoded.height;
        job.output.width = plan.targetWidth;
        job.output.height = plan.targetHeight;
        job.output.resized = plan.resized;
        job.retina = plan.retina;
        replaceImageTagInEditor(job.src, job.src, plan.displayWidth, plan.displayHeight);
        updateImageJobOverlay(job, overlayHandlers);

        let pixels = decoded.imageData;
        if (plan.resized) {
            job.status = 'resizing';
            job.statusLabel = 'Lanczos3 · linear sRGB…';
            updateImageJobOverlay(job, overlayHandlers);
            pixels = await resizeImageDataLinear(pixels, plan.targetWidth, plan.targetHeight);
        }

        const analysis = analyzePixels(pixels);
        job.candidates.original = plan.resized || !sourceCandidate ? false : sourceCandidate;
        const pngSource = await imageDataToBlob(pixels, 'image/png');
        try {
            qualityScorer = await createImageQualityScorer(pixels);
        } catch (error) {
            console.warn('Offscreen image quality analysis unavailable:', error);
        }
        const scoreCandidate = qualityScorer
            ? qualityScorer.score
            : function (blob) {
                return computeCandidateSsimScore(blob, analysis, imageState.policy);
            };
        job.status = 'compressing';
        job.statusLabel = 'Comparing WebP, JPEG and PNG…';
        updateImageJobOverlay(job, overlayHandlers);

        const includeLosslessInitially = analysis.hasAlpha || job.file.type === 'image/png';
        const primaryTasks = [
            buildWebpCandidates(job, pixels, analysis, scoreCandidate, includeLosslessInitially),
            buildJpegCandidate(job, pngSource, analysis, scoreCandidate),
            buildPng8Candidate(job, pngSource, analysis, scoreCandidate)
        ];
        if (includeLosslessInitially) {
            primaryTasks.push(buildPng24Candidate(job, pngSource));
        }
        await Promise.allSettled(primaryTasks);

        if (!chooseBestCandidate(job.candidates, analysis.hasAlpha)) {
            await Promise.allSettled([
                job.candidates.webpLossless === null
                    ? buildWebpLosslessCandidate(job, pixels)
                    : Promise.resolve(),
                job.candidates.png24 === null
                    ? buildPng24Candidate(job, pngSource)
                    : Promise.resolve()
            ]);
        }
        if (job.candidates.webpLossless === null) {
            job.candidates.webpLossless = false;
        }
        if (job.candidates.png24 === null) {
            job.candidates.png24 = false;
        }
        qualityScorer?.close();

        job.selected = chooseBestCandidate(job.candidates, analysis.hasAlpha);
        if (!job.selected?.blob) {
            throw new Error('No safe image candidate passed the quality threshold.');
        }
        const extension = extensionForCandidate(job.selected);
        resolveJobReservation(job, extension);

        job.status = 'uploading';
        job.statusLabel = 'Uploading ' + extension.toUpperCase() + '…';
        updateImageJobOverlay(job, overlayHandlers);
        const reserve = await job.reservationPromise;
        const result = await uploadBlobToPictureDir(
            job.selected.blob,
            reserve.name,
            extension,
            reserve.dir,
            reserve.token
        );
        const finalPath = result?.res?.file_path;
        if (!finalPath) {
            throw new Error('The upload response did not contain an image path.');
        }

        const previousSrc = job.src;
        replaceImageTagInEditor(previousSrc, finalPath, plan.displayWidth, plan.displayHeight);
        updatePreviewImage(previousSrc, finalPath);
        setJobSrc(job, finalPath);
        URL.revokeObjectURL(job.blobUrl);
        job.blobUrl = null;
        job.status = 'done';
        job.statusLabel = reserve.name;
        updateImageJobOverlay(job, overlayHandlers);
        document.dispatchEvent(new CustomEvent('image_inserted.register', {detail: {src: finalPath}}));
        logPipelineSummary(job);
        return finalPath;
    } catch (error) {
        rejectJobReservation(job, error);
        job.error = error instanceof Error ? error : new Error('Image optimization failed.');
        job.status = 'error';
        job.statusLabel = job.error.message;
        updateImageJobOverlay(job, overlayHandlers);
        throw job.error;
    } finally {
        qualityScorer?.close();
    }
}

function releaseJobMemory(job) {
    job.file = null;
    Object.values(job.candidates || {}).forEach(function (candidate) {
        if (candidate && typeof candidate === 'object') {
            candidate.blob = null;
        }
    });
}

function closeImageJob(job) {
    if (!job || (job.status !== 'done' && job.status !== 'error')) {
        return;
    }
    detachJobOverlay(job);
    job.overlayHidden = true;
    if (job.status === 'error' && job.src) {
        removeImageTagFromEditor(job.src);
        imageState.lastPreviewWrapper?.querySelectorAll('img').forEach(function (img) {
            if (img.getAttribute('src') === job.src) {
                img.remove();
            }
        });
    }
    if (job.src && imageState.pasteImageBySrc.get(job.src) === job) {
        imageState.pasteImageBySrc.delete(job.src);
    }
    if (job.blobUrl) {
        URL.revokeObjectURL(job.blobUrl);
        job.blobUrl = null;
    }
    imageState.pasteImageJobs.delete(job.id);
}

const overlayHandlers = {closeImageJob: closeImageJob};

export function optimizeAndUploadFile(file) {
    if (!file) {
        return Promise.reject(new Error('No image file was provided.'));
    }
    const job = createImageJob(file);
    markImageOperation(1);
    job.promise = runImagePipeline(job).finally(function () {
        releaseJobMemory(job);
        job.settled = true;
        markImageOperation(-1);
    });
    // A visible error overlay owns the rejection until the editor save path checks it.
    job.promise.catch(function () {});
    return job.promise;
}

export async function waitForPendingImages() {
    const running = Array.from(imageState.pasteImageJobs.values())
        .filter(function (job) { return !job.settled && job.promise; })
        .map(function (job) { return job.promise; });
    if (running.length > 0) {
        await Promise.all(running);
    }
    const failed = Array.from(imageState.pasteImageJobs.values()).find(function (job) {
        return job.status === 'error';
    });
    if (failed) {
        throw failed.error || new Error('An image could not be optimized.');
    }
}

function supportedImage(file) {
    const type = String(file?.type || '').toLowerCase();
    return ['image/jpeg', 'image/png', 'image/webp'].includes(type);
}

function bindCodemirrorImageHandlers() {
    if (!register_codemirror.isReady()) {
        return;
    }
    register_codemirror.onPaste(function (event) {
        const items = (event.clipboardData || event.originalEvent.clipboardData).items;
        let processed = false;
        for (let index = 0; index < items.length; index += 1) {
            const file = items[index].type.startsWith('image/') ? items[index].getAsFile() : null;
            if (file && supportedImage(file)) {
                optimizeAndUploadFile(file);
                processed = true;
            }
        }
        if (processed) {
            event.preventDefault();
        }
        return !processed;
    });

    register_codemirror.onDrop(function (event) {
        const files = Array.from(event.dataTransfer?.files || []).filter(supportedImage);
        if (files.length === 0) {
            return;
        }
        register_codemirror.setSelectionFromCoords(event.x, event.y);
        files.forEach(optimizeAndUploadFile);
        event.preventDefault();
    });
}

let pipelineInitialized = false;

export function initImagePipeline(form, config) {
    imageState.editorForm = form || null;
    imageState.contentImageDirectory = typeof config?.directory === 'string' ? config.directory : '';
    imageState.defaultPublicationDate = typeof config?.publicationDate === 'string'
        ? config.publicationDate
        : '';
    bindCodemirrorImageHandlers();
    if (pipelineInitialized) {
        return;
    }
    pipelineInitialized = true;

    document.addEventListener('preview_updated.register', function (event) {
        if (!event.detail?.wrapper) {
            return;
        }
        imageState.lastPreviewWrapper = event.detail.wrapper;
        imageState.lastPreviewWrapper.querySelectorAll('img').forEach(function (img) {
            const job = findImageJobForPreview(img);
            if (job) {
                img.setAttribute('aria-busy', job.status === 'done' ? 'false' : 'true');
                renderImageOverlay(img, job, overlayHandlers);
            }
        });
    });
}
