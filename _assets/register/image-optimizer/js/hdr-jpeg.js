/** Lossless, privacy-preserving JPEG gain-map containers. */

const encoder = new TextEncoder();
const decoder = new TextDecoder();
const xmpPrefix = 'http://ns.adobe.com/xap/1.0/\0';
const hdrNamespace = 'http://ns.adobe.com/hdr-gain-map/1.0/';
const rdfNamespace = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
const isoPrefix = 'urn:iso:std:iso:ts:21496:-1\0';
const numericProperties = ['GainMapMin', 'GainMapMax', 'Gamma', 'OffsetSDR', 'OffsetHDR', 'HDRCapacityMin', 'HDRCapacityMax'];

function starts(bytes, text) {
    const prefix = encoder.encode(text);
    return prefix.every((value, index) => bytes[index] === value);
}

function segment(marker, payload) {
    if (payload.length > 65533) throw new Error('The HDR JPEG metadata is too large.');
    const result = new Uint8Array(payload.length + 4);
    result.set([255, marker, (payload.length + 2) >> 8, (payload.length + 2) & 255]);
    result.set(payload, 4);
    return result;
}

function join(parts) {
    const result = new Uint8Array(parts.reduce((size, part) => size + part.length, 0));
    let offset = 0;
    for (const part of parts) {
        result.set(part, offset);
        offset += part.length;
    }
    return result;
}

function jpeg(bytes, start = 0, end = bytes.length) {
    if (bytes[start] !== 255 || bytes[start + 1] !== 216) throw new Error('The HDR JPEG image is invalid.');
    const parts = [bytes.subarray(start, start + 2)];
    const segments = [];
    let offset = start + 2;
    let width = 0;
    let height = 0;
    while (offset < end) {
        const markerOffset = offset;
        if (bytes[offset++] !== 255) throw new Error('The HDR JPEG marker stream is invalid.');
        while (offset < end && bytes[offset] === 255) offset++;
        const marker = bytes[offset++];
        if (marker === 217) {
            parts.push(bytes.subarray(markerOffset, offset));
            return {parts, segments, end: offset, width, height};
        }
        if (marker === 1 || marker >= 208 && marker <= 215) {
            parts.push(bytes.subarray(markerOffset, offset));
            continue;
        }
        if (offset + 2 > end) throw new Error('The HDR JPEG segment is truncated.');
        const length = bytes[offset] * 256 + bytes[offset + 1];
        const next = offset + length;
        if (length < 2 || next > end) throw new Error('The HDR JPEG segment length is invalid.');
        const payload = bytes.subarray(offset + 2, next);
        const data = bytes.subarray(markerOffset, next);
        const entry = {marker, payload, data, offset: markerOffset};
        segments.push(entry);
        parts.push(entry);
        if ([192, 193, 194, 195, 197, 198, 199, 201, 202, 203, 205, 206, 207].includes(marker)) {
            if (payload.length < 6) throw new Error('The HDR JPEG dimensions are missing.');
            height = payload[1] * 256 + payload[2];
            width = payload[3] * 256 + payload[4];
        }
        offset = next;
        if (marker !== 218) continue;
        const scanStart = offset;
        while (offset < end) {
            if (bytes[offset] !== 255) { offset++; continue; }
            let codeOffset = offset + 1;
            while (codeOffset < end && bytes[codeOffset] === 255) codeOffset++;
            const code = bytes[codeOffset];
            if (code === 0 || code === 1 || code >= 208 && code <= 215) { offset = codeOffset + 1; continue; }
            break;
        }
        parts.push(bytes.subarray(scanStart, offset));
    }
    throw new Error('The HDR JPEG has no end marker.');
}

function tiff(payload, prefixLength) {
    const bytes = payload.subarray(prefixLength);
    const little = bytes[0] === 73 && bytes[1] === 73;
    if (!little && !(bytes[0] === 77 && bytes[1] === 77)) throw new Error('The JPEG metadata byte order is invalid.');
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const word = (offset) => {
        if (offset < 0 || offset + 2 > bytes.length) throw new Error('The JPEG metadata is truncated.');
        return view.getUint16(offset, little);
    };
    const integer = (offset) => {
        if (offset < 0 || offset + 4 > bytes.length) throw new Error('The JPEG metadata is truncated.');
        return view.getUint32(offset, little);
    };
    if (word(2) !== 42) throw new Error('The JPEG metadata header is invalid.');
    const ifd = integer(4);
    if (ifd < 8) throw new Error('The JPEG metadata directory is invalid.');
    const count = word(ifd);
    if (ifd + 2 + count * 12 + 4 > bytes.length) throw new Error('The JPEG metadata directory is truncated.');
    const fields = new Map();
    for (let index = 0; index < count; index++) {
        const offset = ifd + 2 + index * 12;
        fields.set(word(offset), {type: word(offset + 2), count: integer(offset + 4), offset: offset + 8});
    }
    return {fields, word, integer, length: bytes.length};
}

function orientation(image) {
    for (const entry of image.segments) {
        if (entry.marker !== 225 || !starts(entry.payload, 'Exif\0\0')) continue;
        const metadata = tiff(entry.payload, 6);
        const field = metadata.fields.get(274);
        if (field?.type === 3 && field.count === 1) {
            const value = metadata.word(field.offset);
            // Cameras also write the nonstandard value 0; browsers treat it as identity.
            if (value >= 2 && value <= 8) return value;
        }
    }
    return 1;
}

function xmpDocuments(image) {
    return image.segments.filter(entry => entry.marker === 225 && starts(entry.payload, xmpPrefix)).map(entry => {
        const xml = decoder.decode(entry.payload.subarray(xmpPrefix.length));
        if (/<!DOCTYPE|<!ENTITY/i.test(xml)) throw new Error('The HDR JPEG XMP contains unsupported declarations.');
        const document = new DOMParser().parseFromString(xml, 'application/xml');
        if (document.getElementsByTagName('parsererror').length) throw new Error('The HDR JPEG XMP is invalid.');
        return document;
    });
}

function hdrValues(documents) {
    const values = new Map();
    function store(name, value) {
        if (values.has(name) && JSON.stringify(values.get(name)) !== JSON.stringify(value)) {
            throw new Error('The HDR JPEG gain-map metadata is conflicting.');
        }
        values.set(name, value);
    }
    function parse(name, texts) {
        if (name === 'Version') {
            if (texts.length !== 1 || texts[0] !== '1.0') throw new Error('The HDR JPEG gain-map version is unsupported.');
            return store(name, '1.0');
        }
        if (name === 'BaseRenditionIsHDR') {
            if (texts.length !== 1 || !/^(true|false)$/i.test(texts[0])) throw new Error('The HDR JPEG base rendition is invalid.');
            return store(name, texts[0].toLowerCase() === 'true' ? 'True' : 'False');
        }
        if (!numericProperties.includes(name)) return;
        if (!(texts.length === 1 || texts.length === 3) || name.startsWith('HDRCapacity') && texts.length !== 1) {
            throw new Error('The HDR JPEG gain-map channels are invalid.');
        }
        const numbers = texts.map(text => {
            if (!/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i.test(text) || !Number.isFinite(Number(text))) {
                throw new Error('The HDR JPEG gain-map value is invalid.');
            }
            return Number(text);
        });
        store(name, numbers);
    }
    for (const document of documents) {
        for (const description of document.getElementsByTagNameNS(rdfNamespace, 'Description')) {
            for (const attribute of description.attributes) {
                if (attribute.namespaceURI === hdrNamespace) parse(attribute.localName, [attribute.value.trim()]);
            }
            for (const property of description.childNodes) {
                if (property.namespaceURI !== hdrNamespace) continue;
                const sequence = property.getElementsByTagNameNS(rdfNamespace, 'Seq');
                const items = sequence.length ? sequence[0].getElementsByTagNameNS(rdfNamespace, 'li') : [];
                parse(property.localName, items.length
                    ? Array.from(items, item => item.textContent.trim())
                    : [property.textContent.trim()]);
            }
        }
    }
    return values;
}

function validateValues(values) {
    if (!values.has('Version') || !values.has('GainMapMax') || !values.has('HDRCapacityMax')) {
        throw new Error('The HDR JPEG gain-map metadata is incomplete.');
    }
    const min = values.get('GainMapMin') || [0];
    const max = values.get('GainMapMax');
    for (let channel = 0; channel < 3; channel++) {
        if (max[channel % max.length] < min[channel % min.length]) throw new Error('The HDR JPEG gain range is invalid.');
    }
    for (const name of ['Gamma', 'OffsetSDR', 'OffsetHDR', 'HDRCapacityMin']) {
        const property = values.get(name) || [];
        if (property.some(value => name === 'Gamma' ? value <= 0 : value < 0)) throw new Error('The HDR JPEG gain-map range is invalid.');
    }
    if (values.get('HDRCapacityMax')[0] <= (values.get('HDRCapacityMin') || [0])[0]) {
        throw new Error('The HDR JPEG display capacity is invalid.');
    }
}

function xmp(xml) {
    return segment(225, encoder.encode(xmpPrefix + xml));
}

function packet(contents, namespaces = '', attributes = '') {
    return `<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="${rdfNamespace}"><rdf:Description rdf:about="" xmlns:hdrgm="${hdrNamespace}" ${namespaces} ${attributes}>${contents}</rdf:Description></rdf:RDF></x:xmpmeta>`;
}

function gainMapXmp(values) {
    const fields = [];
    const attributes = [];
    for (const [name, value] of values) {
        if (Array.isArray(value) && value.length > 1) {
            fields.push(`<hdrgm:${name}><rdf:Seq>${value.map(number => `<rdf:li>${number}</rdf:li>`).join('')}</rdf:Seq></hdrgm:${name}>`);
        } else {
            attributes.push(`hdrgm:${name}="${Array.isArray(value) ? value[0] : value}"`);
        }
    }
    return xmp(packet(fields.join(''), '', attributes.join(' ')));
}

function vectorIsoMetadata(values) {
    // libultrahdr's legacy XMP reader supports scalar attributes. Preserve Adobe's
    // RGB coefficients through the equivalent ISO packet when the source has vectors.
    const channel = (name, index, fallback) => {
        const numbers = values.get(name) || [fallback];
        return numbers[index % numbers.length];
    };
    const numbers = [(values.get('HDRCapacityMin') || [0])[0], values.get('HDRCapacityMax')[0]];
    for (let c = 0; c < 3; c++) {
        numbers.push(channel('GainMapMin', c, 0), channel('GainMapMax', c, 0), channel('Gamma', c, 1),
            channel('OffsetSDR', c, 1 / 64), channel('OffsetHDR', c, 1 / 64));
    }
    const largest = Math.max(1, ...numbers.map(Math.abs));
    const denominator = Math.min(1000000, Math.floor(2147483647 / largest));
    if (denominator < 1 || numbers.some((value, index) => (index - 4) % 5 === 0 && Math.round(value * denominator) === 0)) {
        throw new Error('The HDR JPEG gain-map coefficients cannot be represented safely.');
    }
    const bytes = new Uint8Array(17 + 3 * 20);
    const view = new DataView(bytes.buffer);
    bytes[4] = 128 | 64 | 8 | (values.get('BaseRenditionIsHDR') === 'True' ? 4 : 0);
    view.setUint32(5, denominator);
    numbers.forEach((number, index) => view.setInt32(9 + index * 4, Math.round(number * denominator)));
    return segment(226, join([encoder.encode(isoPrefix), bytes]));
}

function primaryXmp(gainLength, adobe) {
    const contents = '<Container:Directory><rdf:Seq>'
        + '<rdf:li rdf:parseType="Resource"><Container:Item Item:Semantic="Primary" Item:Mime="image/jpeg"/></rdf:li>'
        + `<rdf:li rdf:parseType="Resource"><Container:Item Item:Semantic="GainMap" Item:Mime="image/jpeg" Item:Length="${gainLength}"/></rdf:li>`
        + '</rdf:Seq></Container:Directory>';
    return xmp(packet(contents, 'xmlns:Container="http://ns.google.com/photos/1.0/container/" xmlns:Item="http://ns.google.com/photos/1.0/container/item/"', adobe ? 'hdrgm:Version="1.0"' : ''));
}

function exifOrientation(value) {
    const payload = new Uint8Array(32);
    payload.set(encoder.encode('Exif\0\0II'));
    const view = new DataView(payload.buffer);
    view.setUint16(8, 42, true);
    view.setUint32(10, 8, true);
    view.setUint16(14, 1, true);
    view.setUint16(16, 274, true);
    view.setUint16(18, 3, true);
    view.setUint32(20, 1, true);
    view.setUint16(24, value, true);
    return segment(225, payload);
}

function clean(image, omitApp0 = false) {
    return join(image.parts.filter(part => {
        if (part instanceof Uint8Array) return true;
        const marker = part.marker;
        return marker < 224 || marker > 239 && marker !== 254
            || marker === 224 && !omitApp0 || marker === 238
            || marker === 226 && (starts(part.payload, 'ICC_PROFILE\0') || starts(part.payload, isoPrefix));
    }).map(part => part instanceof Uint8Array ? part : part.data));
}

function mpf(primaryLength, gainLength, tiffOffset) {
    const payload = new Uint8Array(86);
    payload.set(encoder.encode('MPF\0II'));
    const view = new DataView(payload.buffer, 4);
    view.setUint16(2, 42, true);
    view.setUint32(4, 8, true);
    view.setUint16(8, 3, true);
    [[45056, 7, 4, 0x30303130], [45057, 4, 1, 2], [45058, 7, 32, 50]].forEach(([tag, type, count, value], index) => {
        const at = 10 + index * 12;
        view.setUint16(at, tag, true);
        view.setUint16(at + 2, type, true);
        view.setUint32(at + 4, count, true);
        view.setUint32(at + 8, value, true);
    });
    view.setUint32(50, 0x030000, true);
    view.setUint32(54, primaryLength, true);
    view.setUint32(70, gainLength, true);
    view.setUint32(74, primaryLength - tiffOffset, true);
    return segment(226, payload);
}

/** Returns null for SDR; malformed HDR never falls through to an SDR encoder. */
export function sanitizeHdrJpeg(bytes) {
    if (bytes[0] !== 255 || bytes[1] !== 216) return null;
    const primary = jpeg(bytes);
    const primaryHasHdr = primary.segments.some(entry => entry.marker === 225 && starts(entry.payload, xmpPrefix)
        && decoder.decode(entry.payload).includes(hdrNamespace));
    const hasIso = primary.segments.some(entry => entry.marker === 226 && starts(entry.payload, isoPrefix));
    if (!primaryHasHdr && !hasIso) return null;
    const index = primary.segments.find(entry => entry.marker === 226 && starts(entry.payload, 'MPF\0'));
    if (!index) throw new Error('The HDR JPEG gain-map index is missing.');
    const metadata = tiff(index.payload, 4);
    const count = metadata.fields.get(45057);
    const entries = metadata.fields.get(45058);
    if (count?.type !== 4 || count.count !== 1 || metadata.integer(count.offset) !== 2
        || entries?.type !== 7 || entries.count !== 32) throw new Error('The HDR JPEG gain-map index is unsupported.');
    const table = metadata.integer(entries.offset);
    if (table < 8 || table + 32 > metadata.length) throw new Error('The HDR JPEG gain-map index is truncated.');
    const gainLength = metadata.integer(table + 20);
    const gainOffset = index.offset + 8 + metadata.integer(table + 24);
    if (gainLength < 4 || gainOffset < primary.end || gainOffset + gainLength > bytes.length) {
        throw new Error('The HDR JPEG gain-map location is invalid.');
    }
    const gain = jpeg(bytes, gainOffset, gainOffset + gainLength);
    if (gain.end !== gainOffset + gainLength) throw new Error('The HDR JPEG gain-map length is invalid.');
    const values = hdrValues([...xmpDocuments(primary), ...xmpDocuments(gain)]);
    const adobe = values.size > 0;
    if (adobe) validateValues(values);
    const gainHasIso = gain.segments.some(entry => entry.marker === 226 && starts(entry.payload, isoPrefix));
    if (!adobe && !(hasIso && gainHasIso)) throw new Error('The HDR JPEG gain-map metadata is missing.');
    const rotation = orientation(primary);
    const addVectorIso = adobe && !gainHasIso && Array.from(values.values()).some(value => Array.isArray(value) && value.length === 3);
    const iso = addVectorIso ? vectorIsoMetadata(values) : new Uint8Array();
    const gainPixels = clean(gain);
    const sanitizedGain = adobe ? join([gainPixels.subarray(0, 2), gainMapXmp(values), iso, gainPixels.subarray(2)]) : gainPixels;
    const base = clean(primary, true);
    const front = join([base.subarray(0, 2), ...primary.segments.filter(entry => entry.marker === 224).map(entry => entry.data)]);
    const container = primaryXmp(sanitizedGain.length, adobe);
    const exif = rotation === 1 ? new Uint8Array() : exifOrientation(rotation);
    const primaryIso = addVectorIso && !hasIso ? segment(226, join([encoder.encode(isoPrefix), new Uint8Array(4)])) : new Uint8Array();
    const primaryLength = front.length + base.length - 2 + container.length + exif.length + primaryIso.length + 90;
    const sanitized = join([front, container,
        mpf(primaryLength, sanitizedGain.length, front.length + container.length + 8), exif, primaryIso, base.subarray(2), sanitizedGain]);
    const swapped = rotation >= 5;
    return {
        blob: new Blob([sanitized], {type: 'image/jpeg'}),
        width: swapped ? primary.height : primary.width,
        height: swapped ? primary.width : primary.height,
        gainWidth: gain.width,
        gainHeight: gain.height,
        orientation: rotation,
    };
}
