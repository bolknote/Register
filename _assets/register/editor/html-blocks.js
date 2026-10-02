/* HTML is an explicit, indivisible author-controlled block, not an editor mode. */
(() => {
    'use strict';
    const selector = 'div[data-post-html-source]';
    const sandbox = 'allow-scripts allow-forms allow-modals allow-downloads allow-popups allow-presentation';
    const editPath = 'm8 5-6 7 6 7m8-14 6 7-6 7m-3-17-2 20';
    const donePath = 'm5 12 4 4L19 6';

    function toolIcon(path) {
        const namespace = 'http://www.w3.org/2000/svg';
        const icon = document.createElementNS(namespace, 'svg');
        icon.setAttribute('viewBox', '0 0 24 24');
        icon.setAttribute('aria-hidden', 'true');
        icon.setAttribute('focusable', 'false');
        const shape = document.createElementNS(namespace, 'path');
        shape.setAttribute('d', path);
        icon.append(shape);
        return icon;
    }

    function block(source = '') {
        const node = document.createElement('div');
        node.className = 'post-html-block';
        node.dataset.postHtmlSource = source;
        return node;
    }

    // Runtime controls and frames must never enter history, recovery or saved HTML.
    function strip(root) {
        root.querySelectorAll(selector).forEach(node => {
            node.replaceChildren();
            node.removeAttribute('contenteditable');
            node.classList.remove('is-editing-html-block');
        });
    }

    function editableHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        strip(template.content);
        return template.innerHTML;
    }

    function serialize(root) {
        const replacements = [];
        root.querySelectorAll(selector).forEach(node => {
            const marker = `register-html-${crypto.randomUUID()}`;
            const source = node.dataset.postHtmlSource;
            node.replaceChildren(document.createComment(marker));
            replacements.push([`<!--${marker}-->`, source]);
        });
        let html = root.innerHTML;
        replacements.forEach(([marker, source]) => { html = html.replace(marker, () => source); });
        return html;
    }

    function protectForAi(html) {
        const template = document.createElement('template');
        template.innerHTML = html;
        const blocks = Array.from(template.content.querySelectorAll(selector));
        if (blocks.length === 0) return {text: html, prose: html, restore: result => result};
        const replacements = blocks.map(node => {
            const token = `REGISTER_HTML_BLOCK_${crypto.randomUUID().replaceAll('-', '')}`;
            const holder = document.createElement('div');
            holder.append(node.cloneNode(true));
            strip(holder);
            const original = serialize(holder);
            node.replaceWith(document.createTextNode(token));
            return {token, original};
        });
        return {
            text: template.innerHTML,
            prose: replacements.reduce((text, {token}) => text.replace(token, ''), template.innerHTML),
            restore(result) {
                // An editorial operation may change prose, never the embedded program.
                if (replacements.some(({token}) => result.split(token).length !== 2)) return null;
                replacements.forEach(({token, original}) => { result = result.replace(token, () => original); });
                return result;
            },
        };
    }

    function create(state, {config, focusAfter}) {
        const sessions = new Map();
        function dispose(node, session) {
            clearTimeout(session.timer);
            session.controller.abort();
            session.form.remove();
            sessions.delete(node);
        }
        function prepare() {
            sessions.forEach((session, node) => {
                if (!state.body.contains(node)) dispose(node, session);
            });
            state.body.querySelectorAll(selector).forEach(node => {
                if (sessions.has(node)) return;
                const labels = config();
                const controller = new AbortController();
                const listen = (element, event, callback) => element.addEventListener(event, callback, {signal: controller.signal});
                node.replaceChildren();
                node.classList.add('post-html-block', 'is-editing-html-block');
                node.contentEditable = 'false';
                const header = document.createElement('div');
                header.className = 'post-html-block-tools';
                const title = document.createElement('strong');
                title.textContent = labels.htmlBlockLabel || 'HTML';
                const actions = document.createElement('div');
                actions.className = 'post-html-block-actions';
                const edit = document.createElement('button');
                edit.type = 'button';
                edit.className = 'post-html-block-edit';
                const editIcon = toolIcon(editPath);
                const editLabel = document.createElement('span');
                editLabel.textContent = labels.htmlBlockEdit || 'Edit code';
                edit.append(editIcon, editLabel);
                edit.setAttribute('aria-expanded', 'false');
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'post-html-block-remove';
                remove.append(toolIcon('M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7'),
                    document.createTextNode(labels.htmlBlockRemove || 'Remove block'));
                actions.append(edit, remove);
                header.append(title, actions);
                const code = document.createElement('textarea');
                code.className = 'post-html-block-code';
                code.setAttribute('aria-label', labels.htmlBlockCode || 'HTML code');
                code.spellcheck = false;
                code.hidden = true;
                code.value = node.dataset.postHtmlSource;
                const frame = document.createElement('iframe');
                frame.className = 'post-html-block-preview';
                frame.title = labels.htmlBlockPreview || 'HTML preview';
                frame.setAttribute('sandbox', sandbox);
                frame.name = `register-html-${crypto.randomUUID()}`;
                frame.hidden = node.dataset.postHtmlSource.trim() === '';
                const form = document.createElement('form');
                form.hidden = true;
                form.method = 'post';
                form.action = state.form.action;
                form.target = frame.name;
                document.body.append(form);
                const session = {controller, form, timer: null, key: null, code, edit};
                sessions.set(node, session);
                function preview() {
                    clearTimeout(session.timer);
                    if (!state.body.contains(node)) return;
                    session.key = crypto.randomUUID();
                    frame.hidden = node.dataset.postHtmlSource.trim() === '';
                    if (frame.hidden) return;
                    const theme = getComputedStyle(state.body);
                    const panelTheme = getComputedStyle(node);
                    const fields = {
                        inplace_action: 'html_preview',
                        inplace_token: state.form.elements.namedItem('inplace_token')?.value || '',
                        html_source: node.dataset.postHtmlSource,
                        html_preview_key: session.key,
                        html_base: location.href,
                        html_font: theme.font,
                        html_color: theme.color,
                        html_backgroundColor: panelTheme.backgroundColor,
                        html_colorScheme: panelTheme.colorScheme,
                    };
                    form.replaceChildren();
                    Object.entries(fields).forEach(([name, value]) => {
                        const input = document.createElement('input');
                        input.type = 'hidden'; input.name = name; input.value = value;
                        form.append(input);
                    });
                    form.submit();
                }
                listen(window, 'message', event => {
                    if (event.source !== frame.contentWindow || event.data?.registerHtmlPreview !== session.key) return;
                    const height = Number(event.data.height);
                    if (Number.isFinite(height)) frame.style.height = `${Math.max(40, Math.min(height, 20000))}px`;
                });
                listen(edit, 'click', () => {
                    code.hidden = !code.hidden;
                    edit.setAttribute('aria-expanded', String(!code.hidden));
                    editLabel.textContent = code.hidden ? (labels.htmlBlockEdit || 'Edit code') : (labels.htmlBlockDone || 'Done');
                    editIcon.firstElementChild.setAttribute('d', code.hidden ? editPath : donePath);
                    if (!code.hidden) code.focus();
                    else { preview(); focusAfter(node); }
                });
                listen(remove, 'click', () => {
                    state.history?.before();
                    focusAfter(node);
                    dispose(node, session);
                    node.remove();
                    state.bodyDirty = true;
                    state.history?.record();
                });
                listen(code, 'beforeinput', event => {
                    event.stopPropagation();
                    state.history?.before(event.inputType);
                });
                listen(code, 'input', event => {
                    event.stopPropagation();
                    node.dataset.postHtmlSource = code.value;
                    frame.hidden = code.value.trim() === '';
                    if (frame.hidden) session.key = null;
                    state.bodyDirty = true;
                    state.history?.record(event.inputType);
                    state.recovery?.persist();
                    clearTimeout(session.timer);
                    session.timer = setTimeout(preview, 400);
                });
                // Native code-field editing must not invoke paragraph/caption handlers.
                listen(node, 'keydown', event => {
                    if ((event.metaKey || event.ctrlKey) && (event.code === 'KeyS' || event.key.toLowerCase() === 's')) return;
                    event.stopPropagation();
                });
                listen(node, 'contextmenu', event => {
                    if (event.target === code) return;
                    event.preventDefault();
                    event.stopPropagation();
                });
                ['paste', 'copy', 'cut', 'contextmenu'].forEach(event => listen(code, event, e => e.stopPropagation()));
                node.append(header, code, frame);
                preview();
            });
        }
        return {
            prepare,
            edit(node) { prepare(); sessions.get(node)?.edit.click(); },
            destroy() { sessions.forEach((session, node) => dispose(node, session)); },
        };
    }

    window.RegisterEditorHtmlBlocks = Object.freeze({block, strip, editableHtml, serialize, protectForAi, create});
})();
