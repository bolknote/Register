/** Plain text for image descriptions and publication previews. */

export function normalizePlainText(text) {
    // Joining controls are part of words and emoji, not whitespace.
    return text.replace(/[\u00a0\u200b\ufeff]/g, ' ').replace(/\s+/g, ' ').trim();
}

export function htmlToPlainText(html, omitCode = false, preferLead = false) {
    const body = new DOMParser().parseFromString(String(html), 'text/html').body;
    body.querySelectorAll('script, style, template, noscript, svg, math' + (omitCode ? ', pre, code' : ''))
        .forEach(element => element.replaceWith(' '));

    // Only a real cut element outside omitted content divides the article.
    const cut = preferLead ? body.querySelector('cut') : null;
    if (cut) {
        const range = body.ownerDocument.createRange();
        range.selectNodeContents(body);
        range.setEndBefore(cut);
        const lead = textFromNodes(range.cloneContents());
        if (lead) return lead;
    }
    return textFromNodes(body);
}

function textFromNodes(root) {
    // textContent alone joins adjacent paragraphs and ignores line breaks.
    root.querySelectorAll('address, article, aside, blockquote, br, dd, div, dl, dt, figcaption, figure, footer, '
        + 'h1, h2, h3, h4, h5, h6, header, hr, li, main, nav, ol, p, pre, section, table, tbody, td, tfoot, th, thead, tr, ul')
        .forEach(element => {
            element.before('\n');
            element.after('\n');
        });
    return (root.textContent || '').replace(/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/g, ' ')
        .replace(/[\t ]+/g, ' ').replace(/ *[\r\n]+ */g, '\n').trim();
}
