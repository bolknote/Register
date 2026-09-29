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
            // Retain a tag-name separator for the structural scanner below.
            return ' ' + prefix + 'line-' + (lineBreaks.length - 1) + '\u0000';
        });
        end = tag.end;
    }
    tagged += masked.slice(end);
    return formatParagraphs(tagged)
        .replace(new RegExp(' ' + prefix + 'line-(\\d+)\u0000', 'g'), (match, index) => lineBreaks[Number(index)])
        .replace(new RegExp('<!--' + prefix + '(\\d+)-->', 'g'), (match, index) => originals[Number(index)])
        .replace(new RegExp('<pre>' + prefix + '(\\d+)</pre>', 'g'), (match, index) => originals[Number(index)]);
}

function withoutComments(source) {
    let result = '';
    let end = 0;
    for (const tag of htmlTags(source, {comments: true})) {
        if (tag.name !== '') continue;
        result += source.slice(end, tag.start);
        end = tag.end;
    }
    return result + source.slice(end);
}

function formatParagraphs(sText) {
    sText = sText.replace(/(\r\n|\r|\n)/g, '\n');
    const asParag = sText.split(/\n{2,}/);

    for (let i = asParag.length; i--;) {
        if (withoutComments(asParag[i]).trim() === '') {
            continue;
        }

        asParag[i] = asParag[i].replace(/\s+$/gm, '');

        if (Array.from(htmlTags(asParag[i])).some(tag => /^(?:pre|script|style|ol|ul|li|cut)$/.test(tag.name))) {
            continue;
        }

        asParag[i] = asParag[i]
            .replace(/<br \/>((?:[ \t]*<!--.*?-->)*[ \t]*)$/gm, '$1')
            .replace(/^.*$/gm, line => {
                const visible = withoutComments(line).trim();
                const tags = Array.from(htmlTags(visible));
                const first = tags[0];
                const last = tags.at(-1);
                const openingLine = first && !first.closing && first.start === 0 && first.end === visible.length
                    && /^(?:blockquote|p|h[2-4])$/.test(first.name);
                const closingLine = last?.closing && last.end === visible.length
                    && /^(?:blockquote|p|h[2-4])$/.test(last.name);
                const existingBreak = last && !last.closing && last.name === 'br' && last.end === visible.length;
                return visible === '' || openingLine || closingLine || existingBreak ? line : line + '<br />';
            })
            .replace(/<br \/>$/, '');

        const visible = withoutComments(asParag[i]).trim();
        const tags = Array.from(htmlTags(visible));
        const first = tags[0];
        const last = tags.at(-1);
        if (!tags.some(tag => /^(?:blockquote|h[2-4])$/.test(tag.name))) {
            if (!(last?.closing && last.name === 'p' && last.end === visible.length)) {
                asParag[i] = asParag[i].replace(/\s*$/g, '</p>');
            }
            if (!(first && !first.closing && first.name === 'p' && first.start === 0)) {
                asParag[i] = asParag[i].replace(/^\s*/g, '<p>');
            }
        }
    }

    return asParag.join("\n\n");
}
