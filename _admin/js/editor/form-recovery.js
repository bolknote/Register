import '../../../_assets/register/editor/storage.js';

// Form snapshots contain editable values only, never CSRF/session tokens.
export function createFormRecovery(form, options, onRestore) {
    const fields = Array.from(form.elements).filter(field => field.name
        && field.matches('input, textarea, select')
        && !['hidden', 'file', 'submit', 'button', 'reset', 'image'].includes(field.type));
    const values = field => {
        if (field.matches('select')) return Array.from(field.selectedOptions).map(option => [field.name, option.value]);
        if (['checkbox', 'radio'].includes(field.type) && !field.checked) return [];
        return [[field.name, field.value]];
    };
    const snapshot = data => fields.flatMap(field => {
        if (!data || field.disabled) return values(field);
        const submitted = data.getAll(field.name).filter(value => typeof value === 'string');
        if (['checkbox', 'radio'].includes(field.type)) return submitted.includes(field.value) ? [[field.name, field.value]] : [];
        if (field.matches('select')) return Array.from(field.options)
            .filter(option => submitted.includes(option.value)).map(option => [field.name, option.value]);
        return submitted.length ? [[field.name, submitted[0]]] : [];
    });
    // Browser-restored values remain unsaved: the baseline comes from server defaults.
    let saved = fields.flatMap(field => {
        if (field.matches('select')) {
            const selected = Array.from(field.options).filter(option => option.defaultSelected);
            if (!field.multiple && selected.length === 0 && field.options[0]) selected.push(field.options[0]);
            return selected.map(option => [field.name, option.value]);
        }
        if (['checkbox', 'radio'].includes(field.type) && !field.defaultChecked) return [];
        if (['checkbox', 'radio'].includes(field.type)) return [[field.name, field.value]];
        return [[field.name, field.defaultValue]];
    });
    const baseline = saved;
    let revision = Number(form.elements.revision?.value || 0);
    let store = null;
    try {
        if (Number.isSafeInteger(options.userId) && options.userId > 0 && typeof options.scope === 'string' && options.scope) {
            const prefix = `register:admin-recovery:1:${encodeURIComponent(options.scope)}:${options.userId}:${options.entity}:`;
            store = window.RegisterEditorStorage.createStore(localStorage, prefix, record =>
                /^(?:new|[1-9][0-9]*)$/u.test(record.target)
                && Number.isSafeInteger(record.revision) && record.revision >= 0
                && Array.isArray(record.snapshot) && record.snapshot.length <= 1000
                && record.snapshot.every(field => Array.isArray(field) && field.length === 2
                    && field.every(value => typeof value === 'string')));
        }
    } catch (_) { /* Blocked storage must not block editing. */ }
    const id = store ? window.RegisterEditorStorage.recordId() : '';
    let record = null;
    let last = JSON.stringify(saved);
    let lastRevision = revision;
    const panel = document.createElement('section');
    panel.className = 'editor-recovery';
    panel.setAttribute('aria-live', 'polite');
    const labels = options.labels || {};

    function persist() {
        if (!store) return false;
        const current = snapshot();
        const serialized = JSON.stringify(current);
        if (serialized === last && lastRevision === revision) return true;
        if (serialized === JSON.stringify(saved)) {
            if (record) store.remove(record);
            record = null;
        } else {
            const next = {version: 1, id, target: options.target, revision, savedAt: Date.now(), snapshot: current};
            if (!store.save(next)) {
                panel.textContent = labels.unavailable || 'The local draft could not be saved.';
                if (!panel.isConnected) form.before(panel);
                return false;
            }
            record = next;
        }
        last = serialized;
        lastRevision = revision;
        return true;
    }

    function restore(copy) {
        const values = new Map();
        copy.snapshot.forEach(([name, value]) => {
            if (!values.has(name)) values.set(name, []);
            values.get(name).push(value);
        });
        fields.forEach(field => {
            const entries = values.get(field.name) || [];
            if (['checkbox', 'radio'].includes(field.type)) field.checked = entries.includes(field.value);
            else if (field.matches('select')) Array.from(field.options).forEach(option => { option.selected = entries.includes(option.value); });
            else field.value = entries[0] ?? '';
        });
        onRestore();
        fields.forEach(field => {
            field.dispatchEvent(new Event('input', {bubbles: true}));
            field.dispatchEvent(new Event('change', {bubbles: true}));
        });
        form.dispatchEvent(new Event('publication-state-change'));
        // Keep the old copy if the new tab could not persist its restored values.
        if (persist()) store.remove(copy);
        render();
    }

    function render() {
        panel.replaceChildren();
        const copies = store?.list(options.target).filter(copy => copy.id !== id) || [];
        copies.forEach(copy => {
            const row = document.createElement('div');
            const description = document.createElement('p');
            description.textContent = (copy.revision === revision
                ? labels.found || 'A local draft is available.'
                : labels.changed || 'The server version changed since this draft was saved.')
                + ' ' + new Date(copy.savedAt).toLocaleString()
                + ' — ' + (copy.snapshot.find(([name]) => name === 'title')?.[1] || '').slice(0, 120);
            const restoreButton = document.createElement('button');
            restoreButton.type = 'button';
            restoreButton.textContent = labels.restore || 'Restore draft';
            restoreButton.addEventListener('click', () => restore(copy));
            const discardButton = document.createElement('button');
            discardButton.type = 'button';
            discardButton.textContent = labels.discard || 'Discard draft';
            discardButton.addEventListener('click', () => { store.remove(copy); render(); });
            row.append(description, restoreButton, discardButton);
            panel.append(row);
        });
        if (copies.length) form.before(panel);
        else panel.remove();
    }

    form.addEventListener('input', persist);
    form.addEventListener('change', persist);
    window.addEventListener('storage', render);
    render();
    return {
        baseline, snapshot, persist,
        markSaved(submitted, nextRevision) {
            saved = submitted;
            revision = Number(nextRevision ?? revision);
            last = null;
            persist();
        },
    };
}
