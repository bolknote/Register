#!/usr/bin/env node
import {parseArgs} from 'node:util';
import {writeFile} from 'node:fs/promises';
import {buildAssets} from './optimizer.mjs';

try {
    const {values} = parseArgs({options: {root: {type: 'string'}, report: {type: 'string'}}});
    if (!values.root) throw new Error('Usage: npm run build:assets -- --root=<staged-public-root> [--report=<file>]');
    const report = await buildAssets(values.root, {onProgress(done, total, path) {
        if (done % 10 === 0 || done === total) console.error(`Assets: ${done}/${total} (${path})`);
    }});
    if (values.report) await writeFile(values.report, JSON.stringify(report, null, 2) + '\n');
    const {source, minified, br, zst, gz} = report.totals;
    const size = value => `${(value / 1024).toFixed(1)} KiB`;
    console.error(`Assets: ${report.files.length} files; source ${size(source)}; minified ${size(minified)}; Brotli ${size(br)}; Zstandard ${size(zst)}; gzip ${size(gz)}`);
} catch (error) {
    console.error(`Asset build failed: ${error.message}`);
    process.exitCode = 1;
}
