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

    const progress = document.getElementById('register-search-progress');

    async function reindexQuery() {
        try {
            const response = await fetch(window.registerSearchConfig.url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({csrf_token: window.registerSearchConfig.csrfToken})
            });
            const data = await response.json();
            if (!response.ok || data.success !== true || typeof data.status !== 'string') {
                throw new Error(data.message || `Search indexing failed with HTTP ${response.status}.`);
            }

            progress.textContent = `: ${window.registerSearchConfig.scheduledMessage}`;
        } catch (error) {
            progress.textContent = `: ${window.registerSearchConfig.failureMessage}`;
            console.warn('Search repair scheduling failed:', error);
        }
    }

    window.registerSearch = {
        reindex: function () {
            progress.textContent = '…';
            void reindexQuery();

            return false;
        }
    };
}());
