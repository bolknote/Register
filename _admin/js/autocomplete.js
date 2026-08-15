(() => {
    'use strict';

    window.makeAutocompleteControl = function (controlId, allowEmpty, emptyLabel, fetchUrl) {
        const control = document.getElementById(controlId);
        if (!control) {
            return;
        }

        const button = control.querySelector('button.ay-select-button');
        const select = control.querySelector('select.dropdown-select');
        const filter = control.querySelector('div.search');
        const filterContent = filter?.querySelector('span');
        const dropdown = control.querySelector('div.ay-select-dropdown');
        if (!button || !select || !filter || !filterContent || !dropdown) {
            return;
        }

        let filterValue = '';
        let currentValue = select.value;
        let controller = null;
        let allowCollapseOnChange = true;

        const globalClick = function (event) {
            if (event.target instanceof Node && !control.contains(event.target)) {
                collapse();
            }
        };

        const setExpanded = function (expanded) {
            dropdown.hidden = !expanded;
            button.classList.toggle('opened', expanded);
            button.setAttribute('aria-expanded', String(expanded));
            if (!expanded) {
                document.removeEventListener('click', globalClick);
            }
        };

        const expand = function () {
            if (!dropdown.hidden) {
                return;
            }

            setExpanded(true);
            window.setTimeout(function () {
                document.addEventListener('click', globalClick);
            }, 0);
        };

        const collapse = function (returnFocus = false) {
            setExpanded(false);
            if (returnFocus) {
                button.focus();
            }
        };

        const toggle = function () {
            if (dropdown.hidden) {
                expand();
            } else {
                collapse();
            }
        };

        const updateFilter = function (newValue) {
            if (newValue === filterValue) {
                return;
            }

            filterValue = newValue;
            filterContent.textContent = filterValue;
            updateOptions(filterValue);
        };

        const nextFilter = function (event) {
            if (event.key === 'Backspace') {
                return filterValue.slice(0, -1);
            }
            if (event.key.length === 1) {
                return filterValue + event.key;
            }

            return filterValue;
        };

        button.addEventListener('click', toggle);
        button.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                collapse();
                return;
            }
            if (event.key === 'Tab') {
                return;
            }
            if (event.key === 'Enter' || event.key === 'ArrowDown') {
                event.preventDefault();
                expand();
                select.focus();
                return;
            }

            expand();
            if (event.key === ' ') {
                event.preventDefault();
            }
            updateFilter(nextFilter(event));
        });

        select.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' || event.key === 'Enter') {
                event.preventDefault();
                collapse(true);
                return;
            }

            if (event.key === ' ') {
                event.preventDefault();
            }
            allowCollapseOnChange = false;
            updateFilter(nextFilter(event));
        });

        filter.addEventListener('click', function () {
            button.focus();
        });

        select.addEventListener('mousedown', function () {
            allowCollapseOnChange = true;
        });
        select.addEventListener('change', function () {
            button.textContent = select.selectedOptions[0]?.textContent || emptyLabel;
            currentValue = select.value;
            if (allowCollapseOnChange) {
                collapse();
            }
        });

        function updateOptions(query) {
            const url = fetchUrl + '&query=' + encodeURIComponent(query)
                + '&additional=' + encodeURIComponent(currentValue);

            controller?.abort();
            const requestController = new AbortController();
            controller = requestController;
            filter.classList.add('animate');

            fetch(url, {signal: requestController.signal})
                .then(function (response) {
                    if (!response.ok) {
                        throw new Error('Autocomplete request failed with HTTP ' + response.status + '.');
                    }

                    return response.json();
                })
                .then(function (data) {
                    if (controller !== requestController) {
                        return;
                    }
                    if (!Array.isArray(data)) {
                        throw new Error('Autocomplete returned invalid data.');
                    }

                    select.replaceChildren();
                    const appendOption = function (item) {
                        if (!item || (typeof item.value !== 'string' && typeof item.value !== 'number')
                            || typeof item.text !== 'string') {
                            return;
                        }

                        const option = document.createElement('option');
                        option.value = String(item.value);
                        option.textContent = item.text;
                        option.selected = option.value === currentValue;
                        select.append(option);
                    };
                    if (allowEmpty) {
                        appendOption({text: emptyLabel, value: ''});
                    }
                    data.forEach(appendOption);
                    controller = null;
                    filter.classList.remove('animate');
                })
                .catch(function (error) {
                    if (controller === requestController) {
                        controller = null;
                        filter.classList.remove('animate');
                    }
                    if (!(error instanceof DOMException) || error.name !== 'AbortError') {
                        console.warn(error);
                    }
                });
        }
    };
})();
