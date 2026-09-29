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
    for (const tag of htmlTags(source, {comments: true})) {
        if (tag.name === '') {
            if (stack.length === 0) blocks.push(tag);
            continue;
        }
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
        // A complete comment is invisible inline content. Using a pre marker
        // for it would also exclude all neighbouring prose from formatting.
        const comment = block.name === '' && block.text.endsWith('-->');
        masked += source.slice(end, block.start) + (comment ? '<!--' : '<pre>')
            + prefix + originals.length + (comment ? '-->' : '</pre>');
        originals.push(source.slice(block.start, block.end));
        end = block.end;
    }
    masked += source.slice(end);
    // A line break inside a tag or quoted attribute is HTML source, not a
    // prose boundary. Hide it from paragraph splitting, trimming and <br> insertion.
    const lineBreaks = [];
    let tagged = '';
    end = 0;
    for (const tag of htmlTags(masked)) {
        tagged += masked.slice(end, tag.start) + tag.text.replace(/\r\n?|\n/g, lineBreak => {
            lineBreaks.push(lineBreak);
            return prefix + 'line-' + (lineBreaks.length - 1) + '\u0000';
        });
        end = tag.end;
    }
    tagged += masked.slice(end);
    return formatParagraphs(tagged)
        .replace(new RegExp(prefix + 'line-(\\d+)\u0000', 'g'), (match, index) => lineBreaks[Number(index)])
        .replace(new RegExp('<!--' + prefix + '(\\d+)-->', 'g'), (match, index) => originals[Number(index)])
        .replace(new RegExp('<pre>' + prefix + '(\\d+)</pre>', 'g'), (match, index) => originals[Number(index)]);
}

function formatParagraphs(sText) {
    sText = sText.replace(/(\r\n|\r|\n)/g, '\n');
    const asParag = sText.split(/\n{2,}/);

    for (let i = asParag.length; i--;) {
        if (asParag[i].replace(/<!--[\s\S]*?-->/g, '').trim() === '') {
            continue;
        }

        asParag[i] = asParag[i].replace(/\s+$/gm, '');

        if (/<\/?(?:pre|script|style|ol|ul|li|cut)[^>]*>/i.test(asParag[i])) {
            continue;
        }

        asParag[i] = asParag[i]
            .replace(/<br \/>((?:[ \t]*<!--.*?-->)*[ \t]*)$/gm, '$1')
            .replace(/^.*$/gm, line => {
                const visible = line.replace(/<!--.*?-->/g, '').trim();
                return visible === '' || /^<(?:blockquote|p|h[2-4])\b[^>]*>$/.test(visible)
                    || /<\/(?:blockquote|p|h[2-4])\b[^>]*>$/.test(visible) ? line : line + '<br />';
            })
            .replace(/<br \/>$/, '');

        const visible = asParag[i].replace(/<!--.*?-->/g, '');
        if (!/<\/?(?:blockquote|h[2-4])[^>]*>/.test(visible)) {
            if (!/<\/p\b[^>]*>\s*$/.test(visible)) {
                asParag[i] = asParag[i].replace(/\s*$/g, '</p>');
            }
            if (!/^\s*<p[^>]*>/.test(visible)) {
                asParag[i] = asParag[i].replace(/^\s*/g, '<p>');
            }
        }
    }

    return asParag.join("\n\n");
}
