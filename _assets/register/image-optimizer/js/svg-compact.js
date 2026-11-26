const svgNamespace = 'http://www.w3.org/2000/svg';
const manifestNamespace = 'http://c2pa.org/manifest';
const structuralElements = new Set(['svg', 'g', 'defs', 'symbol', 'pattern', 'marker', 'clipPath', 'mask']);

// Exporters often repeat the same 8-bit RGB paints on every shape. Only
// shorten whole paint declarations in flat CSS; leave arbitrary CSS verbatim.
function compactPaints(style) {
    if (!/rgb\(/iu.test(style)) return style;
    const flat = style.replace(/"[^"\\;]*"|'[^'\\;]*'|\([^()'";\\]*\)/gu, '');
    if (/[^-\w \t\r\n:;,.#%+!]/u.test(flat)) return style;
    return style.replace(
        /((?:^|;)[ \t\r\n]*(?:fill|stroke|color|stop-color|flood-color|lighting-color)[ \t\r\n]*:[ \t\r\n]*)rgb\(([\d, \t\r\n]+)\)(?=[ \t\r\n]*(?:;|$))/gi,
        (match, property, argumentsText) => {
            const channels = argumentsText.split(',').map(value => value.trim());
            if (channels.length !== 3 || channels.some(value => !/^\d{1,3}$/u.test(value) || Number(value) > 255)) return match;
            const hex = channels.map(value => Number(value).toString(16).padStart(2, '0')).join('');
            return property + '#' + hex.replace(/^([a-f\d])\1([a-f\d])\2([a-f\d])\3$/u, '$1$2$3');
        },
    );
}

// XMLSerializer omits the XML declaration and the internal DTD subset. Keep the
// actual prolog, including processing instructions, when emitting UTF-8.
function xmlProlog(source) {
    const tokens = /<\?[\s\S]*?\?>|<!--[\s\S]*?-->|"[^"]*"|'[^']*'|\[|\]|<(?=[^!?/])/gu;
    let subsetDepth = 0;
    for (const match of source.matchAll(tokens)) {
        if (match[0] === '[') ++subsetDepth;
        else if (match[0] === ']') --subsetDepth;
        else if (match[0] === '<' && subsetDepth === 0) {
            return source.slice(0, match.index).replace(/^(<\?xml\s[^?]*?\bencoding\s*=\s*["'])[^"']+/u, '$1UTF-8');
        }
    }
    throw new Error('The SVG root is missing.');
}

export function compactSvg(original, source, document) {
    // Match the namespace and location, not the prefix chosen by the exporter.
    // Other metadata can include attribution or licence terms.
    let removedManifest = false;
    for (const manifest of Array.from(document.getElementsByTagNameNS(manifestNamespace, 'manifest'))) {
        const parent = manifest.parentElement;
        if (parent?.namespaceURI === svgNamespace && parent.localName === 'metadata') {
            manifest.remove();
            removedManifest = true;
        }
    }

    const root = document.documentElement;
    const elements = [root, ...root.getElementsByTagName('*')];
    // Scripts may inspect childNodes, and arbitrary XML or HTML may give
    // whitespace its own meaning. Keep those documents' authored DOM intact.
    const staticDrawing = !document.doctype
        && !document.createTreeWalker(document, NodeFilter.SHOW_PROCESSING_INSTRUCTION).nextNode()
        && elements.every(element => element.namespaceURI === svgNamespace
            && !/^(?:script|foreignObject|style|a|animate.*|set|discard)$/u.test(element.localName)
            && !element.getAttributeNames().some(name => /^on/iu.test(name)));
    if (!staticDrawing && !removedManifest) return original;
    if (staticDrawing) {
        for (const element of elements) {
            if (element.closest('metadata')) continue;
            if (element.hasAttribute('style')) {
                element.setAttribute('style', compactPaints(element.getAttribute('style')));
            }
            if (element.localName === 'path' && element.hasAttribute('d')) {
                // Path commands delimit their arguments without spaces. Keep every
                // number verbatim, including arc flags, exponents and precision.
                const parts = element.getAttribute('d').split(/([MmZzLlHhVvCcSsQqTtAa])/u);
                element.setAttribute('d', parts.map(part => part.replace(/[\t\n\r ]+/gu, ' ').replace(/^ | $/gu, '')).join(''));
            }
            if (!structuralElements.has(element.localName) || !element.childElementCount
                || element.closest('[*|space="preserve"]')) continue;
            for (const child of Array.from(element.childNodes)) {
                if (child.nodeType === Node.TEXT_NODE && /^[\t\n\r ]+$/u.test(child.data)) child.remove();
            }
        }
    }

    const serializer = new XMLSerializer();
    const parts = [xmlProlog(source)];
    for (let node = root; node; node = node.nextSibling) parts.push(serializer.serializeToString(node));
    const compact = new Blob(parts, {type: 'image/svg+xml'});
    return removedManifest || compact.size < original.size ? compact : original;
}
