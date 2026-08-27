/** Promise-based facade for the libwebp worker. */

const workerUrl = new URL('webp-encode-worker.js', import.meta.url).toString();
let worker = null;
let workerReady = null;
let nextTaskId = 0;
let nextSessionId = 0;
const tasks = new Map();

function rejectTasks(error) {
    tasks.forEach(function (task) {
        task.reject(error);
    });
    tasks.clear();
}

function ensureWorker() {
    if (workerReady) {
        return workerReady;
    }

    workerReady = new Promise(function (resolve, reject) {
        worker = new Worker(workerUrl);
        worker.onmessage = function (event) {
            const message = event.data || {};
            if (message.type === 'ready') {
                resolve(worker);
                return;
            }
            if (message.type === 'init-error') {
                const error = new Error(message.message || 'libwebp initialization failed.');
                rejectTasks(error);
                reject(error);
                return;
            }
            if (!['initialized', 'encoded', 'error'].includes(message.type)) {
                return;
            }
            const task = tasks.get(message.id);
            if (!task) {
                return;
            }
            tasks.delete(message.id);
            if (message.type === 'error') {
                task.reject(new Error(message.message || 'WebP encoding failed.'));
                return;
            }
            task.resolve(message);
        };
        worker.onerror = function (event) {
            const error = new Error(event && event.message ? event.message : 'WebP encoder worker failed.');
            rejectTasks(error);
            reject(error);
            worker.terminate();
            worker = null;
            workerReady = null;
        };
    });

    return workerReady;
}

function sendTask(activeWorker, message, transfer) {
    return new Promise(function (resolve, reject) {
        const id = ++nextTaskId;
        tasks.set(id, {resolve: resolve, reject: reject});
        activeWorker.postMessage(Object.assign({id: id}, message), transfer || []);
    });
}

export function createWebpEncoder(imageData) {
    if (!imageData || !imageData.data || imageData.width <= 0 || imageData.height <= 0) {
        return Promise.reject(new Error('Invalid WebP encoder input.'));
    }

    const sessionId = ++nextSessionId;
    return ensureWorker().then(function (activeWorker) {
        const data = imageData.data.buffer.slice(
            imageData.data.byteOffset,
            imageData.data.byteOffset + imageData.data.byteLength
        );
        return sendTask(activeWorker, {
            type: 'init',
            sessionId: sessionId,
            data: data,
            width: imageData.width,
            height: imageData.height
        }, [data]).then(function () {
            let closed = false;
            return {
                encode: function (options) {
                    if (closed) {
                        return Promise.reject(new Error('WebP encoder session is closed.'));
                    }
                    return sendTask(activeWorker, {
                        type: 'encode',
                        sessionId: sessionId,
                        options: options || {}
                    }).then(function (message) {
                        return new Blob([message.data], {type: 'image/webp'});
                    });
                },
                close: function () {
                    if (closed) {
                        return;
                    }
                    closed = true;
                    activeWorker.postMessage({type: 'release', sessionId: sessionId});
                }
            };
        });
    });
}
