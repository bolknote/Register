import {readFileSync} from 'node:fs';

export const baseJpeg = readFileSync(new URL('../_resources/image-optimizer/hdr-base.jpg', import.meta.url));
export const gainJpeg = readFileSync(new URL('../_resources/image-optimizer/hdr-gain-map.jpg', import.meta.url));
const xmpId = 'http://ns.adobe.com/xap/1.0/\0';
const isoId = 'urn:iso:std:iso:ts:21496:-1\0';

export function jpegSegment(marker, payload) {
    const bytes = Buffer.alloc(payload.length + 4);
    bytes[0] = 255;
    bytes[1] = marker;
    bytes.writeUInt16BE(payload.length + 2, 2);
    Buffer.from(payload).copy(bytes, 4);
    return bytes;
}

export function exif(orientation, little = true) {
    const bytes = Buffer.alloc(32);
    bytes.write('Exif\0\0' + (little ? 'II' : 'MM'));
    const word = (value, at) => little ? bytes.writeUInt16LE(value, at) : bytes.writeUInt16BE(value, at);
    const integer = (value, at) => little ? bytes.writeUInt32LE(value, at) : bytes.writeUInt32BE(value, at);
    word(42, 8); integer(8, 10); word(1, 14);
    word(274, 16); word(3, 18); integer(1, 20); word(orientation, 24);
    return jpegSegment(225, bytes);
}

export function hdrFixture({orientation = 1, little = true, values = {}, iso = false, adobe = true, prefix = 'hdrgm', gainBase = gainJpeg, base = baseJpeg} = {}) {
    const defaults = {Version: '1.0', GainMapMin: '0', GainMapMax: '3', Gamma: '1', OffsetSDR: '0', OffsetHDR: '0', HDRCapacityMin: '0', HDRCapacityMax: '3', BaseRenditionIsHDR: 'False'};
    const tags = Object.entries({...defaults, ...values}).map(([key, value]) => Array.isArray(value)
        ? `<${prefix}:${key}><rdf:Seq>${value.map(item => `<rdf:li>${item}</rdf:li>`).join('')}</rdf:Seq></${prefix}:${key}>`
        : `<${prefix}:${key}>${value}</${prefix}:${key}>`).join('');
    // A real single-channel ISO 21496-1 packet, with the same log2 gain and headroom.
    const isoMetadata = Buffer.alloc(37);
    isoMetadata[4] = 64 | 8;
    isoMetadata.writeUInt32BE(10000, 5);
    [0, 3, 0, 3, 1, 0, 0].forEach((number, index) => isoMetadata.writeInt32BE(number * 10000, 9 + index * 4));
    const packet = (content) => `<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:${prefix}="http://ns.adobe.com/hdr-gain-map/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/"><dc:creator>PRIVATE_CREATOR</dc:creator>${content}</rdf:Description></rdf:RDF></x:xmpmeta>`;
    const gain = Buffer.concat([gainBase.subarray(0, 2), jpegSegment(254, Buffer.from('PRIVATE_GAIN_COMMENT')),
        ...(adobe ? [jpegSegment(225, Buffer.from(xmpId + packet(tags)))] : []),
        ...(iso ? [jpegSegment(226, Buffer.concat([Buffer.from(isoId), isoMetadata]))] : []), gainBase.subarray(2)]);
    const headers = Buffer.concat([exif(orientation, little), jpegSegment(225, Buffer.from(xmpId + (adobe ? packet(`<${prefix}:Version>1.0</${prefix}:Version>`) : '<private>PRIVATE_CREATOR</private>'))),
        ...(iso ? [jpegSegment(226, Buffer.concat([Buffer.from(isoId), Buffer.alloc(4)]))] : [])]);
    const index = Buffer.alloc(86);
    index.write('MPF\0' + (little ? 'II' : 'MM'));
    const word = (value, at) => little ? index.writeUInt16LE(value, at) : index.writeUInt16BE(value, at);
    const integer = (value, at) => little ? index.writeUInt32LE(value, at) : index.writeUInt32BE(value, at);
    word(42, 6); integer(8, 8); word(3, 12);
    [[0xb000, 7, 4, 0x30303130], [0xb001, 4, 1, 2], [0xb002, 7, 32, 50]].forEach(([tag, type, count, value], indexNumber) => {
        const at = 14 + 12 * indexNumber;
        word(tag, at); word(type, at + 2); integer(count, at + 4); integer(value, at + 8);
    });
    index.write('0100', 22);
    const primaryLength = base.length + headers.length + 90;
    integer(0x030000, 54); integer(primaryLength, 58);
    integer(gain.length, 74); integer(primaryLength - (2 + headers.length + 8), 78);
    return Buffer.concat([base.subarray(0, 2), headers, jpegSegment(226, index), base.subarray(2), gain]);
}

export function jpegImages(bytes) {
    const firstEnd = bytes.indexOf(Buffer.from([255, 217])) + 2;
    return [bytes.subarray(0, firstEnd), bytes.subarray(firstEnd)];
}

export function gainMetadata(bytes) {
    const match = bytes.toString('utf8').match(/<x:xmpmeta[\s\S]*?<\/x:xmpmeta>/);
    return match ? match[0] : '';
}
