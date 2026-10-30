// `npm run build:app [-- tauri build options]`: builds the installers with the version taken from the release tag, or the commit hash
// when the build isn't of a tag. Cargo.toml is left alone (a build must not dirty a tracked file); the version is handed to Tauri
// as a config override instead, so the installer's name and metadata carry it (and so does the window, via src-tauri/build.rs).
//
//   v1.2.0   (tag)     -> 1.2.0
//   99a6a45  (commit)  -> <Cargo.toml version>+99a6a45  (semver build metadata, `-dirty` becomes `.dirty`; on Windows, where an MSI needs a plain number, just
//                         the Cargo.toml version)
//
// RI_BUILD_VERSION (the tag name or commit sha, set by the workflows) wins over asking git.
import { spawnSync } from 'node:child_process'
import { readFileSync, writeFileSync, mkdtempSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, dirname } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')

function git(...args) {
    const r = spawnSync('git', args, { cwd: root, encoding: 'utf8' })
    return r.status === 0 ? r.stdout.trim() : ''
}

function buildRef() {
    const fromEnv = (process.env.RI_BUILD_VERSION || '').trim()
    if (fromEnv) return fromEnv.length === 40 && /^[0-9a-f]+$/i.test(fromEnv) ? fromEnv.slice(0, 7) : fromEnv
    return git('describe', '--tags', '--exact-match', '--dirty') || git('describe', '--always', '--dirty', '--abbrev=7')
}

const cargoVersion = readFileSync(join(root, 'src-tauri', 'Cargo.toml'), 'utf8').match(/^version\s*=\s*"([^"]+)"/m)?.[1]
const ref = buildRef()
const tag = ref.match(/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/)
let version = cargoVersion
if (tag) version = tag[1]
else if (ref && process.platform !== 'win32') version = `${cargoVersion}+${ref.replace(/[^0-9A-Za-z]/g, '.')}` // no '-': an .rpm version can't contain one

console.log(`RI Launcher build: shown as ${ref || cargoVersion}, installer version ${version}`)
const dir = mkdtempSync(join(tmpdir(), 'ri-launcher-'))
const config = join(dir, 'version.json')
writeFileSync(config, JSON.stringify({ version }))

const extra = process.argv.slice(2)
const r = spawnSync('npm', ['run', 'tauri', '--', 'build', '--config', config, ...extra], {
    cwd: root,
    stdio: 'inherit',
    shell: process.platform === 'win32',
    env: { ...process.env, RI_BUILD_VERSION: ref || process.env.RI_BUILD_VERSION || '' },
})
process.exit(r.status ?? 1)
