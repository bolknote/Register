/** Source ranges for editor operations, without reserializing the author's HTML. */

export function* htmlTags(source) {
    const pattern = /<!--[\s\S]*?(?:-->|$)|<\/?([a-z][a-z0-9:_-]*)(?=[\s/>])(?:[^"'<>]|"[^"]*"|'[^']*')*>/gi;
    let match;
    while ((match = pattern.exec(source)) !== null) {
        if (!match[1]) continue;
        const tag = {
            name: match[1].toLowerCase(),
            closing: match[0].startsWith('</'),
            start: match.index,
            end: pattern.lastIndex,
            text: match[0]
        };
        yield tag;
        // Markup-looking text in these elements is not another HTML element.
        if (!tag.closing && /^(?:script|style|textarea|title)$/u.test(tag.name)) {
            const closing = new RegExp('</' + tag.name + '\\s*>', 'gi');
            closing.lastIndex = tag.end;
            const end = closing.exec(source);
            pattern.lastIndex = end ? end.index : source.length;
        }
    }
}

export function htmlAttribute(tag, name) {
    const prefix = tag.match(/^<\/?[a-z][a-z0-9:_-]*/i)?.[0].length || 0;
    const pattern = /([^\s"'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g;
    pattern.lastIndex = prefix;
    let match;
    while ((match = pattern.exec(tag)) !== null) {
        if (match[1].toLowerCase() === name) {
            return {
                start: match.index,
                end: pattern.lastIndex,
                value: match[2] ?? match[3] ?? match[4] ?? '',
                quote: match[2] !== undefined ? '"' : match[3] !== undefined ? "'" : '"'
            };
        }
    }
    return null;
}

export function paragraphBlocks(source) {
    const stack = [];
    const blocks = [];
    for (const tag of htmlTags(source)) {
        if (!/^(?:p|pre|blockquote|h[2-4])$/u.test(tag.name)) continue;
        if (!tag.closing) {
            stack.push(tag);
        } else {
            const index = stack.findLastIndex(open => open.name === tag.name);
            if (index < 0) continue;
            const open = stack[index];
            stack.splice(index);
            blocks.push({start: open.start, end: tag.end, contentStart: open.end, contentEnd: tag.start});
        }
    }
    return blocks;
}

export function formatParagraph(source, open, close) {
    const outer = [];
    for (const block of paragraphBlocks(source).sort((a, b) => a.start - b.start)) {
        if (outer.length === 0 || block.start >= outer[outer.length - 1].end) outer.push(block);
    }
    let end = 0;
    // Several complete selected blocks keep their individual boundaries too.
    if (outer.length > 0 && outer.every(block => {
        const gap = source.slice(end, block.start);
        end = block.end;
        return gap.trim() === '';
    }) && source.slice(end).trim() === '') {
        let result = '';
        end = 0;
        for (const block of outer) {
            result += source.slice(end, block.start) + open + source.slice(block.contentStart, block.contentEnd) + close;
            end = block.end;
        }
        return result + source.slice(end);
    }
    return open + source + close;
}
