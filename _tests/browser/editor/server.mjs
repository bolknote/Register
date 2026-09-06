import {createServer} from 'node:http';
import {readFile} from 'node:fs/promises';
import {pathToFileURL} from 'node:url';
import {execFileSync} from 'node:child_process';

// Only this loopback-only test server exposes the editor's private functions.
// The production asset and its event handlers are otherwise served unchanged.
export function createFixtureServer() {
    return createServer(async (request, response) => {
        response.setHeader('Cache-Control', 'no-store');
        response.setHeader('Content-Security-Policy', "default-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data: blob:; media-src 'self'; object-src 'none'");
        try {
            if (request.url === '/image-optimizer/js/optimizer.js') {
                response.setHeader('Content-Type', 'text/javascript');
                response.end('export async function optimizeImage(blob) { return {blob, extension: "png", retina: false, width: 1, height: 1, displayWidth: 1, displayHeight: 1}; }');
                return;
            }
            if (request.url === '/editor.js') {
                const source = process.env.EDITOR_TEST_REVISION
                    ? execFileSync('git', ['show', `${process.env.EDITOR_TEST_REVISION}:_assets/register/post-inplace.js`], {encoding: 'utf8'})
                    : await readFile(new URL('../../../_assets/register/post-inplace.js', import.meta.url), 'utf8');
                response.setHeader('Content-Type', 'text/javascript');
                response.end(source.replace(/\}\)\(\);\s*$/u, `
                    window.editorTest = {editorStates, runFormattingCommand, contextInlineCode,
                        removeContextLink, applyContextLink, handleContextAction, contextBlockStyle,
                        beginImageCaptionEditing, finishImageCaptionEditing, insertMediaFiles,
                        beginInlineMediaCaption, finishInlineMediaCaption,
                        editableBodyHtml, prepareEditableMedia, stopEditing,
                        createBodyHistory: typeof createBodyHistory === 'function' ? createBodyHistory : null};
                })();`));
                return;
            }
            const files = new Map([
                ['/', ['index.html', 'text/html; charset=utf-8']],
                ['/cases.js', ['cases.js', 'text/javascript']],
                ['/site.css', ['../../../_styles/register/site.css', 'text/css']],
            ]);
            const file = files.get(request.url);
            if (!file) { response.writeHead(404); response.end(); return; }
            response.setHeader('Content-Type', file[1]);
            response.end(await readFile(new URL(file[0], import.meta.url)));
        } catch (error) {
            response.writeHead(500);
            response.end(String(error));
        }
    });
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    const server = createFixtureServer();
    server.listen(Number(process.env.EDITOR_TEST_PORT) || 8082, '127.0.0.1', () => {
        console.log(`Editor regressions: http://127.0.0.1:${server.address().port}/`);
    });
}
