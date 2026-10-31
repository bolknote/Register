// Synthetic private extensions; no article, photograph or actual personal data.
import {readFileSync} from 'node:fs';

const encoder = new TextEncoder();
const concat = (...parts) => new Uint8Array(parts.flatMap(part => [...part]));

// Reuse the real sRGB profile in our synthetic JPEG, rather than pretending
// arbitrary application bytes are an ICC profile in the browser regression.
const jpeg = readFileSync(new URL('../_resources/image-optimizer/hdr-base.jpg', import.meta.url));
const profileStart = jpeg.indexOf('ICC_PROFILE\0');
if (profileStart < 4 || jpeg[profileStart + 12] !== 1 || jpeg[profileStart + 13] !== 1) {
    throw new Error('The synthetic JPEG must contain one ICC profile chunk');
}
export const gifColorProfile = jpeg.subarray(profileStart + 14, profileStart - 2 + jpeg.readUInt16BE(profileStart - 2));

export function gifSubBlocks(data) {
    const parts = [];
    for (let offset = 0; offset < data.length; offset += 255) {
        const block = data.subarray(offset, offset + 255);
        parts.push(new Uint8Array([block.length]), block);
    }
    return concat(...parts, new Uint8Array([0]));
}

export function gifApplication(identifier, data) {
    if (identifier.length !== 11) throw new Error('GIF application identifiers have eleven bytes');
    return concat(new Uint8Array([0x21, 0xff, 11]), encoder.encode(identifier), gifSubBlocks(data));
}

export function gifWithPrivateMetadata(source, profile = null) {
    const headerEnd = 13 + (source[10] & 0x80 ? 3 * (1 << ((source[10] & 7) + 1)) : 0);
    const header = source.subarray(0, headerEnd);
    const comment = concat(new Uint8Array([0x21, 0xfe]), gifSubBlocks(encoder.encode('PRIVATE_creator=person@example.test;location=55.75,37.61;'.repeat(8))));
    // Adobe XMP Part 3, GIF: UTF-8 packet followed by the 258-byte magic
    // trailer. The packet is deliberately not split into ordinary sub-blocks.
    const xmp = concat(new Uint8Array([0x21, 0xff, 11]), encoder.encode('XMP DataXMP'),
        encoder.encode('<?xpacket begin=""?><x:xmpmeta xmlns:x="adobe:ns:meta/">PRIVATE_XMP_location</x:xmpmeta><?xpacket end="w"?>'),
        new Uint8Array([1, ...Array.from({length: 256}, (_, index) => 255 - index), 0]));
    const application = gifApplication('PRIVATE!001', encoder.encode('PRIVATE_application'));
    const icc = profile === null ? new Uint8Array() : gifApplication('ICCRGBG1012', profile);
    return {
        input: concat(header, comment, xmp, application, icc, source.subarray(headerEnd, -1), comment, source.subarray(-1), encoder.encode('PRIVATE_trailing_data')),
        expected: concat(header, icc, source.subarray(headerEnd)),
    };
}
