import { receive } from '../lib/bridge.js';

export default async function (message, ctx) {
    await receive(message, ctx);
}
