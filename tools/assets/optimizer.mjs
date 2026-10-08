import {createHash} from 'node:crypto';
import {lstat, mkdir, readFile, readdir, realpath, rename, rm, stat, utimes, writeFile} from 'node:fs/promises';
import {dirname, extname, relative, resolve, sep} from 'node:path';
import {fileURLToPath} from 'node:url';
import {
    brotliCompressSync, brotliDecompressSync, constants, gunzipSync, gzipSync,
    zstdCompressSync, zstdDecompressSync,
} from 'node:zlib';
import {gzipAsync} from '@gfx/zopfli';
import {parse} from 'acorn';
import {transform} from 'lightningcss';
import {minify} from 'terser';
import {minifySync as minifyOxc} from 'oxc-minify';

export const assetDirectories = ['_admin', '_assets', '_extensions', '_styles'];
export const manifestPath = '_include/asset-manifest.json';
const sourceRoot = fileURLToPath(new URL('../../', import.meta.url));
const brotliCache = new WeakMap();

function compressedBrotli(content) {
    if (!brotliCache.has(content)) brotliCache.set(content, brotliCompressSync(content, {params: {
        [constants.BROTLI_PARAM_MODE]: constants.BROTLI_MODE_TEXT,
        [constants.BROTLI_PARAM_QUALITY]: 11,
        [constants.BROTLI_PARAM_SIZE_HINT]: content.length,
    }}));
    return brotliCache.get(content);
}

function withCssEncoding(content) {
    // Lightning CSS #310: emitted Unicode must also work without HTTP charset
    // headers, including when this CSS is linked by a non-UTF-8 document.
    return /[^\x00-\x7f]/u.test(content.toString('utf8')) && !content.toString('utf8').startsWith('@charset "UTF-8";')
        ? Buffer.concat([Buffer.from('@charset "UTF-8";'), content]) : content;
}

/** Preserve classic-script globals and module boundaries; never mangle public property names. */
export async function minifyAsset(filename, source) {
    const extension = extname(filename);
    if (extension === '.css') {
        const result = transform({filename, code: source, minify: true, errorRecovery: false});
        // Lightning CSS retains /*! ... */ notices. Keep other explicit license notices too.
        const notices = source.toString().match(/\/\*[\s\S]*?\*\//gu) ?? [];
        const missing = notices.filter(comment => /@license|@preserve|copyright/iu.test(comment)
            && !Buffer.from(result.code).toString().includes(comment));
        return withCssEncoding(Buffer.concat([Buffer.from(missing.join('\n') + (missing.length ? '\n' : '')), result.code]));
    }

    const code = source.toString('utf8');
    let comments = [];
    let module = extension === '.mjs';
    if (module) {
        parse(code, {ecmaVersion: 'latest', sourceType: 'module', onComment: comments});
    } else {
        try {
            parse(code, {ecmaVersion: 'latest', sourceType: 'script', onComment: comments});
        } catch {
            // A failed module parse is a build error, never a silent unminified fallback.
            comments = [];
            parse(code, {ecmaVersion: 'latest', sourceType: 'module', onComment: comments});
            module = true;
        }
    }
    const oxc = minifyOxc(filename, code, {
        module,
        compress: {target: 'es2020', maxIterations: 5, treeshake: {
            propertyReadSideEffects: 'always', propertyWriteSideEffects: true, unknownGlobalSideEffects: true,
        }},
        mangle: {toplevel: module},
        codegen: {legalComments: 'inline', asciiOnly: false},
    });
    if (oxc.errors.length) throw new Error(oxc.errors.map(error => error.message).join('; '));
    // Oxc's legal-comment filter does not include ordinary Copyright notices.
    const notices = comments.filter(comment => /copyright/iu.test(comment.value))
        .map(comment => code.slice(comment.start, comment.end)).filter(comment => !oxc.code.includes(comment));
    const oxcCode = Buffer.from(notices.join('\n') + (notices.length ? '\n' : '') + oxc.code);
    const result = await minify({[filename]: code}, {
        ecma: 2020,
        module,
        compress: {passes: 5, unsafe: false},
        mangle: {toplevel: module},
        format: {comments: /^!|@preserve|@license|copyright/iu, ascii_only: false},
    });
    if (typeof result.code !== 'string') throw new Error(`No minified output for ${filename}`);
    const terserCode = Buffer.from(result.code);
    // Browser transfer size matters more than the number of uncompressed bytes.
    return compressedBrotli(oxcCode).length < compressedBrotli(terserCode).length ? oxcCode : terserCode;
}

/** Spend CPU during a release build, with browser-compatible decoding and deterministic output. */
export async function compressAsset(content) {
    const zopfli = Buffer.from(await gzipAsync(content, {numiterations: 30}));
    const gzip = gzipSync(content, {level: 9});
    const encoded = {
        br: compressedBrotli(content),
        zst: zstdCompressSync(content, {params: {
            [constants.ZSTD_c_compressionLevel]: 22,
            // HTTP zstd must use a window of at most 8 MiB (RFC 9659).
            [constants.ZSTD_c_windowLog]: 23,
        }}),
        gz: zopfli.length < gzip.length ? zopfli : gzip,
    };
    const decoders = {br: brotliDecompressSync, zst: zstdDecompressSync, gz: gunzipSync};
    for (const [suffix, data] of Object.entries(encoded)) {
        if (!decoders[suffix](data).equals(content)) throw new Error(`Invalid ${suffix} output`);
    }
    return encoded;
}

async function assetFiles(directory) {
    const files = [];
    for (const entry of await readdir(directory, {withFileTypes: true})) {
        const filename = resolve(directory, entry.name);
        if (entry.isSymbolicLink()) throw new Error(`Symlink in asset tree: ${filename}`);
        if (entry.name.startsWith('.')) continue;
        if (entry.isDirectory()) files.push(...await assetFiles(filename));
        else if (entry.isFile() && /\.(?:css|m?js)$/u.test(entry.name)) files.push(filename);
    }
    return files.sort();
}

async function writeAtomic(filename, content, modifiedAt) {
    const temporary = `${filename}.register-build-tmp`;
    let created = false;
    try {
        await writeFile(temporary, content, {flag: 'wx', mode: 0o644});
        created = true;
        if (modifiedAt) await utimes(temporary, modifiedAt, modifiedAt);
        await rename(temporary, filename);
    } finally {
        if (created) await rm(temporary, {force: true});
    }
}

/** Optimize only a staged distribution. The repository's readable source is never overwritten. */
export async function buildAssets(publicRoot, {onProgress = () => {}} = {}) {
    const root = await realpath(publicRoot);
    const repository = await realpath(sourceRoot);
    if (root === repository || repository.startsWith(root + sep)) {
        throw new Error('Asset optimization requires a staged distribution, not the source checkout.');
    }
    for (const directory of [...assetDirectories, '_include']) {
        const entry = await lstat(resolve(root, directory));
        if (!entry.isDirectory() || entry.isSymbolicLink()) throw new Error(`Unsafe asset directory: ${directory}`);
    }
    const worker = resolve(root, 'service-worker.js');
    if (!(await lstat(worker)).isFile() || (await lstat(worker)).isSymbolicLink()) {
        throw new Error('The staged distribution has no safe service-worker.js.');
    }
    const files = [worker];
    for (const directory of assetDirectories) files.push(...await assetFiles(resolve(root, directory)));
    files.sort();

    // Validate and transform the whole tree before publishing any replacement.
    const outputs = [];
    for (const filename of files) {
        const source = await readFile(filename);
        const path = relative(root, filename).split(sep).join('/');
        let content;
        try {
            content = await minifyAsset(path, source);
        } catch (error) {
            throw new Error(`Unable to optimize ${path}: ${error.message}`, {cause: error});
        }
        // Never inflate already optimized vendor assets in the preferred wire encoding.
        const original = extname(path) === '.css' ? withCssEncoding(source) : source;
        if (compressedBrotli(original).length < compressedBrotli(content).length) content = original;
        outputs.push({filename, path, sourceBytes: source.length, content, modifiedAt: (await stat(filename)).mtime});
    }

    const assets = {};
    const report = {files: [], totals: {source: 0, minified: 0, br: 0, zst: 0, gz: 0}};
    for (const output of outputs) {
        const {filename, path, sourceBytes, content, modifiedAt} = output;
        const encoded = await compressAsset(content);
        await writeAtomic(filename, content, modifiedAt);
        const sizes = {path, source: sourceBytes, minified: content.length};
        for (const [suffix, data] of Object.entries(encoded)) {
            if (data.length < content.length) await writeAtomic(`${filename}.${suffix}`, data, modifiedAt);
            else await rm(`${filename}.${suffix}`, {force: true});
            sizes[suffix] = Math.min(content.length, data.length);
        }
        assets[path] = createHash('sha256').update(content).digest('hex');
        report.files.push(sizes);
        for (const key of Object.keys(report.totals)) report.totals[key] += sizes[key];
        onProgress(report.files.length, outputs.length, path);
    }
    await mkdir(dirname(resolve(root, manifestPath)), {recursive: true});
    await writeAtomic(resolve(root, manifestPath), Buffer.from(JSON.stringify({version: 1, assets}, null, 2) + '\n'));
    return report;
}
