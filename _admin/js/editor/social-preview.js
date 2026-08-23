/** Keeps the Open Graph card preview in sync with the editor fields. */

function firstImage(html) {
    if (!html) {
        return '';
    }

    const documentFragment = new DOMParser().parseFromString(String(html), 'text/html');
    return documentFragment.querySelector('img[src]')?.getAttribute('src')?.trim() || '';
}

function plainText(html) {
    if (!html) {
        return '';
    }

    const documentFragment = new DOMParser().parseFromString(String(html), 'text/html');
    return (documentFragment.body.textContent || '').replace(/\s+/g, ' ').trim();
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
        const body = inputValue(form, 'body');
        const imageUrl = inputValue(form, 'social_image') || firstImage(body) || config.defaultImage || '';
        const descriptionText = inputValue(form, 'meta_description') || plainText(body).slice(0, 220);

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
    render();
}

export {initSocialPreview};
