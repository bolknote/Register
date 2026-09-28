/**
 * Text formatting helpers for Register.
 *
 * @copyright 2007-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   Register
 */

import {htmlTags} from './html.js';

function protectedBlocks(source) {
    const blocks = [];
    const stack = [];
    for (const tag of htmlTags(source)) {
        if (!/^(?:pre|script|style|textarea|title|ol|ul|li|cut)$/u.test(tag.name)) continue;
        if (tag.name === 'cut') {
            if (stack.length === 0) blocks.push(tag);
        } else if (!tag.closing) {
            stack.push(tag);
        } else {
            const index = stack.findLastIndex(open => open.name === tag.name);
            if (index < 0) continue;
            const open = stack[index];
            stack.splice(index);
            if (stack.length === 0) blocks.push({start: open.start, end: tag.end});
        }
    }
    if (stack.length > 0) blocks.push({start: stack[0].start, end: source.length});
    return blocks;
}

export function smartParagraphs(source) {
    // Mask complete source ranges before splitting or trimming. A middle chunk
    // of a preformatted block has no tag of its own to protect its whitespace.
    let prefix = '\u0000register-paragraph-';
    while (source.includes(prefix)) prefix += '-';
    const originals = [];
    let end = 0;
    let masked = '';
    for (const block of protectedBlocks(source)) {
        masked += source.slice(end, block.start) + '<pre>' + prefix + originals.length + '</pre>';
        originals.push(source.slice(block.start, block.end));
        end = block.end;
    }
    masked += source.slice(end);
    return formatParagraphs(masked).replace(new RegExp('<pre>' + prefix + '(\\d+)</pre>', 'g'),
        (match, index) => originals[Number(index)]);
}

function formatParagraphs(sText) {
    sText = sText.replace(/(\r\n|\r|\n)/g, '\n');
    const asParag = sText.split(/\n{2,}/);

    for (let i = asParag.length; i--;) {
        if (asParag[i].replace(/^\s+|\s+$/g, '') === '') {
            continue;
        }

        asParag[i] = asParag[i].replace(/\s+$/gm, '');

        if (/<\/?(?:pre|script|style|ol|ul|li|cut)[^>]*>/i.test(asParag[i])) {
            continue;
        }

        asParag[i] = asParag[i].replace(/<br \/>$/gm, '').
            replace(/$/gm, '-').
            replace(/(<\/(?:blockquote|p|h[2-4])>)?-$/gm, function ($0, $1) {
                return $1 ? $1 : '<br />';
            }).
            replace(/(?:<br \/>)?$/g, '');

        if (!/<\/?(?:blockquote|h[2-4])[^>]*>/.test(asParag[i])) {
            if (!/<\/p>\s*$/.test(asParag[i])) {
                asParag[i] = asParag[i].replace(/\s*$/g, '</p>');
            }
            if (!/^\s*<p[^>]*>/.test(asParag[i])) {
                asParag[i] = asParag[i].replace(/^\s*/g, '<p>');
            }
        }
    }

    return asParag.join("\n\n");
}
