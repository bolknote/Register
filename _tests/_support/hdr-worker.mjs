// Execute the checked-in classic worker and its real Wasm codec without browser rendering.
import {parentPort, workerData} from 'node:worker_threads';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';

const workerUrl = new URL(workerData.url);
const context = vm.createContext({URL, WebAssembly, TextDecoder, TextEncoder, setTimeout, clearTimeout,
    performance, console, fetch() { throw new Error('HDR codec tests must not access the network.'); },
    location: {href: workerUrl.href}, postMessage: (message, transfer) => parentPort.postMessage(message, transfer),
});
context.self = context;
context.importScripts = path => {
    const url = new URL(path, workerUrl);
    vm.runInContext(readFileSync(url, 'utf8'), context, {filename: url.pathname});
    const factory = context.RegisterUltraHdrModule;
    context.RegisterUltraHdrModule = options => factory({...options,
        instantiateWasm(imports, receive) {
            const binary = new WebAssembly.Module(readFileSync(new URL('../lib/register-ultrahdr.wasm', workerUrl)));
            const instance = new WebAssembly.Instance(binary, imports);
            receive(instance, binary);
            return instance.exports;
        }});
};
vm.runInContext(readFileSync(workerUrl, 'utf8'), context, {filename: workerUrl.pathname});
parentPort.on('message', message => context.onmessage({data: message}));
