import test from 'node:test';
import assert from 'node:assert/strict';
import {spawn} from 'node:child_process';
import {once} from 'node:events';
import {mkdir, mkdtemp, rm, symlink, unlink, writeFile} from 'node:fs/promises';
import {createServer} from 'node:net';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {fileURLToPath} from 'node:url';
import {gzipSync} from 'node:zlib';

test('Development asset router confines compressed representations to their resolved asset path', async () => {
    const scratch = await mkdtemp(join(tmpdir(), 'register-dev-asset-policy-'));
    const publicRoot = join(scratch, 'public');
    const asset = join(publicRoot, '_assets', 'fixture.css');
    const css = 'body { color: red; }';
    let server;
    let serverError;
    let serverLog = '';
    try {
        await mkdir(join(publicRoot, '_assets'), {recursive: true});
        await writeFile(asset, css);
        await writeFile(asset + '.gz', gzipSync(css));
        const listener = createServer();
        listener.listen(0, '127.0.0.1');
        await once(listener, 'listening');
        const {port} = listener.address();
        await new Promise((done, reject) => listener.close(error => error ? reject(error) : done()));
        const origin = `http://127.0.0.1:${port}`;
        const router = fileURLToPath(new URL('../../tools/dev-router.php', import.meta.url));
        server = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${port}`, '-t', publicRoot, router],
            {stdio: ['ignore', 'pipe', 'pipe']});
        server.on('error', error => { serverError = error; });
        server.stdout.on('data', data => { serverLog += data; });
        server.stderr.on('data', data => { serverLog += data; });
        const request = encoding => fetch(origin + '/_assets/fixture.css.asset', {
            headers: {'Accept-Encoding': encoding}, signal: AbortSignal.timeout(1000),
        });
        for (let attempt = 0; ; attempt++) {
            if (serverError) throw serverError;
            if (server.exitCode !== null || server.signalCode !== null) throw new Error('PHP exited: ' + serverLog);
            try {
                const response = await request('identity');
                await response.text();
                if (response.ok) break;
            } catch (_) { /* The disposable server is starting. */ }
            if (attempt >= 40) throw new Error('PHP asset fixture did not start: ' + serverLog);
            await new Promise(done => setTimeout(done, 100));
        }
        const identity = await request('identity');
        assert.equal(identity.status, 200);
        assert.equal(await identity.text(), css);
        const compressed = await request('gzip');
        assert.equal(compressed.status, 200);
        assert.equal(compressed.headers.get('content-encoding'), 'gzip');
        assert.equal(await compressed.text(), css);

        const privateRepresentation = join(scratch, 'private.gz');
        await writeFile(privateRepresentation, gzipSync('Private fixture outside the public root'));
        await unlink(asset + '.gz');
        await symlink(privateRepresentation, asset + '.gz');
        const escaped = await request('gzip');
        assert.equal(escaped.status, 404, 'A sidecar symlink must not expose a private file');
        await escaped.text();
        const stillPublic = await request('identity');
        assert.equal(stillPublic.status, 200);
        assert.equal(await stillPublic.text(), css);
    } finally {
        server?.kill('SIGTERM');
        if (server && !serverError && server.exitCode === null && server.signalCode === null) {
            await once(server, 'exit');
        }
        await rm(scratch, {recursive: true, force: true});
    }
});
