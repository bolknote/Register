/**
 * Token-based tags input for the content editor.
 *
 * @copyright 2009-2026 Roman Parpalak
 * @license   https://opensource.org/license/mit MIT
 * @package   S2
 */

function normalizeTag(value) {
    return String(value)
        .replace(/^\s*#+\s*/u, '')
        .replace(/\s+/gu, ' ')
        .trim();
}

function parseTags(value) {
    return String(value)
        .split(/[,;\n]+/u)
        .map(normalizeTag)
        .filter(Boolean);
}

function uniqueTags(values) {
    const result = [];
    const used = new Set();
    values.forEach(function (value) {
        const tag = normalizeTag(value);
        const key = tag.toLocaleLowerCase();
        if (tag !== '' && !used.has(key)) {
            used.add(key);
            result.push(tag);
        }
    });
    return result;
}

export function initTagsInput(config) {
    const sourceInput = document.getElementById(config.inputId);
    if (!(sourceInput instanceof HTMLInputElement)) {
        return;
    }

    const suggestions = uniqueTags(config.suggestions || []);
    const suggestionListId = config.inputId + '-suggestions';
    const root = document.createElement('div');
    const surface = document.createElement('div');
    const input = document.createElement('input');
    const suggestionList = document.createElement('ul');
    let tags = uniqueTags(parseTags(sourceInput.value));
    let matches = [];
    let activeIndex = -1;
    let internalUpdate = false;

    root.className = 'editor-tags-input';
    surface.className = 'editor-tags-surface';
    input.type = 'text';
    input.className = 'editor-tags-text-input';
    input.placeholder = config.placeholder || '';
    input.autocomplete = 'off';
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-haspopup', 'listbox');
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-controls', suggestionListId);

    suggestionList.id = suggestionListId;
    suggestionList.className = 'editor-tag-suggestions';
    suggestionList.hidden = true;
    suggestionList.setAttribute('role', 'listbox');
    suggestionList.setAttribute('aria-label', config.suggestionsLabel || config.placeholder || 'Tags');

    surface.append(input);
    root.append(surface, suggestionList);
    sourceInput.hidden = true;
    sourceInput.tabIndex = -1;
    sourceInput.setAttribute('aria-hidden', 'true');
    sourceInput.insertAdjacentElement('afterend', root);

    function syncSourceInput() {
        internalUpdate = true;
        sourceInput.value = tags.join(', ');
        sourceInput.dispatchEvent(new Event('input', {bubbles: true}));
        sourceInput.dispatchEvent(new Event('change', {bubbles: true}));
        internalUpdate = false;
    }

    function renderTags() {
        surface.querySelectorAll('.editor-tag-chip').forEach(function (chip) {
            chip.remove();
        });

        const fragment = document.createDocumentFragment();
        tags.forEach(function (tag, index) {
            const chip = document.createElement('span');
            const label = document.createElement('span');
            const removeButton = document.createElement('button');

            chip.className = 'editor-tag-chip';
            label.className = 'editor-tag-chip-label';
            label.textContent = tag;
            removeButton.type = 'button';
            removeButton.className = 'editor-tag-chip-remove';
            removeButton.dataset.tagIndex = String(index);
            removeButton.textContent = '×';
            removeButton.setAttribute('aria-label', (config.removeLabel || 'Remove tag') + ': ' + tag);
            chip.append(label, removeButton);
            fragment.append(chip);
        });
        surface.insertBefore(fragment, input);
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
        suggestionList.querySelectorAll('[role="option"]').forEach(function (option, optionIndex) {
            const isActive = optionIndex === activeIndex;
            option.setAttribute('aria-selected', isActive ? 'true' : 'false');
            if (isActive) {
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

        const query = normalizeTag(input.value).toLocaleLowerCase();
        const selected = new Set(tags.map(function (tag) {
            return tag.toLocaleLowerCase();
        }));

        matches = suggestions
            .filter(function (tag) {
                const key = tag.toLocaleLowerCase();
                return !selected.has(key) && (query === '' || key.includes(query));
            })
            .sort(function (left, right) {
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
        if (matches.length === 0) {
            closeSuggestions();
            return;
        }

        const fragment = document.createDocumentFragment();
        matches.forEach(function (tag, index) {
            const option = document.createElement('li');
            option.id = suggestionListId + '-' + index;
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

    function addTags(values) {
        const existing = new Set(tags.map(function (tag) {
            return tag.toLocaleLowerCase();
        }));
        let changed = false;

        values.forEach(function (value) {
            const tag = normalizeTag(value);
            const key = tag.toLocaleLowerCase();
            if (tag !== '' && !existing.has(key)) {
                tags.push(tag);
                existing.add(key);
                changed = true;
            }
        });

        if (changed) {
            renderTags();
            syncSourceInput();
        }
        return changed;
    }

    function commitInput() {
        const values = parseTags(input.value);
        if (values.length === 0) {
            return false;
        }
        addTags(values);
        input.value = '';
        renderSuggestions(document.activeElement === input);
        return true;
    }

    function chooseSuggestion(index) {
        const tag = matches[index];
        if (!tag) {
            return;
        }
        addTags([tag]);
        input.value = '';
        renderSuggestions(true);
    }

    surface.addEventListener('click', function (event) {
        const removeButton = event.target.closest('.editor-tag-chip-remove');
        if (removeButton) {
            const index = Number(removeButton.dataset.tagIndex);
            if (Number.isInteger(index) && index >= 0 && index < tags.length) {
                tags.splice(index, 1);
                renderTags();
                syncSourceInput();
                renderSuggestions(true);
                input.focus();
            }
            return;
        }
        input.focus();
    });

    input.addEventListener('focus', function () {
        renderSuggestions(true);
    });
    input.addEventListener('input', function () {
        renderSuggestions(true);
    });
    input.addEventListener('keydown', function (event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (suggestionList.hidden) {
                renderSuggestions(true);
            }
            setActiveSuggestion(activeIndex + (event.key === 'ArrowDown' ? 1 : -1));
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            if (activeIndex >= 0) {
                chooseSuggestion(activeIndex);
            } else {
                commitInput();
            }
            return;
        }
        if (event.key === ',' || event.key === ';') {
            event.preventDefault();
            commitInput();
            return;
        }
        if (event.key === 'Backspace' && input.value === '' && tags.length > 0) {
            tags.pop();
            renderTags();
            syncSourceInput();
            renderSuggestions(true);
            return;
        }
        if (event.key === 'Escape') {
            closeSuggestions();
        }
    });
    input.addEventListener('paste', function (event) {
        const pastedText = event.clipboardData && event.clipboardData.getData('text');
        if (!pastedText || !/[,;\n]/u.test(pastedText)) {
            return;
        }
        event.preventDefault();
        addTags(parseTags(pastedText));
        input.value = '';
        renderSuggestions(true);
    });
    input.addEventListener('blur', function () {
        setTimeout(function () {
            if (!root.contains(document.activeElement)) {
                commitInput();
                closeSuggestions();
            }
        }, 0);
    });

    suggestionList.addEventListener('mousedown', function (event) {
        event.preventDefault();
    });
    suggestionList.addEventListener('click', function (event) {
        const option = event.target.closest('[role="option"]');
        if (!option) {
            return;
        }
        chooseSuggestion(Array.from(suggestionList.children).indexOf(option));
        input.focus();
    });

    sourceInput.addEventListener('input', function () {
        if (internalUpdate) {
            return;
        }
        tags = uniqueTags(parseTags(sourceInput.value));
        input.value = '';
        renderTags();
        closeSuggestions();
    });
    sourceInput.addEventListener('focus_tag_editor.s2', function () {
        input.focus();
        renderSuggestions(true);
    });

    const form = sourceInput.closest('form');
    if (form) {
        form.addEventListener('submit', commitInput, {capture: true});
        form.addEventListener('reset', function () {
            setTimeout(function () {
                tags = uniqueTags(parseTags(sourceInput.value));
                input.value = '';
                renderTags();
                closeSuggestions();
            }, 0);
        });
    }

    document.addEventListener('pointerdown', function (event) {
        if (!root.contains(event.target)) {
            closeSuggestions();
        }
    });

    renderTags();
}
