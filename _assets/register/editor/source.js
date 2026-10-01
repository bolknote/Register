/* Visual and HTML surfaces share the same post, recovery copy and save action. */
(() => {
    'use strict';

    const assetBase = new URL('../../../', document.currentScript.src);
    const version = new URL(document.currentScript.src).searchParams.get('v');
    let dependencies = null;

    function asset(path) {
        const url = new URL(path, assetBase);
        if (version) url.searchParams.set('v', version);
        return url.href;
    }

    async function loadCodeMirror() {
        if (!dependencies) {
            dependencies = (async () => {
                const style = document.createElement('link');
                style.rel = 'stylesheet';
                style.href = asset('_admin/lib/codemirror.css');
                const styled = new Promise((resolve, reject) => {
                    style.onload = resolve;
                    style.onerror = () => { style.remove(); reject(new Error('Unable to load HTML editor styles')); };
                });
                document.head.append(style);
                await styled;
                for (const file of ['codemirror', 'xml', 'javascript', 'css', 'htmlmixed']) {
                    if (file === 'codemirror' && window.CodeMirror) continue;
                    await new Promise((resolve, reject) => {
                        const script = document.createElement('script');
                        script.src = asset(`_admin/lib/codemirror/${file}.min.js`);
                        script.onload = resolve;
                        script.onerror = () => { script.remove(); reject(new Error('Unable to load HTML editor')); };
                        document.head.append(script);
                    });
                }
                return import(asset('_admin/js/editor/text/html.js'));
            })().catch(error => { dependencies = null; throw error; });
        }
        return dependencies;
    }

    function create({editableBodyHtml, editorConfig, finishCaptions, closeContextMenu, prepareEditableMedia, showEditorStatus, clearError, clearStatus, editorStates}) {
        function createSourceEditor(state) {
            const labels = editorConfig();
            const tabs = document.createElement('div');
            tabs.className = 'post-editor-mode-tabs';
            tabs.setAttribute('role', 'tablist');
            tabs.setAttribute('aria-label', labels.editorModeLabel || 'Editing mode');
            const source = document.createElement('div');
            source.className = 'post-editor-source';
            source.hidden = true;
            const input = document.createElement('textarea');
            input.setAttribute('aria-label', labels.htmlLabel || 'HTML');
            input.spellcheck = false;
            source.append(input);
            const id = `post-editor-${window.RegisterEditorStorage.recordId()}`;
            source.id = `${id}-html`;
            const oldId = state.body.id;
            state.body.id ||= `${id}-visual`;
            const buttons = ['visual', 'html'].map(mode => {
                const button = document.createElement('button');
                button.type = 'button';
                button.dataset.editorMode = mode;
                button.setAttribute('role', 'tab');
                button.setAttribute('aria-controls', mode === 'html' ? source.id : state.body.id);
                button.textContent = mode === 'html' ? (labels.htmlLabel || 'HTML') : (labels.visualLabel || 'Editor');
                tabs.append(button);
                return button;
            });
            state.body.after(source, tabs);
            let cm = null;
            let htmlTools = null;
            let active = false;
            let destroyed = false;
            let switching = false;
            let changing = false;
            let visualSelection = null;
            const controller = new AbortController();
            const value = () => cm ? cm.getValue() : input.value;
            const preserved = () => {
                if (!state.htmlSource) return null;
                if (state.htmlSource.rendered === state.body.innerHTML
                    || state.htmlSource.canonical === editableBodyHtml(state, true)) return state.htmlSource.html;
                // Caption editors temporarily change the DOM, then may cancel.
                // Keep the checkpoint dormant so cancellation can recover the
                // exact source without inventing an undo step.
                return null;
            };
            const checkpoint = html => ({html, rendered: state.body.innerHTML, canonical: editableBodyHtml(state, true)});

            function update() {
                buttons.forEach(button => {
                    const selected = (button.dataset.editorMode === 'html') === active;
                    button.setAttribute('aria-selected', String(selected));
                    button.tabIndex = selected ? 0 : -1;
                });
                tabs.style.top = `${(active ? source : state.body).offsetTop}px`;
            }

            function changed() {
                if (changing || !active || state.submitting) return;
                state.bodyDirty = true;
                clearError(state.form);
                clearStatus(state.card);
                state.recovery?.persist();
            }

            function setValue(html) {
                changing = true;
                if (value() !== html) {
                    if (cm) cm.setValue(html);
                    else input.value = html;
                }
                changing = false;
            }

            function canRender(root) {
                // Keep executable/interactive markup as source text rather than
                // activating it inside the editor or silently stripping it.
                if (root.querySelector('script, iframe, object, embed, base, meta, link, form, input, button, textarea, select, svg, math, template')) return false;
                return !Array.from(root.querySelectorAll('*')).some(node => Array.from(node.attributes).some(attribute => (
                    /^on/iu.test(attribute.name) || attribute.name === 'srcdoc'
                    || (/^(href|src|action|xlink:href)$/iu.test(attribute.name)
                        && /^\s*(javascript|vbscript|data):/iu.test(attribute.value))
                )));
            }

            async function switchMode(mode) {
                if (destroyed || switching || state.submitting || active === (mode === 'html')) return;
                if (mode === 'html') {
                    finishCaptions(state);
                    closeContextMenu(state, false);
                    if (state.mediaUploads.size || state.aiController || state.aiAltTasks.size) {
                        showEditorStatus(state, labels.htmlPending || 'Wait for uploads and AI changes to finish.', true);
                        return;
                    }
                    const selected = window.getSelection();
                    visualSelection = selected?.rangeCount && state.body.contains(selected.getRangeAt(0).commonAncestorContainer)
                        ? selected.getRangeAt(0).cloneRange() : null;
                    state.history?.before();
                    switching = true;
                    tabs.setAttribute('aria-busy', 'true');
                    try {
                        htmlTools = await loadCodeMirror();
                        if (destroyed || state.submitting || editorStates.get(state.card) !== state) return;
                        if (state.mediaUploads.size || state.aiController || state.aiAltTasks.size) {
                            showEditorStatus(state, labels.htmlPending || 'Wait for uploads and AI changes to finish.', true);
                            return;
                        }
                        finishCaptions(state);
                        const latestSelection = window.getSelection();
                        visualSelection = latestSelection?.rangeCount && state.body.contains(latestSelection.getRangeAt(0).commonAncestorContainer)
                            ? latestSelection.getRangeAt(0).cloneRange() : visualSelection;
                        const html = preserved() ?? (state.bodyDirty ? editableBodyHtml(state) : state.originalBody);
                        setValue(html);
                        active = true;
                        state.body.hidden = true;
                        source.hidden = false;
                        state.card.classList.add('is-html-editing');
                        if (!cm) {
                            cm = window.CodeMirror.fromTextArea(input, {
                                mode: 'htmlmixed', lineNumbers: true, lineWrapping: true,
                                indentUnit: 2, tabSize: 2, inputStyle: 'textarea',
                                screenReaderLabel: labels.htmlLabel || 'HTML',
                                extraKeys: {'Cmd-S': () => state.form.requestSubmit(), 'Ctrl-S': () => state.form.requestSubmit()},
                            });
                            cm.on('change', changed);
                        }
                        update();
                        cm.refresh();
                        cm.focus();
                    } catch (_) {
                        showEditorStatus(state, labels.htmlLoadFailed || 'Unable to open the HTML editor. Try again.', true);
                    } finally {
                        switching = false;
                        tabs.removeAttribute('aria-busy');
                    }
                    return;
                }

                const html = value();
                const previous = preserved() ?? (state.bodyDirty ? editableVisualHtml() : state.originalBody);
                if (html !== previous) {
                    const template = document.createElement('template');
                    template.innerHTML = html;
                    if (!canRender(template.content)) {
                        showEditorStatus(state, labels.htmlUnsupported || 'This HTML must be edited in HTML mode. Its source has been retained.', true);
                        return;
                    }
                    state.history?.transact(() => {
                        state.body.replaceChildren(...template.content.childNodes);
                        prepareEditableMedia(state.body);
                        state.htmlSource = checkpoint(html);
                        state.bodyDirty = true;
                    });
                    visualSelection = null;
                } else if (!state.htmlSource) {
                    state.htmlSource = checkpoint(html);
                }
                active = false;
                source.hidden = true;
                state.body.hidden = false;
                state.card.classList.remove('is-html-editing');
                update();
                state.body.focus({preventScroll: true});
                if (visualSelection && state.body.contains(visualSelection.commonAncestorContainer)) {
                    window.getSelection()?.removeAllRanges();
                    window.getSelection()?.addRange(visualSelection);
                }
                state.recovery?.persist();
            }

            function editableVisualHtml() {
                return editableBodyHtml(state, true);
            }

            tabs.addEventListener('mousedown', event => event.preventDefault(), {signal: controller.signal});
            tabs.addEventListener('click', event => {
                const button = event.target.closest('[data-editor-mode]');
                if (button) switchMode(button.dataset.editorMode);
            }, {signal: controller.signal});
            tabs.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                switchMode(event.key === 'Home' ? 'visual' : event.key === 'End' ? 'html' : active ? 'visual' : 'html');
            }, {signal: controller.signal});
            input.addEventListener('input', changed, {signal: controller.signal});
            const resize = new ResizeObserver(update);
            resize.observe(state.card);
            resize.observe(state.body);
            resize.observe(source);
            update();

            function redateSnapshot(snapshot, mediaById) {
                if (!snapshot || !htmlTools) return;
                const replacements = [];
                for (const tag of htmlTools.htmlTags(snapshot.html)) {
                    if (tag.closing) continue;
                    const mediaId = htmlTools.htmlAttribute(tag.text, 'data-post-media-id');
                    const src = htmlTools.htmlAttribute(tag.text, 'src');
                    const media = mediaId ? mediaById.get(Number(mediaId.value)) : null;
                    if (!src || !media?.url) continue;
                    const escaped = media.url.replaceAll('&', '&amp;').replaceAll('"', '&quot;');
                    replacements.push({start: tag.start + src.start, end: tag.start + src.end, text: `src="${escaped}"`});
                }
                replacements.reverse().forEach(replacement => {
                    snapshot.html = snapshot.html.slice(0, replacement.start) + replacement.text + snapshot.html.slice(replacement.end);
                });
            }

            return {
                get active() { return active; },
                get html() { return active ? value() : preserved(); },
                get snapshotSource() { return preserved() === null ? null : {...state.htmlSource}; },
                switchMode,
                focus() { cm?.focus(); },
                restore(html) {
                    // Recovery HTML remains inert text, including unsupported tags.
                    state.htmlSource = checkpoint(html);
                    return switchMode('html');
                },
                redateSnapshot,
                async redateMedia(mediaById, html) {
                    const snapshot = {html};
                    if (typeof snapshot.html !== 'string') return;
                    htmlTools ||= await import(asset('_admin/js/editor/text/html.js'));
                    redateSnapshot(snapshot, mediaById);
                    state.htmlSource = checkpoint(snapshot.html);
                    if (active) setValue(snapshot.html);
                },
                destroy() {
                    destroyed = true;
                    controller.abort();
                    resize.disconnect();
                    cm?.toTextArea();
                    source.remove();
                    tabs.remove();
                    state.body.hidden = false;
                    state.body.id = oldId;
                    state.card.classList.remove('is-html-editing');
                },
            };
        }
        return {createSourceEditor};
    }
    window.RegisterEditorSource = Object.freeze({create});
})();
