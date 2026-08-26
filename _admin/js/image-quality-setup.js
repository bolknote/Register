/** Promise facade for the visible-pixel SSIM worker. */

const workerUrl = new URL('image-quality-worker.js', import.meta.url).toString();
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
                const error = new Error(message.message || 'Image quality worker initialization failed.');
                rejectTasks(error);
                reject(error);
                return;
            }
            if (!['initialized', 'scored', 'error'].includes(message.type)) {
                return;
            }
            const task = tasks.get(message.id);
            if (!task) {
                return;
            }
            tasks.delete(message.id);
            if (message.type === 'error') {
                task.reject(new Error(message.message || 'Image quality analysis failed.'));
                return;
            }
            task.resolve(message);
        };
        worker.onerror = function (event) {
            const error = new Error(event?.message || 'Image quality worker failed.');
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

export function createImageQualityScorer(imageData) {
    if (!imageData?.data || imageData.width <= 0 || imageData.height <= 0) {
        return Promise.reject(new Error('Invalid image quality reference.'));
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
                score: function (blob) {
                    if (closed) {
                        return Promise.reject(new Error('The image quality session is closed.'));
                    }
                    return sendTask(activeWorker, {
                        type: 'score',
                        sessionId: sessionId,
                        blob: blob
                    }).then(function (message) {
                        return {score: message.score, downscale: message.score};
                    });
                },
                close: function () {
                    if (!closed) {
                        closed = true;
                        activeWorker.postMessage({type: 'release', sessionId: sessionId});
                    }
                }
            };
        });
    });
}
