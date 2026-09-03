import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {pathToFileURL} from 'node:url';

// A generated one-hour, 8-bit mono PCM WAV. No audio assets or external services are needed.
export const sampleRate = 8000;
export const sampleSize = 44 + sampleRate * 3600;
const header = Buffer.alloc(44);
header.write('RIFF');
header.writeUInt32LE(sampleSize - 8, 4);
header.write('WAVEfmt ', 8);
header.writeUInt32LE(16, 16);
header.writeUInt16LE(1, 20);
header.writeUInt16LE(1, 22);
header.writeUInt32LE(sampleRate, 24);
header.writeUInt32LE(sampleRate, 28);
header.writeUInt16LE(1, 32);
header.writeUInt16LE(8, 34);
header.write('data', 36);
header.writeUInt32LE(sampleSize - 44, 40);

export function byteRange(value) {
    if (!value) return [0, sampleSize - 1];
    const match = /^bytes=(\d*)-(\d*)$/.exec(value);
    if (!match || (!match[1] && !match[2])) return null;
    const start = match[1] ? Number(match[1]) : Math.max(0, sampleSize - Number(match[2]));
    const end = match[1] && match[2] ? Math.min(sampleSize - 1, Number(match[2])) : sampleSize - 1;
    return start <= end && start < sampleSize ? [start, end] : null;
}

export function createFixtureServer() {
    const requests = [];
    const assets = new Map([
        ['/', new URL('./index.html', import.meta.url)],
        ['/fixture.js', new URL('./fixture.js', import.meta.url)],
        ['/fixture.css', new URL('./fixture.css', import.meta.url)],
        ...['loader.js', 'player.js', 'player.css'].map(name => [
            `/_assets/register/audio-player/${name}`, new URL(`../../../_assets/register/audio-player/${name}`, import.meta.url),
        ]),
    ]);
    return createServer(async (request, response) => {
        const url = new URL(request.url, 'http://127.0.0.1');
        response.setHeader('Cache-Control', 'no-store');
        response.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; object-src 'none'; base-uri 'none'");
        if (url.pathname === '/requests') {
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify(requests));
            return;
        }
        if (url.pathname === '/sample.wav') {
            const range = byteRange(request.headers.range);
            response.setHeader('Accept-Ranges', 'bytes');
            response.setHeader('Content-Type', 'audio/wav');
            if (!range) {
                response.writeHead(416, {'Content-Range': `bytes */${sampleSize}`});
                response.end();
                return;
            }
            const [start, end] = range;
            const entry = {track: url.searchParams.get('track') ?? 'main', range: request.headers.range ?? null,
                status: request.headers.range ? 206 : 200, start, end, sent: 0, closed: false};
            requests.push(entry);
            if (requests.length > 80) requests.shift();
            response.statusCode = entry.status;
            response.setHeader('Content-Length', end - start + 1);
            if (request.headers.range) response.setHeader('Content-Range', `bytes ${start}-${end}/${sampleSize}`);
            if (request.method === 'HEAD') { response.end(); return; }
            let position = start;
            let timer;
            function sendChunk() {
                const length = Math.min(8000, end - position + 1);
                const chunk = Buffer.alloc(length, 128);
                if (position < header.length) header.copy(chunk, 0, position, Math.min(header.length, position + length));
                const writable = response.write(chunk);
                position += length;
                entry.sent += length;
                if (position > end) {
                    response.end();
                } else if (writable) {
                    timer = setTimeout(sendChunk, 150);
                } else {
                    response.once('drain', () => { timer = setTimeout(sendChunk, 150); });
                }
            }
            timer = setTimeout(sendChunk, Math.min(5000, Math.max(0, Number(url.searchParams.get('delay')) || 0)));
            response.on('close', () => { clearTimeout(timer); entry.closed = true; });
            return;
        }
        const file = assets.get(url.pathname);
        if (!file) { response.writeHead(404); response.end('Not found'); return; }
        try {
            response.setHeader('Content-Type', file.pathname.endsWith('.js') ? 'text/javascript'
                : file.pathname.endsWith('.css') ? 'text/css' : 'text/html; charset=utf-8');
            response.end(await readFile(file));
        } catch (error) {
            response.writeHead(500);
            response.end(String(error));
        }
    });
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    server.listen(Number(process.env.AUDIO_TEST_PORT) || 8081, '127.0.0.1', () => {
        console.log(`Audio fixture: http://127.0.0.1:${server.address().port}/`);
    });
}
