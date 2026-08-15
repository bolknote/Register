document.addEventListener('DOMContentLoaded', function () {
    const form = document.forms.install_form;
    if (!form) {
        return;
    }

    const databaseTypes = form.elements.req_db_type;
    const updateDatabaseFields = function () {
        const isSqlite = databaseTypes.value === 'sqlite';
        ['fld2', 'fld4', 'fld5'].forEach(function (id) {
            const field = document.getElementById(id);
            if (field) {
                field.disabled = isSqlite;
            }
        });
    };

    Array.from(databaseTypes).forEach(function (radio) {
        radio.addEventListener('change', updateDatabaseFields);
    });
    updateDatabaseFields();
});
