/**
 * Lossless source-file candidates with private metadata removed and color data retained.
 *
 * @copyright 2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

const textDecoder = new TextDecoder('latin1');

function bytesEqual(bytes, offset, expected) {
    if (offset < 0 || offset + expected.length > bytes.length) {
        return false;
    }
    for (let index = 0; index < expected.length; index += 1) {
        if (bytes[offset + index] !== expected[index]) {
            return false;
        }
    }
    return true;
}

function ascii(bytes, offset, length) {
    return textDecoder.decode(bytes.subarray(offset, offset + length));
}

function tiffOrientation(bytes, tiffOffset, endOffset) {
    if (tiffOffset + 8 > endOffset) {
        return null;
    }
    const littleEndian = bytesEqual(bytes, tiffOffset, [0x49, 0x49]);
    const bigEndian = bytesEqual(bytes, tiffOffset, [0x4d, 0x4d]);
    if (!littleEndian && !bigEndian) {
        return null;
    }

    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const read16 = function (offset) {
        return view.getUint16(offset, littleEndian);
    };
    const read32 = function (offset) {
        return view.getUint32(offset, littleEndian);
    };
    if (read16(tiffOffset + 2) !== 42) {
        return null;
    }

    const ifdOffset = tiffOffset + read32(tiffOffset + 4);
    if (ifdOffset < tiffOffset || ifdOffset + 2 > endOffset) {
        return null;
    }
    const entryCount = read16(ifdOffset);
    for (let index = 0; index < entryCount; index += 1) {
        const entryOffset = ifdOffset + 2 + index * 12;
        if (entryOffset + 12 > endOffset) {
            return null;
        }
        if (read16(entryOffset) === 0x0112 && read16(entryOffset + 2) === 3 && read32(entryOffset + 4) === 1) {
            return read16(entryOffset + 8);
        }
    }
    return null;
}

function embeddedExifOrientation(bytes, payloadOffset, payloadLength) {
    const endOffset = payloadOffset + payloadLength;
    if (endOffset > bytes.length) {
        return null;
    }
    const tiffOffset = bytesEqual(bytes, payloadOffset, [0x45, 0x78, 0x69, 0x66, 0, 0])
        ? payloadOffset + 6
        : payloadOffset;
    return tiffOrientation(bytes, tiffOffset, endOffset);
}

function sanitizeJpeg(bytes) {
    if (!bytesEqual(bytes, 0, [0xff, 0xd8])) {
        throw new Error('The JPEG file is invalid.');
    }

    const parts = [bytes.subarray(0, 2)];
    let offset = 2;
    while (offset < bytes.length) {
        const markerOffset = offset;
        if (bytes[offset] !== 0xff) {
            throw new Error('The JPEG marker stream is invalid.');
        }
        while (offset < bytes.length && bytes[offset] === 0xff) {
            offset += 1;
        }
        if (offset >= bytes.length) {
            throw new Error('The JPEG marker stream is truncated.');
        }
        const marker = bytes[offset];
        offset += 1;
        if (marker === 0xd9) {
            parts.push(bytes.subarray(markerOffset, offset));
            return new Blob(parts, {type: 'image/jpeg'});
        }
        if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) {
            parts.push(bytes.subarray(markerOffset, offset));
            continue;
        }
        if (offset + 2 > bytes.length) {
            throw new Error('The JPEG segment is truncated.');
        }
        const length = bytes[offset] * 256 + bytes[offset + 1];
        const segmentEnd = offset + length;
        if (length < 2 || segmentEnd > bytes.length) {
            throw new Error('The JPEG segment length is invalid.');
        }

        const segment = bytes.subarray(markerOffset, segmentEnd);
        if (marker === 0xda) {
            parts.push(segment);
            const scanOffset = segmentEnd;
            let cursor = scanOffset;
            let nextMarker = -1;
            while (cursor < bytes.length) {
                if (bytes[cursor] !== 0xff) {
                    cursor += 1;
                    continue;
                }

                let markerCodeOffset = cursor + 1;
                while (markerCodeOffset < bytes.length && bytes[markerCodeOffset] === 0xff) {
                    markerCodeOffset += 1;
                }
                if (markerCodeOffset >= bytes.length) {
                    throw new Error('The JPEG image scan is truncated.');
                }

                const scanMarker = bytes[markerCodeOffset];
                if (scanMarker === 0x00 || scanMarker === 0x01 || (scanMarker >= 0xd0 && scanMarker <= 0xd7)) {
                    cursor = markerCodeOffset + 1;
                    continue;
                }

                parts.push(bytes.subarray(scanOffset, cursor));
                nextMarker = cursor;
                break;
            }
            if (nextMarker === -1) {
                throw new Error('The JPEG file has no end marker.');
            }
            offset = nextMarker;
            continue;
        }

        if (marker === 0xe1) {
            const orientation = embeddedExifOrientation(segment, 4, segment.length - 4);
            if (orientation !== null && orientation !== 1) {
                return null;
            }
        }

        const isAppMarker = marker >= 0xe0 && marker <= 0xef;
        const keep = !isAppMarker && marker !== 0xfe
            || marker === 0xe0
            || marker === 0xee
            || marker === 0xe2 && ascii(segment, 4, 12) === 'ICC_PROFILE\0';
        if (keep) {
            parts.push(segment);
        }
        offset = segmentEnd;
    }

    throw new Error('The JPEG file has no image scan.');
}

function sanitizePng(bytes) {
    const signature = [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a];
    if (!bytesEqual(bytes, 0, signature)) {
        throw new Error('The PNG file is invalid.');
    }

    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const parts = [bytes.subarray(0, 8)];
    const retainedColorChunks = new Set(['iCCP', 'sRGB', 'gAMA', 'cHRM', 'sBIT', 'tRNS', 'bKGD']);
    let offset = 8;
    let foundEnd = false;
    while (offset + 12 <= bytes.length) {
        const length = view.getUint32(offset, false);
        const chunkEnd = offset + 12 + length;
        if (chunkEnd > bytes.length) {
            throw new Error('The PNG chunk stream is truncated.');
        }
        const type = ascii(bytes, offset + 4, 4);
        if (type === 'acTL' || type === 'fcTL' || type === 'fdAT') {
            throw new Error('Animated PNG is not supported by the image optimizer.');
        }
        if (type === 'eXIf') {
            const orientation = embeddedExifOrientation(bytes, offset + 8, length);
            if (orientation !== null && orientation !== 1) {
                return null;
            }
        }

        const critical = (bytes[offset + 4] & 0x20) === 0;
        if (critical || retainedColorChunks.has(type)) {
            parts.push(bytes.subarray(offset, chunkEnd));
        }
        offset = chunkEnd;
        if (type === 'IEND') {
            foundEnd = true;
            break;
        }
    }
    if (!foundEnd) {
        throw new Error('The PNG file has no IEND chunk.');
    }
    return new Blob(parts, {type: 'image/png'});
}

function sanitizeWebp(bytes) {
    if (ascii(bytes, 0, 4) !== 'RIFF' || ascii(bytes, 8, 4) !== 'WEBP') {
        throw new Error('The WebP file is invalid.');
    }

    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const parts = [];
    let offset = 12;
    let foundImage = false;
    while (offset + 8 <= bytes.length) {
        const type = ascii(bytes, offset, 4);
        const length = view.getUint32(offset + 4, true);
        const chunkEnd = offset + 8 + length + (length & 1);
        if (chunkEnd > bytes.length) {
            throw new Error('The WebP chunk stream is truncated.');
        }
        if (type === 'ANIM' || type === 'ANMF') {
            throw new Error('Animated WebP is not supported by the image optimizer.');
        }
        if (type === 'EXIF') {
            const orientation = embeddedExifOrientation(bytes, offset + 8, length);
            if (orientation !== null && orientation !== 1) {
                return null;
            }
        }

        if (type === 'VP8 ' || type === 'VP8L') {
            foundImage = true;
        }
        if (type === 'VP8X') {
            if (length < 10) {
                throw new Error('The WebP feature chunk is invalid.');
            }
            if ((bytes[offset + 8] & 0x02) !== 0) {
                throw new Error('Animated WebP is not supported by the image optimizer.');
            }
            const chunk = bytes.slice(offset, chunkEnd);
            chunk[8] &= ~0x0c;
            parts.push(chunk);
        } else if (['ICCP', 'ALPH', 'VP8 ', 'VP8L'].includes(type)) {
            parts.push(bytes.subarray(offset, chunkEnd));
        }
        offset = chunkEnd;
    }
    if (!foundImage) {
        throw new Error('The WebP file has no image bitstream.');
    }

    const bodySize = parts.reduce(function (sum, part) { return sum + part.byteLength; }, 4);
    const header = new Uint8Array(12);
    header.set([0x52, 0x49, 0x46, 0x46], 0);
    new DataView(header.buffer).setUint32(4, bodySize, true);
    header.set([0x57, 0x45, 0x42, 0x50], 8);
    return new Blob([header].concat(parts), {type: 'image/webp'});
}

async function createSanitizedSourceCandidate(file) {
    const bytes = new Uint8Array(await file.arrayBuffer());
    let blob;
    let extension;
    switch (String(file.type || '').toLowerCase()) {
        case 'image/jpeg':
            blob = sanitizeJpeg(bytes);
            extension = 'jpg';
            break;
        case 'image/png':
            blob = sanitizePng(bytes);
            extension = 'png';
            break;
        case 'image/webp':
            blob = sanitizeWebp(bytes);
            extension = 'webp';
            break;
        default:
            throw new Error('Unsupported image format.');
    }

    return blob ? {
        type: 'original',
        blob: blob,
        size: blob.size,
        ssim: 1,
        extension: extension
    } : null;
}

export {createSanitizedSourceCandidate, sanitizeJpeg, sanitizePng, sanitizeWebp};
