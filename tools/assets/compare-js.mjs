#!/usr/bin/env node
import {readFile} from 'node:fs/promises';
import {resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
import {brotliCompressSync, constants} from 'node:zlib';
import {minifySync} from 'oxc-minify';
import {parse} from 'acorn';
import {minify} from 'terser';

const root = fileURLToPath(new URL('../../', import.meta.url));
const report = JSON.parse(await readFile(process.argv[2], 'utf8'));
const totals = {oxc: {bytes: 0, br: 0, ms: 0}, terser: {bytes: 0, br: 0, ms: 0}};
const files = [];
for (const {path} of report.files.filter(file => file.path.endsWith('.js'))) {
    const code = await readFile(resolve(root, path), 'utf8');
    let module = false;
    try { parse(code, {ecmaVersion: 'latest', sourceType: 'script'}); }
    catch { parse(code, {ecmaVersion: 'latest', sourceType: 'module'}); module = true; }
    let start = performance.now();
    const oxc = minifySync(path, code, {module, compress: {target: 'es2020', maxIterations: 5},
        mangle: {toplevel: module}, codegen: {legalComments: 'inline'}});
    if (oxc.errors.length) throw new Error(JSON.stringify(oxc.errors));
    totals.oxc.ms += performance.now() - start;
    start = performance.now();
    const terser = await minify({[path]: code}, {module, ecma: 2020, compress: {passes: 5, unsafe: false},
        mangle: {toplevel: module}, format: {comments: /^!|@preserve|@license|copyright/iu, ascii_only: false}});
    totals.terser.ms += performance.now() - start;
    const sizes = {path};
    for (const [name, output] of Object.entries({oxc, terser})) {
        const content = Buffer.from(output.code);
        const br = brotliCompressSync(content, {params: {[constants.BROTLI_PARAM_QUALITY]: 11}}).length;
        totals[name].bytes += content.length;
        totals[name].br += br;
        sizes[name] = {bytes: content.length, br};
    }
    files.push(sizes);
}
console.log(JSON.stringify({count: files.length, totals, largestDifferences: files
    .sort((a, b) => Math.abs(b.oxc.br - b.terser.br) - Math.abs(a.oxc.br - a.terser.br)).slice(0, 10)}, null, 2));
