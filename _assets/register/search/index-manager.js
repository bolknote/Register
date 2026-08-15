/**
 * Adds reindexing functions to the admin panel
 *
 * @copyright 2011-2024 Roman Parpalak
 * @copyright 2026 Evgeny Stepanischev
 * @license http://opensource.org/licenses/MIT MIT
 * @package Register
 */

(function () {
    'use strict';

    const root = document.querySelector('[data-register-search]');
    if (!root) {
        return;
    }

    const config = {
        url: root.dataset.reindexUrl || '',
        csrfToken: root.dataset.csrfToken || '',
        scheduledMessage: root.dataset.scheduledMessage || '',
        failureMessage: root.dataset.failureMessage || ''
    };
    const progress = document.getElementById('register-search-progress');

    async function reindexQuery() {
        try {
            const response = await fetch(config.url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({csrf_token: config.csrfToken})
            });
            const data = await response.json();
            if (!response.ok || data.success !== true || typeof data.status !== 'string') {
                throw new Error(data.message || `Search indexing failed with HTTP ${response.status}.`);
            }

            progress.textContent = `: ${config.scheduledMessage}`;
        } catch (error) {
            progress.textContent = `: ${config.failureMessage}`;
            console.warn('Search repair scheduling failed:', error);
        }
    }

    root.querySelector('[data-register-search-reindex]')?.addEventListener('click', function (event) {
        event.preventDefault();
        progress.textContent = '…';
        void reindexQuery();
    });
}());
