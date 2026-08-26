/** Promise-based facade for the linear-sRGB image resize worker. */

const workerUrl = new URL('image-resize-worker.js', import.meta.url).toString();
let worker = null;
let workerReady = null;
let nextTaskId = 0;
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
        worker = new Worker(workerUrl, {type: 'module'});
        worker.onmessage = function (event) {
            const message = event.data || {};
            if (message.type === 'ready') {
                resolve(worker);
                return;
            }
            if (message.type !== 'done' && message.type !== 'error') {
                return;
            }
            const task = tasks.get(message.id);
            if (!task) {
                return;
            }
            tasks.delete(message.id);
            if (message.type === 'error') {
                task.reject(new Error(message.message || 'Image resize failed.'));
                return;
            }
            task.resolve(new ImageData(
                new Uint8ClampedArray(message.data),
                task.targetWidth,
                task.targetHeight
            ));
        };
        worker.onerror = function (event) {
            const error = new Error(event && event.message ? event.message : 'Image resize worker failed.');
            rejectTasks(error);
            reject(error);
            worker.terminate();
            worker = null;
            workerReady = null;
        };
    });

    return workerReady;
}

export function resizeImageDataLinear(imageData, targetWidth, targetHeight) {
    if (!imageData || !imageData.data || targetWidth <= 0 || targetHeight <= 0) {
        return Promise.reject(new Error('Invalid image resize input.'));
    }
    if (imageData.width === targetWidth && imageData.height === targetHeight) {
        return Promise.resolve(imageData);
    }

    return ensureWorker().then(function (activeWorker) {
        return new Promise(function (resolve, reject) {
            const id = ++nextTaskId;
            const data = imageData.data.buffer.slice(
                imageData.data.byteOffset,
                imageData.data.byteOffset + imageData.data.byteLength
            );
            tasks.set(id, {resolve: resolve, reject: reject, targetWidth: targetWidth, targetHeight: targetHeight});
            activeWorker.postMessage({
                type: 'resize',
                id: id,
                data: data,
                width: imageData.width,
                height: imageData.height,
                targetWidth: targetWidth,
                targetHeight: targetHeight
            }, [data]);
        });
    });
}
