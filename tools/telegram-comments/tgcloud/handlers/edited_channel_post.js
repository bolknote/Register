import { receiveEvent } from '../lib/bridge.js';

export default async function (post, ctx) {
    await receiveEvent(post, ctx, 'channel_post');
}
