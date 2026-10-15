// Share one retry budget across native file downloads and Register JSON endpoints.
export function createDeliverySession(fetch, download, {
    attempts = 7, maxRetries = 6, maxDurationMs = 15_000, now = Date.now,
} = {}) {
    attempts = Number.isSafeInteger(attempts) ? Math.max(1, Math.min(7, attempts)) : 7;
    let remainingRetries = Number.isSafeInteger(maxRetries) && maxRetries >= 0 ? maxRetries : 6;
    maxDurationMs = Number.isFinite(maxDurationMs) && maxDurationMs >= 0 ? maxDurationMs : 15_000;
    let startedAt;

    async function retry(operation, retryable = () => false) {
        let retryResponse;
        for (let attempt = 0; attempt < attempts; attempt++) {
            const timestamp = now();
            if (startedAt === undefined) startedAt = timestamp;
            // The SDK has no AbortController: bound new requests, not an in-flight one.
            if (timestamp - startedAt >= maxDurationMs) {
                if (retryResponse) return retryResponse;
                throw new Error('Register delivery session deadline reached; retry later.');
            }
            if (attempt > 0) {
                if (remainingRetries === 0) {
                    if (retryResponse) return retryResponse;
                    throw new Error('Register delivery retry budget exhausted; retry later.');
                }
                remainingRetries--;
            }

            retryResponse = undefined;
            let result;
            try { result = await operation(); } catch { continue; }
            if (retryable(result)) {
                retryResponse = result;
                continue;
            }
            return result;
        }
        if (retryResponse) return retryResponse;
        throw new Error('Register delivery request retries exhausted; retry later.');
    }

    return {
        fetch: (url, options) => retry(async () => {
            const response = await fetch(url, options);
            let body, readError, unreadable = false;
            try { body = await response.json(); } catch (error) { readError = error; unreadable = true; }
            if (response.ok && unreadable) throw new Error('Register delivery acknowledgement could not be read.');
            return {
                ok: response.ok, status: response.status, headers: response.headers,
                json: async () => {
                    if (unreadable) throw readError;
                    return body;
                },
            };
        }, response => response.status >= 500 && response.status < 600),
        download: id => retry(() => download(id)),
    };
}

// Compatibility adapter for callers that only need Register JSON requests.
export function createRetryingJsonFetch(fetch, options = {}) {
    return createDeliverySession(fetch, undefined, options).fetch;
}
