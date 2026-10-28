(() => {
    const selector = 'details[data-header-search]';
    const close = search => {
        search.open = false;
        const suggestions = search.querySelector('#register_search_tip');
        if (suggestions) suggestions.hidden = true;
    };

    // Delegated listeners also cover a header replaced by partial navigation.
    document.addEventListener('toggle', event => {
        const search = event.target;
        if (!(search instanceof HTMLDetailsElement) || !search.matches(selector)) return;
        if (search.open) {
            document.querySelectorAll('.public-auth-user-menu[open]').forEach(menu => { menu.open = false; });
            search.querySelector('input[type="search"]')?.focus();
        }
    }, true);

    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || !(event.target instanceof Element)) return;
        const search = event.target.closest(selector);
        if (!search?.open) return;
        // Capture before autocomplete's Escape handler schedules a refocus.
        event.preventDefault();
        event.stopPropagation();
        close(search);
        search.querySelector('summary')?.focus();
    }, true);

    document.addEventListener('pointerdown', event => {
        document.querySelectorAll(`${selector}[open]`).forEach(search => {
            if (!search.contains(event.target)) close(search);
        });
    });

    document.addEventListener('focusout', event => {
        if (!(event.target instanceof Element) || !(event.relatedTarget instanceof Node)) return;
        const search = event.target.closest(selector);
        if (search?.open && !search.contains(event.relatedTarget)) close(search);
    });
})();
