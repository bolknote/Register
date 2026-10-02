(() => {
    const params = new URL(location.href).searchParams;
    const user = Number(params.get('account') || 1);
    const revision = Number(params.get('revision') || 1);
    const config = {
        aiAltEnabled: false,
        titlePlaceholder: 'New post',
        tagSuggestionsUrl: '/_inplace/tags',
        recoveryUserId: user,
        saveTimeoutMs: Number(params.get('timeout')) || 30000,
        recoveryFound: 'This device has unsaved text:',
        recoveryChanged: 'The site has a newer version. Review it before saving.',
        recoveryRestore: 'Restore text', recoveryDiscard: 'Delete local copy',
    };
    document.getElementById('post-editor-resources').dataset.config = JSON.stringify(config);
    function post(creating) {
        const card = document.createElement('article');
        card.className = 'post-card' + (user ? ' is-manageable' : '') + (creating ? ' is-creating' : '');
        card.dataset.postId = creating ? '0' : '9';
        if (creating) card.dataset.postCreating = '';
        const title = creating ? '' : `Server title ${revision}`;
        const body = creating ? '' : `<p>Server body ${revision}</p>`;
        card.innerHTML = `
            <form id="form-${creating ? 'new' : '9'}" class="post-inplace-edit-form" action="/_inplace/post/${creating ? 'new' : '9'}" hidden>
                <input name="title" type="hidden"><textarea name="body" hidden></textarea>
                <input name="tags" type="hidden" value="old"><input name="published_at" type="hidden" value="1788696000">
                <input name="uploaded_media_ids" type="hidden">
                ${creating ? '' : '<input name="slug" type="hidden" value="server-slug">'}
                <input name="inplace_action" type="hidden" value="${creating ? 'create' : 'edit'}">
                <input name="revision" type="hidden" value="${creating ? 0 : revision}">
                <input name="inplace_token" type="hidden" value="private-fixture-token">
            </form>
            <p class="post-inplace-error post-inplace-edit-error" hidden></p><p class="post-inplace-status" hidden></p>
            <h2 class="post head"><a href="/server-slug"><span class="post-title-text" data-post-inplace-title></span></a></h2>
            <div class="post time"><time datetime="2026-09-06T12:00:00Z" data-local-time="datetime" data-locale="ru">6 September</time>
                <button type="button" class="post-inplace-date-button" hidden>Date</button>
                <input class="post-inplace-datetime" type="datetime-local" step="1" hidden></div>
            <nav class="post-inplace-tools">
                <button class="post-inplace-button post-edit-start" type="button">Edit</button>
                <button class="post-inplace-button post-edit-menu" type="button" aria-label="Editor tools" aria-haspopup="menu" aria-expanded="false" hidden>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h3m4 0h9M4 12h9m4 0h3M4 18h3m4 0h9"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="9" cy="18" r="2"/></svg>
                </button>
                <button class="post-inplace-button post-edit-save" type="button" hidden>Save</button>
                <button class="post-inplace-button post-edit-cancel" type="button" hidden>Cancel</button>
            </nav>
            <div class="post body" data-post-inplace-body></div>
            <div class="post foot"><div class="post-foot-meta"><span class="post-foot-tags"><span data-post-inplace-tags-values>old</span></span></div></div>`;
        card.querySelector('[data-post-inplace-title]').textContent = title;
        card.querySelector('[data-post-inplace-body]').innerHTML = body;
        card.querySelector('[name="title"]').value = title;
        card.querySelector('[name="body"]').value = body;
        if (!user) card.querySelector('.post-inplace-tools').remove();
        return card;
    }
    document.querySelector('.live-post-feed').append(post(false));
    if (user) {
        const slot = document.createElement('div');
        slot.dataset.postCreateSlot = '';
        slot.innerHTML = '<button class="post-create-start" type="button">New post</button><template class="post-create-template"></template>';
        slot.querySelector('template').content.append(post(true));
        document.getElementById('content').prepend(slot);
    }
})();
