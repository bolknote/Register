/**
 * Adds reindexing functions to the admin panel
 *
 * @copyright (C) 2011-2024 Roman Parpalak
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

            if (data.status.startsWith('go_')) {
                progress.textContent = `: ${data.status.substring(3)}%…`;
                window.setTimeout(reindexQuery, 50);
                return;
            }

            progress.textContent = data.status === 'stop' ? ': 100%' : `: ${data.status}`;
        } catch (error) {
            progress.textContent = ': indexing failed';
            console.warn('Search indexing failed:', error);
        }
    }

    window.registerSearch = {
        reindex: function () {
            progress.textContent = ': 0%…';
            void reindexQuery();

            return false;
        }
    };
}());
