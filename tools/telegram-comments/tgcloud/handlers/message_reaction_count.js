import { receiveEvent } from '../lib/bridge.js';

export default async function (reaction, ctx) {
    await receiveEvent(reaction, ctx, 'reaction_count');
}
