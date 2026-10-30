#!/usr/bin/env node
// Builds rstation-image.json for an SD card image hosted elsewhere (e.g. Nextcloud) and
// uploads it to the fixed `rstation-image` GitHub release, where RI Launcher reads it.
//
//   npm run publish:rstation-image -- <image-url> [--version 1.2.0] [--name file.img.xz]
//        [--notes "text"] [--min-launcher 0.3.0] [--url <mirror-url>]...
//        [--rauc <bundle-url> [--rauc-name file.raucb] [--rauc-url <mirror-url>]...] [--dry-run]
//
// The image (and the .raucb, if given) is streamed from its URL once to get its SHA-256 and size (nothing is written
// to disk). The manifest's `rauc` object has the same filename/size/sha256/urls shape as the image. Needs the GitHub CLI (`gh`), logged in with write access to the repo.
import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { parseArgs } from 'node:util';

const REPO = 'fiforms/reliefinventory';
const TAG = 'rstation-image';
const ASSET = 'rstation-image.json';

const { values, positionals } = parseArgs({
    allowPositionals: true,
    options: {
        version: { type: 'string' },
        name: { type: 'string' },
        notes: { type: 'string', default: '' },
        'min-launcher': { type: 'string' },
        url: { type: 'string', multiple: true, default: [] },
        rauc: { type: 'string' },
        'rauc-name': { type: 'string' },
        'rauc-url': { type: 'string', multiple: true, default: [] },
        'dry-run': { type: 'boolean', default: false },
    },
});

if (positionals.length !== 1) {
    console.error('Usage: npm run publish:rstation-image -- <image-url> [--version V] [--name FILE] [--notes TEXT] [--min-launcher V] [--url MIRROR]... [--rauc URL [--rauc-name FILE] [--rauc-url MIRROR]...] [--dry-run]');
    process.exit(2);
}

// A Nextcloud share page (…/s/<token>) is not the file; …/s/<token>/download is. When the
// share is a folder, /download alone is a page or a zip, so --name picks the one file in it.
function directUrl(raw) {
    const u = new URL(raw);
    if (u.protocol !== 'https:') throw new Error(`${raw} must be https`);
    if (/\/s\/[^/]+\/?$/.test(u.pathname)) {
        u.pathname = u.pathname.replace(/\/?$/, '/download');
        if (values.name) u.search = `?path=%2F&files=${encodeURIComponent(values.name)}`;
    }
    return u.toString();
}

// Streams a file once to get its SHA-256 and size; `name` overrides the file name the server reports.
async function hashed(url, name) {
    console.error(`Downloading ${url} to hash it...`);
    const res = await fetch(url, { redirect: 'follow' });
    if (!res.ok || !res.body) throw new Error(`Download failed: HTTP ${res.status}`);
    if ((res.headers.get('content-type') ?? '').startsWith('text/html')) throw new Error('The URL returned a web page, not a file. For a Nextcloud folder share, pass --name <file in the folder>.');
    const hash = createHash('sha256');
    let size = 0;
    let lastReport = 0;
    for await (const chunk of res.body) {
        hash.update(chunk);
        size += chunk.length;
        if (Date.now() - lastReport > 2000) {
            process.stderr.write(`\r  ${(size / 1048576).toFixed(0)} MB`);
            lastReport = Date.now();
        }
    }
    process.stderr.write(`\r  ${(size / 1048576).toFixed(0)} MB, done\n`);
    if (size === 0) throw new Error('Downloaded 0 bytes — is the URL a direct file link?');
    const disposition = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(res.headers.get('content-disposition') ?? '');
    const filename = name
        ?? (disposition && decodeURIComponent(disposition[1]))
        ?? decodeURIComponent(new URL(url).pathname.split('/').filter(Boolean).pop());
    return { filename, size, sha256: hash.digest('hex') };
}

const urls = [positionals[0], ...values.url].map(directUrl);
const image = await hashed(urls[0], values.name);

// The signed RAUC bundle (.raucb) for over-the-air updates of devices already in the field. Optional; the launcher ignores it (it
// only writes SD cards), a server or device can use it to update R Stations (dev/TODO.md).
let rauc;
if (values.rauc) {
    const raucUrls = [values.rauc, ...values['rauc-url']].map(directUrl);
    rauc = { ...(await hashed(raucUrls[0], values['rauc-name'])), urls: raucUrls };
}

const now = new Date();
const manifest = {
    schema: 1,
    version: values.version ?? now.toISOString().slice(0, 10),
    released: now.toISOString(),
    notes: values.notes,
    filename: image.filename,
    size: image.size,
    sha256: image.sha256,
    urls,
    ...(rauc ? { rauc } : {}),
    ...(values['min-launcher'] ? { min_launcher_version: values['min-launcher'] } : {}),
};
const json = JSON.stringify(manifest, null, 2) + '\n';
console.error(json);

if (values['dry-run']) {
    console.error('Dry run: nothing uploaded.');
    process.exit(0);
}

const gh = (...args) => execFileSync('gh', args, { stdio: ['ignore', 'inherit', 'inherit'] });
const exists = (() => {
    try { execFileSync('gh', ['release', 'view', TAG, '--repo', REPO], { stdio: 'ignore' }); return true; } catch { return false; }
})();
if (!exists) {
    gh('release', 'create', TAG, '--repo', REPO, '--title', 'R Station image manifest',
        '--notes', 'Holds rstation-image.json, which RI Launcher reads to find the current R Station SD card image. Not a software release.',
        '--latest=false');
}

// gh names the asset after the file, so write it under the exact asset name.
const dir = mkdtempSync(join(tmpdir(), 'rstation-manifest-'));
try {
    const file = join(dir, ASSET);
    writeFileSync(file, json);
    gh('release', 'upload', TAG, file, '--repo', REPO, '--clobber');
} finally {
    rmSync(dir, { recursive: true, force: true });
}
console.error(`Published: https://github.com/${REPO}/releases/download/${TAG}/${ASSET}`);
