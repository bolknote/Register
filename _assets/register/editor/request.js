/* Shared deadlines for editor requests, including reading the response body. */
(() => {
    'use strict';

    async function withDeadline(task, options = {}, onTimeout = () => {}) {
        let timer;
        try {
            return await Promise.race([task, new Promise((_, reject) => {
                timer = setTimeout(() => {
                    onTimeout();
                    const error = new Error(options.timeoutMessage || 'The server did not respond in time. Your draft is retained; please try saving again.');
                    error.name = 'EditorTimeoutError';
                    reject(error);
                }, options.timeoutMs ?? 30000);
            })]);
        } finally {
            clearTimeout(timer);
        }
    }

    async function requestJson(url, init = {}, options = {}) {
        const retries = options.retries ?? 0;
        for (let attempt = 0; ; attempt++) {
            const controller = new AbortController();
            const abort = () => controller.abort(init.signal?.reason);
            init.signal?.addEventListener('abort', abort, {once: true});
            if (init.signal?.aborted) abort();
            try {
                return await withDeadline((async () => {
                    const response = await window.fetch(url, {...init, signal: controller.signal});
                    const data = response.redirected ? null : await response.json().catch(error => {
                        // Invalid JSON is a received response. A broken body stream
                        // is a lost response and must reach the bounded retry below.
                        if (error?.name === 'SyntaxError') return null;
                        throw error;
                    });
                    return {response, data};
                })(), options, () => controller.abort());
            } catch (error) {
                // Callers enable retries only for operations protected against duplication.
                if (attempt >= retries || init.signal?.aborted) throw error;
            } finally {
                init.signal?.removeEventListener('abort', abort);
            }
        }
    }

    window.RegisterEditorRequest = Object.freeze({requestJson, withDeadline});
})();
