/** Read global and field validation messages from an admin form response. */

function nonemptyStrings(values) {
    return Array.isArray(values)
        ? values.filter(value => typeof value === 'string' && value.trim() !== '') : [];
}

export function formErrorMessages(form, data, fallback) {
    const errors = nonemptyStrings(data?.errors);
    const fields = data?.field_errors;
    if (fields && typeof fields === 'object' && !Array.isArray(fields)) {
        for (const [name, values] of Object.entries(fields)) {
            const control = form.elements.namedItem(name);
            const input = control instanceof RadioNodeList ? control[0] : control;
            const label = input?.getAttribute('aria-label')?.trim() || input?.labels?.[0]?.textContent.trim();
            errors.push(...nonemptyStrings(values).map(error => label ? `${label}: ${error}` : error));
        }
    }
    return errors.length ? errors
        : [typeof data?.message === 'string' && data.message.trim() !== '' ? data.message : fallback];
}
