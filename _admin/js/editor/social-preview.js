/** Keeps the Open Graph card preview in sync with the editor fields. */

import {register_codemirror} from './codemirror.js';
import {htmlToPlainText, normalizePlainText} from './text/plain.js';

function firstImage(html) {
    if (!html) {
        return '';
    }

    const documentFragment = new DOMParser().parseFromString(String(html), 'text/html');
    return documentFragment.querySelector('img[src]')?.getAttribute('src')?.trim() || '';
}

function descriptionFromBody(html, title) {
    // Keep the local fallback consistent with PublicationMetadataGenerator.
    const normalizedTitle = normalizePlainText(title).toLowerCase();
    const withoutTitle = text => {
        const lines = text.split(/\n+/).map(normalizePlainText).filter(Boolean);
        if (lines[0]?.toLowerCase() === normalizedTitle) lines.shift();
        return lines.join(' ');
    };
    // Test the lead after removing its title and invisible whitespace.
    const text = withoutTitle(htmlToPlainText(html, true, true)) || withoutTitle(htmlToPlainText(html, true));
    const characters = Array.from(text);
    if (characters.length <= 160) return text;

    let summary = '';
    for (const sentence of text.split(/(?<=[.!?…])\s+/u)) {
        const candidate = summary ? summary + ' ' + sentence : sentence;
        if (Array.from(candidate).length > 160) break;
        summary = candidate;
    }
    if (summary) return summary;
    const prefix = characters.slice(0, 159).join('').trimEnd();
    return prefix.replace(/^(.+)\s+\S*$/u, '$1').trimEnd() + '…';
}

function inputValue(form, name) {
    const control = form.elements[name];
    return control && typeof control.value === 'string' ? control.value.trim() : '';
}

function initSocialPreview(form, config = {}) {
    const preview = document.querySelector('[data-social-preview]');
    if (!preview) {
        return;
    }

    const image = preview.querySelector('[data-social-preview-image]');
    const site = preview.querySelector('[data-social-preview-site]');
    const title = preview.querySelector('[data-social-preview-title]');
    const description = preview.querySelector('[data-social-preview-description]');

    const render = function () {
        const body = register_codemirror.isReady() ? register_codemirror.getValue() : inputValue(form, 'body');
        const imageUrl = inputValue(form, 'social_image') || firstImage(body) || config.defaultImage || '';
        const descriptionText = inputValue(form, 'meta_description') || descriptionFromBody(body, inputValue(form, 'title'));

        site.textContent = config.siteName || window.location.hostname;
        title.textContent = inputValue(form, 'title') || config.emptyTitle || 'Untitled';
        description.textContent = descriptionText || config.emptyText || '';

        if (imageUrl) {
            image.style.backgroundImage = 'url(' + JSON.stringify(imageUrl) + ')';
            image.hidden = false;
        } else {
            image.style.backgroundImage = '';
            image.hidden = true;
        }
    };

    form.addEventListener('input', render);
    form.addEventListener('change', render);
    register_codemirror.onChange(render);
    render();
}

export {initSocialPreview};
