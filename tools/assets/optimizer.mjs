import {createHash} from 'node:crypto';
import {lstat, mkdir, readFile, readdir, realpath, rename, rm, stat, utimes, writeFile} from 'node:fs/promises';
import {dirname, extname, posix, relative, resolve, sep} from 'node:path';
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
import {assetBundles} from './bundles.mjs';

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

function validateBundles(bundles) {
    const isAsset = path => typeof path === 'string'
        && /^(?:_admin|_assets|_extensions|_styles)\/.+\.(?:css|js)$/u.test(path)
        && !/[\\\x00-\x1f\x7f]/u.test(path)
        && path.split('/').every(segment => segment && segment !== '.' && segment !== '..');
    const paths = new Set();
    for (const bundle of bundles) {
        if (!isAsset(bundle.path) || paths.has(bundle.path) || !Array.isArray(bundle.files)
            || bundle.files.length < 2 || new Set(bundle.files).size !== bundle.files.length
            || bundle.files.some(path => !isAsset(path) || path === bundle.path || extname(path) !== extname(bundle.path))) {
            throw new Error('Invalid asset bundle definition');
        }
        paths.add(bundle.path);
    }
    if (bundles.some(bundle => bundle.files.some(path => paths.has(path)))) {
        throw new Error('Asset bundles must contain original assets, not other bundles');
    }
    return paths;
}

function bundleCss(source, target) {
    const result = transform({filename: source.path, code: source.content, visitor: {
        Rule(rule) {
            // Concatenation would move later imports/namespaces after style rules.
            if (rule.type === 'import' || rule.type === 'namespace') {
                throw new Error(`Cannot bundle CSS with @${rule.type}: ${source.path}`);
            }
        },
        Url(url) {
            if (/^(?:[a-z][a-z0-9+.-]*:|\/|#)/iu.test(url.url)) return;
            const suffixIndex = url.url.search(/[?#]/u);
            const pathname = suffixIndex < 0 ? url.url : url.url.slice(0, suffixIndex);
            const suffix = suffixIndex < 0 ? '' : url.url.slice(suffixIndex);
            const resolved = posix.normalize(posix.join(posix.dirname(source.path), pathname));
            return {...url, url: posix.relative(posix.dirname(target), resolved) + suffix};
        },
    }}).code;
    const notices = source.content.toString('utf8').match(/\/\*[\s\S]*?\*\//gu) ?? [];
    const missing = notices.filter(comment => /@license|@preserve|copyright/iu.test(comment)
        && !Buffer.from(result).toString('utf8').includes(comment));
    return Buffer.concat([Buffer.from(missing.join('\n') + (missing.length ? '\n' : '')), result]);
}

async function prepareBundles(root, outputs, definitions) {
    const originals = new Map(outputs.map(output => [output.path, output]));
    const bundles = [];
    for (const definition of definitions) {
        if (definition.files.some(path => !originals.has(path))) continue;
        const sources = definition.files.map(path => originals.get(path));
        const css = extname(definition.path) === '.css';
        const contents = sources.map(source => {
            if (css) return bundleCss(source, definition.path);
            try { parse(source.content.toString('utf8'), {ecmaVersion: 'latest', sourceType: 'script'}); }
            catch (error) { throw new Error(`Cannot put an ES module in a classic script bundle: ${source.path}`, {cause: error}); }
            return source.content;
        });
        const combined = Buffer.concat(contents.flatMap(content => [content, Buffer.from(css ? '\n' : '\n;\n')]));
        const content = await minifyAsset(definition.path, combined);
        outputs.push({filename: resolve(root, definition.path), path: definition.path,
            sourceBytes: sources.reduce((sum, source) => sum + source.sourceBytes, 0),
            content, modifiedAt: new Date(Math.max(...sources.map(source => source.modifiedAt.getTime())))});
        bundles.push(definition);
    }
    return bundles;
}

/** Optimize only a staged distribution. The repository's readable source is never overwritten. */
export async function buildAssets(publicRoot, {onProgress = () => {}, bundles: definitions = assetBundles} = {}) {
    const bundlePaths = validateBundles(definitions);
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
    const originals = files.filter(filename => !bundlePaths.has(relative(root, filename).split(sep).join('/'))).sort();

    // Validate and transform the whole tree before publishing any replacement.
    const outputs = [];
    for (const filename of originals) {
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

    const bundles = await prepareBundles(root, outputs, definitions);

    const assets = {};
    const report = {files: [], totals: {source: 0, minified: 0, br: 0, zst: 0, gz: 0}};
    for (const output of outputs) {
        const {filename, path, sourceBytes, content, modifiedAt} = output;
        const encoded = await compressAsset(content);
        await mkdir(dirname(filename), {recursive: true});
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
    await writeAtomic(resolve(root, manifestPath), Buffer.from(JSON.stringify({version: 1, assets, bundles}, null, 2) + '\n'));
    return report;
}
