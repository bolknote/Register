/* Public editor tags: dependencies are supplied by the entry module. */
(() => {
    'use strict';

    function create({clearError, clearStatus, editorConfig, handleEditingSaveShortcut, showError}) {
        const tagSuggestionRequests = new Map();
        let tagEditorSequence = 0;

        function normalizeTags(value) {
            const tags = [];
            const used = new Set();
            for (const part of String(value).split(/[,;\n]+/u)) {
                const tag = part
                    .replace(/^\s*#+\s*/u, '')
                    .replace(/\s+/gu, ' ')
                    .trim();
                if (tag === '') {
                    continue;
                }
                if (Array.from(tag).length > 191 || !/^[\p{L}\p{N}_\- !.]+$/u.test(tag)) {
                    return null;
                }
                const key = tag.toLocaleLowerCase();
                if (!used.has(key)) {
                    used.add(key);
                    tags.push(tag);
                }
                if (tags.length > 100) {
                    return null;
                }
            }
            return tags;
        }

        function loadTagSuggestions(url) {
            const requestUrl = String(url || '').trim();
            if (requestUrl === '') {
                return Promise.resolve([]);
            }

            const pending = tagSuggestionRequests.get(requestUrl);
            if (pending) {
                return pending;
            }

            const request = fetch(requestUrl, {
                credentials: 'same-origin',
                headers: {'X-Requested-With': 'XMLHttpRequest'},
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('Unable to load tag suggestions.');
                    }
                    return response.json();
                })
                .then((payload) => {
                    if (!payload || !Array.isArray(payload.tags)) {
                        return [];
                    }

                    const suggestions = [];
                    const used = new Set();
                    payload.tags.forEach((value) => {
                        const normalized = normalizeTags(value);
                        if (!normalized || normalized.length !== 1) {
                            return;
                        }
                        const tag = normalized[0];
                        const key = tag.toLocaleLowerCase();
                        if (!used.has(key)) {
                            used.add(key);
                            suggestions.push(tag);
                        }
                    });
                    return suggestions;
                })
                .catch((error) => {
                    tagSuggestionRequests.delete(requestUrl);
                    console.warn(error.message);
                    return [];
                });

            tagSuggestionRequests.set(requestUrl, request);
            return request;
        }

        function createTagEditor(state) {
            const root = document.createElement('span');
            const surface = document.createElement('span');
            const input = document.createElement('input');
            const suggestionList = document.createElement('span');
            const suggestionListId = `post-tag-suggestions-${++tagEditorSequence}`;
            let tags = normalizeTags(state.originalTags) || [];
            const originalTags = [...tags];
            let suggestions = [];
            let matches = [];
            let activeIndex = -1;

            root.className = 'post-tags-editor';
            surface.className = 'post-tags-surface';
            input.type = 'text';
            input.className = 'post-tags-text-input';
            input.placeholder = editorConfig().tagsPlaceholder || '';
            input.autocomplete = 'off';
            input.setAttribute('aria-label', editorConfig().tagsLabel || 'Tags');
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-haspopup', 'listbox');
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-controls', suggestionListId);
            suggestionList.id = suggestionListId;
            suggestionList.className = 'post-tag-suggestions';
            suggestionList.hidden = true;
            suggestionList.setAttribute('role', 'listbox');
            suggestionList.setAttribute(
                'aria-label',
                editorConfig().tagSuggestionsLabel || editorConfig().tagsLabel || 'Tag suggestions',
            );
            surface.append(input);
            root.append(surface, suggestionList);
            state.tags.append(root);

            function changed() {
                state.tagsDirty = true;
                clearError(state.form);
                clearStatus(state.card);
            }

            function syncSurface() {
                state.tagsHost.classList.toggle('is-empty', tags.length === 0 && input.value.trim() === '');
            }

            function closeSuggestions() {
                matches = [];
                activeIndex = -1;
                suggestionList.replaceChildren();
                suggestionList.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                input.removeAttribute('aria-activedescendant');
            }

            function setActiveSuggestion(index) {
                if (matches.length === 0) {
                    closeSuggestions();
                    return;
                }

                activeIndex = (index + matches.length) % matches.length;
                suggestionList.querySelectorAll('[role="option"]').forEach((option, optionIndex) => {
                    const active = optionIndex === activeIndex;
                    option.setAttribute('aria-selected', active ? 'true' : 'false');
                    if (active) {
                        input.setAttribute('aria-activedescendant', option.id);
                        option.scrollIntoView({block: 'nearest'});
                    }
                });
            }

            function renderSuggestions(open) {
                if (!open) {
                    closeSuggestions();
                    return;
                }

                const query = input.value.replace(/\s+/gu, ' ').trim().toLocaleLowerCase();
                const selected = new Set(tags.map((tag) => tag.toLocaleLowerCase()));
                matches = suggestions
                    .filter((tag) => {
                        const key = tag.toLocaleLowerCase();
                        return !selected.has(key) && (query === '' || key.includes(query));
                    })
                    .sort((left, right) => {
                        const leftStarts = left.toLocaleLowerCase().startsWith(query);
                        const rightStarts = right.toLocaleLowerCase().startsWith(query);
                        if (leftStarts !== rightStarts) {
                            return leftStarts ? -1 : 1;
                        }
                        return left.localeCompare(right, undefined, {sensitivity: 'base'});
                    })
                    .slice(0, 8);

                suggestionList.replaceChildren();
                activeIndex = -1;
                input.removeAttribute('aria-activedescendant');
                if (matches.length === 0) {
                    closeSuggestions();
                    return;
                }

                const fragment = document.createDocumentFragment();
                matches.forEach((tag, index) => {
                    const option = document.createElement('span');
                    option.id = `${suggestionListId}-${index}`;
                    option.dataset.tag = tag;
                    option.setAttribute('role', 'option');
                    option.setAttribute('aria-selected', 'false');
                    option.textContent = tag;
                    fragment.append(option);
                });
                suggestionList.append(fragment);
                suggestionList.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            }

            function render() {
                surface.querySelectorAll('.post-tag-chip').forEach((chip) => chip.remove());
                const fragment = document.createDocumentFragment();
                tags.forEach((tag, index) => {
                    const chip = document.createElement('span');
                    const label = document.createElement('span');
                    const remove = document.createElement('button');

                    chip.className = 'post-tag-chip';
                    label.className = 'post-tag-chip-label';
                    label.textContent = tag;
                    remove.type = 'button';
                    remove.className = 'post-tag-chip-remove';
                    remove.dataset.tagIndex = String(index);
                    remove.textContent = '×';
                    remove.setAttribute(
                        'aria-label',
                        (editorConfig().removeTagLabel || 'Remove tag') + ': ' + tag,
                    );
                    chip.append(label, remove);
                    fragment.append(chip);
                });
                surface.insertBefore(fragment, input);
                syncSurface();
            }

            function add(value) {
                const additions = normalizeTags(value);
                if (additions === null) {
                    return false;
                }
                const merged = normalizeTags([...tags, ...additions].join(', '));
                if (merged === null) {
                    return false;
                }
                tags = merged;
                input.value = '';
                changed();
                render();
                renderSuggestions(document.activeElement === input);
                return true;
            }

            function commit() {
                if (input.value.trim() === '') {
                    input.value = '';
                    syncSurface();
                    return true;
                }
                return add(input.value);
            }

            surface.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const remove = target?.closest('.post-tag-chip-remove');
                if (remove) {
                    const index = Number(remove.dataset.tagIndex);
                    if (Number.isInteger(index) && index >= 0 && index < tags.length) {
                        tags.splice(index, 1);
                        changed();
                        render();
                        renderSuggestions(true);
                    }
                }
                input.focus();
            });

            input.addEventListener('focus', () => {
                renderSuggestions(true);
            });
            input.addEventListener('input', () => {
                changed();
                syncSurface();
                renderSuggestions(true);
            });
            input.addEventListener('keydown', (event) => {
                if (handleEditingSaveShortcut(event, state)) {
                    event.stopPropagation();
                    return;
                }
                if (event.isComposing) {
                    return;
                }

                if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault();
                    event.stopPropagation();
                    if (suggestionList.hidden) {
                        renderSuggestions(true);
                    }
                    setActiveSuggestion(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
                    return;
                }

                if (event.key === 'Tab' && activeIndex >= 0) {
                    event.preventDefault();
                    const tag = matches[activeIndex];
                    if (tag) {
                        add(tag);
                    }
                    return;
                }

                if (event.key === 'Enter' || event.key === ',' || event.key === ';') {
                    event.preventDefault();
                    event.stopPropagation();
                    const tag = activeIndex >= 0 ? matches[activeIndex] : null;
                    if (tag) {
                        add(tag);
                    } else if (!commit()) {
                        showError(state.form, editorConfig().invalidTags || editorConfig().editError || 'Invalid post tags.');
                    }
                    return;
                }
                if (event.key === 'Backspace' && input.value === '' && tags.length > 0) {
                    event.preventDefault();
                    tags.pop();
                    changed();
                    render();
                    renderSuggestions(true);
                    return;
                }
                if (event.key === 'Escape' && !suggestionList.hidden) {
                    event.preventDefault();
                    event.stopPropagation();
                    closeSuggestions();
                }
            });
            input.addEventListener('paste', (event) => {
                const pasted = event.clipboardData?.getData('text/plain') || '';
                if (!/[,;\n]/u.test(pasted)) {
                    return;
                }
                const value = input.value;
                const start = input.selectionStart ?? value.length;
                const end = input.selectionEnd ?? start;
                const next = value.slice(0, start) + pasted + value.slice(end);
                // Only consume a paste that can be committed in full. Otherwise
                // native insertion leaves the unfinished text available to correct.
                if (add(next)) {
                    event.preventDefault();
                }
            });
            input.addEventListener('blur', () => {
                setTimeout(() => {
                    if (!root.isConnected || !state.card.classList.contains('is-editing') || root.contains(document.activeElement)) {
                        return;
                    }
                    if (!commit()) {
                        showError(state.form, editorConfig().invalidTags || editorConfig().editError || 'Invalid post tags.');
                    }
                    closeSuggestions();
                }, 0);
            });

            suggestionList.addEventListener('mousedown', (event) => {
                event.preventDefault();
            });
            suggestionList.addEventListener('click', (event) => {
                const target = event.target instanceof Element ? event.target : null;
                const option = target?.closest('[role="option"]');
                if (!option) {
                    return;
                }
                const index = Array.from(suggestionList.children).indexOf(option);
                const tag = matches[index];
                if (tag) {
                    add(tag);
                    input.focus();
                }
            });

            render();
            loadTagSuggestions(editorConfig().tagSuggestionsUrl).then((loadedSuggestions) => {
                if (!root.isConnected) {
                    return;
                }
                suggestions = loadedSuggestions;
                if (document.activeElement === input) {
                    renderSuggestions(true);
                }
            });

            return {
                focus: () => input.focus(),
                hasChanges: () => input.value.trim() !== ''
                    || tags.length !== originalTags.length
                    || tags.some((tag, index) => tag !== originalTags[index]),
                sync: () => commit() ? [...tags] : null,
                snapshot: () => [...tags, input.value].filter(Boolean).join(', '),
                restore: value => {
                    const parsed = normalizeTags(value);
                    tags = parsed || [];
                    input.value = parsed === null ? value : '';
                    changed();
                    render();
                },
                replace: (value) => {
                    const replacements = normalizeTags(value);
                    if (replacements === null) {
                        return false;
                    }
                    tags = replacements;
                    input.value = '';
                    changed();
                    render();
                    renderSuggestions(document.activeElement === input);
                    return true;
                },
            };
        }

        return {normalizeTags, loadTagSuggestions, createTagEditor};
    }

    window.RegisterEditorTags = Object.freeze({create});
})();
