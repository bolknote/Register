import {createHash} from 'node:crypto';
import {lstat, readFile, readdir} from 'node:fs/promises';
import {relative, resolve, sep} from 'node:path';
import {parse} from 'acorn';

// A lazy module and its workers/codecs are one cache unit, not independent
// files. In particular, a query on an entry module is not inherited by imports.
export const assetGraphs = ['_assets/register/image-optimizer'];

async function graphFiles(directory) {
    const files = [];
    for (const entry of await readdir(directory, {withFileTypes: true})) {
        const filename = resolve(directory, entry.name);
        if (entry.isSymbolicLink()) throw new Error(`Symlink in asset graph: ${filename}`);
        if (entry.name.startsWith('.')) continue;
        if (entry.isDirectory()) files.push(...await graphFiles(filename));
        else if (entry.isFile() && /\.(?:m?js|wasm)$/u.test(entry.name)) files.push(filename);
    }
    return files.sort();
}

/** Hash optimized JS and original binaries before inserting the graph version. */
export async function prepareAssetGraphs(root, outputs, directories = assetGraphs) {
    const originals = new Map(outputs.map(output => [output.filename, output.content]));
    const graphs = [];
    const paths = new Set();
    for (const path of directories) {
        if (typeof path !== 'string' || !/^(?:_admin|_assets|_extensions|_styles)\//u.test(path)
            || /[\\\x00-\x1f\x7f]/u.test(path)
            || path.split('/').some(part => !part || part === '.' || part === '..')
            || paths.has(path) || [...paths].some(other => path.startsWith(other + '/') || other.startsWith(path + '/'))) {
            throw new Error('Invalid asset graph directory');
        }
        paths.add(path);
        const directory = resolve(root, path);
        const entry = await lstat(directory).catch(error => {
            if (error.code === 'ENOENT') return null;
            throw error;
        });
        if (entry === null) continue;
        if (!entry.isDirectory() || entry.isSymbolicLink()) throw new Error(`Unsafe asset graph: ${path}`);
        const hash = createHash('sha256').update('register-asset-graph-v1\0');
        for (const filename of await graphFiles(directory)) {
            const content = originals.get(filename) ?? await readFile(filename);
            // Names, boundaries and bytes all participate; mtime does not.
            hash.update(JSON.stringify([relative(directory, filename).split(sep).join('/'), content.length]) + '\0').update(content);
        }
        graphs.push({path, version: hash.digest('hex')});
    }
    return graphs;
}

/** Rewrite URL syntax, never matching arbitrary article/text string literals. */
export function versionAssetGraphReferences(filename, source, graphs) {
    if (!graphs.length || !/\.m?js$/u.test(filename)) return source;
    const code = source.toString('utf8');
    let ast;
    try { ast = parse(code, {ecmaVersion: 'latest', sourceType: 'script'}); }
    catch { ast = parse(code, {ecmaVersion: 'latest', sourceType: 'module'}); }
    const changes = [];
    const graphFor = specifier => {
        if (!specifier || /^(?:[a-z][a-z0-9+.-]*:|\/\/|#)/iu.test(specifier)) return null;
        const url = new URL(specifier, 'https://register-build.invalid/' + filename);
        return graphs.find(graph => url.pathname.startsWith('/' + graph.path + '/')) ?? null;
    };
    const versioned = specifier => {
        const graph = graphFor(specifier);
        if (!graph) return null;
        const url = new URL(specifier, 'https://register-build.invalid/' + filename);
        if (!/\.(?:m?js(?:\.asset)?|wasm)$/u.test(url.pathname)) return null;
        const pathname = specifier.split(/[?#]/u)[0];
        const suffix = /\.m?js$/u.test(url.pathname) ? '.asset' : '';
        url.searchParams.set('v', graph.version);
        return pathname + suffix + url.search + url.hash;
    };
    const stringValue = node => node?.type === 'Literal' && typeof node.value === 'string' ? node.value
        : node?.type === 'TemplateLiteral' && !node.expressions.length ? node.quasis[0].value.cooked : null;
    const literal = node => {
        const text = stringValue(node);
        if (text === null) return;
        const value = versioned(text);
        if (value !== null) changes.push({start: node.start, end: node.end, code: JSON.stringify(value)});
    };
    const visit = node => {
        if (!node || typeof node !== 'object') return;
        if (['ImportDeclaration', 'ExportNamedDeclaration', 'ExportAllDeclaration', 'ImportExpression'].includes(node.type)) {
            literal(node.source);
        } else if (node.type === 'NewExpression' && node.callee?.name === 'URL') {
            const argument = node.arguments[0];
            literal(argument);
            // Emscripten locateFile receives the codec name at runtime. Keep
            // existing queries/fragments and apply the same version as workers.
            const prefix = argument?.type === 'BinaryExpression' && argument.operator === '+' ? stringValue(argument.left)
                : argument?.type === 'TemplateLiteral' && argument.expressions.length ? argument.quasis[0].value.cooked : null;
            const graph = prefix?.endsWith('/') ? graphFor(prefix + 'codec.wasm') : null;
            if (graph) {
                changes.push({start: node.start, end: node.end, code: `((url) => {
                    if (/\\.m?js$/.test(url.pathname)) url.pathname += '.asset';
                    url.searchParams.set('v', ${JSON.stringify(graph.version)});
                    return url;
                })(${code.slice(node.start, node.end)})`});
                return;
            }
        } else if ((node.type === 'CallExpression' && ['importScripts', 'fetch'].includes(node.callee?.name))
            || (node.type === 'NewExpression' && ['Worker', 'SharedWorker'].includes(node.callee?.name))) {
            for (const argument of node.arguments) literal(argument);
        }
        for (const value of Object.values(node)) {
            if (Array.isArray(value)) value.forEach(visit);
            else if (value && typeof value === 'object') visit(value);
        }
    };
    visit(ast);
    let result = code;
    for (const change of changes.sort((left, right) => right.start - left.start)) {
        result = result.slice(0, change.start) + change.code + result.slice(change.end);
    }
    return changes.length ? Buffer.from(result) : source;
}
