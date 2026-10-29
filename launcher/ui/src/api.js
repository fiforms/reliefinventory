// Thin wrapper over the Tauri commands in src-tauri/src/main.rs. Outside Tauri (plain `npm run dev` in a
// browser) it serves mock data so the UI can be developed without the native shell:
// add ?mock=notinstalled | installing | reboot | stopped | running | local | failed | nodocker to the URL, and
// &os=macos or &os=linux to see that platform's wording.
import { invoke } from '@tauri-apps/api/core'
import { listen } from '@tauri-apps/api/event'
import { open as openDialog } from '@tauri-apps/plugin-dialog'

export const inTauri = typeof window !== 'undefined' && '__TAURI_INTERNALS__' in window
const kind = () => new URLSearchParams(location.search).get('mock') || 'running'
const now = Math.floor(Date.now() / 1000)

function mockSummary() {
    const k = kind()
    const base = {
        state: 'running', proxy_running: true, mode: 'lan', hostname: null, default_hostname: 'hpwin11.local',
        port: 8080, timezone: null, url: 'http://hpwin11.local:8080',
        share_urls: ['http://hpwin11.local:8080', 'http://10.53.105.130:8080'],
        os: new URLSearchParams(location.search).get('os') || 'windows',
    }
    if (k === 'notinstalled' || k === 'nodocker' || k === 'installing' || k === 'reboot' || k === 'failed') return { ...base, state: 'not_installed', proxy_running: false }
    if (k === 'stopped') return { ...base, state: 'stopped', proxy_running: false }
    if (k === 'local') return { ...base, mode: 'local', url: 'http://localhost:8080', share_urls: [] }
    return base
}

function mockInstall() {
    const k = kind()
    if (k === 'installing') return { running: true, step: 'Downloading Windows Subsystem for Linux', detail: '263 of 350 MB', fraction: 0.75, needs_reboot: false, done: false, error: null }
    if (k === 'reboot') return { running: false, step: 'Restart needed', detail: 'Windows needs to restart to finish turning on its Linux features.', fraction: null, needs_reboot: true, done: false, error: null }
    if (k === 'failed') return { running: false, step: 'Install failed', detail: '', fraction: null, needs_reboot: false, done: false, error: 'Virtualization is switched off on this computer. Turn on "Intel Virtualization Technology" in the BIOS (UEFI) settings, then try again.' }
    return null
}

const mock = {
    settings: () => { const s = mockSummary(); return { mode: s.mode, hostname: s.hostname, port: s.port, timezone: s.timezone } },
    backups: [
        { path: '/opt/ri/backups/relief-backup-20261025-052845-database-3.zip', name: 'relief-backup-20261025-052845-database-3.zip', size: 42643, modified: now - 600 },
        { path: '/opt/ri/backups/relief-backup-20261025-050004-database-1.zip', name: 'relief-backup-20261025-050004-database-1.zip', size: 41957, modified: now - 5000 },
    ],
}

const call = (cmd, args, fallback) => (inTauri ? invoke(cmd, args) : Promise.resolve(typeof fallback === 'function' ? fallback() : fallback))
const unavailable = (what) => Promise.reject(`${what} only works in the desktop app.`)

export const getSummary = () => call('get_summary', {}, mockSummary)
export const getInstallStatus = () => call('get_install_status', {}, mockInstall)
export const startInstall = () => (inTauri ? invoke('start_install') : unavailable('Installing'))
export const getPreflight = () =>
    call('get_preflight', {}, () =>
        kind() === 'nodocker'
            ? 'Docker is not installed on this computer.\nIn a terminal, run:  sudo apt install docker.io docker-compose-v2\nthen:  sudo systemctl enable --now docker  and  sudo usermod -aG docker $USER\nand log out and back in. Then press Check again.'
            : null)
export const dismissInstallStatus = () => call('dismiss_install_status', {}, null)
export const start = () => (inTauri ? invoke('start') : unavailable('Starting'))
export const stop = () => (inTauri ? invoke('stop') : unavailable('Stopping'))
export const update = () => (inTauri ? invoke('update') : unavailable('Updating'))
export const uninstall = (keepData) => (inTauri ? invoke('uninstall', { keepData }) : unavailable('Uninstalling'))
export const getSettings = () => call('get_settings', {}, mock.settings)
export const applySettings = (s) =>
    inTauri ? invoke('apply_settings', { mode: s.mode, hostname: s.hostname || null, port: s.port, timezone: s.timezone || null }) : unavailable('Changing settings')
export const shareableLink = () => call('shareable_link', {}, () => mockSummary().share_urls[0] || Promise.reject('Turn on "Run as a LAN service" first.'))
export const openApp = () => call('open_app', {}, () => window.open('http://localhost:8080', '_blank'))
export const backupsList = () => call('backups_list', {}, () => mock.backups)
export const backupCreate = (scope) => (inTauri ? invoke('backup_create', { scope }) : unavailable('Backing up'))
export const backupExport = (name) => (inTauri ? invoke('backup_export', { name }) : unavailable('Saving a copy'))
export const backupImport = (path) => (inTauri ? invoke('backup_import', { path }) : unavailable('Restoring'))
export const restartComputer = () => (inTauri ? invoke('restart_computer') : unavailable('Restarting'))
export const getAutostart = () => call('get_autostart', {}, true)
export const setAutostart = (enabled) => call('set_autostart', { enabled }, enabled)
export const appVersion = () => call('app_version', {}, '0.1.0')

// R Station SD card image tab. ?mock=image-ready | image-offline | image-old | image-noimager | image-error select a state.
const imageMock = () => new URLSearchParams(location.search).get('mock') || ''
const mockImage = () => {
    const k = imageMock()
    return {
        manifest: {
            schema: 1, version: '0.4.6_0.1.5', released: '2026-10-26T02:23:10Z', notes: 'Print server fixes and the new Settings page.',
            filename: 'rstation-0.4.6_0.1.5.img.xz', size: 1233099372, sha256: '67391426a5cc1398386bd0607f7a7ff35eceb4baeb8ab29426a448385958d9a3',
            urls: ['https://example.org/rstation.img.xz'], min_launcher_version: null,
        },
        downloaded: k === 'image-ready' || k === 'image-noimager' || (mockDl !== null && Date.now() - mockDl.t >= 6000),
        path: '/home/me/.local/share/ReliefInventory/images/rstation-0.4.6_0.1.5.img.xz',
        needs_newer_launcher: k === 'image-old',
        imager_found: k !== 'image-noimager',
        offline: k === 'image-offline',
    }
}
let mockDl = null
export const imageInfo = () => call('image_info', {}, () => (imageMock() === 'image-error' ? Promise.reject("Couldn't reach the image list: dns error") : mockImage()))
export const imageDownloadStart = () => {
    if (inTauri) return invoke('image_download_start')
    mockDl = { running: true, done: 0, total: 1233099372, error: null, finished: false, t: Date.now() }
    return Promise.resolve()
}
export const imageDownloadStatus = () => {
    if (inTauri) return invoke('image_download_status')
    if (!mockDl) return Promise.resolve({ running: false, done: 0, total: 0, error: null, finished: false })
    const f = Math.min(1, (Date.now() - mockDl.t) / 6000)
    return Promise.resolve({ ...mockDl, running: f < 1, done: Math.round(f * mockDl.total), finished: f >= 1 })
}
export const imageDownloadCancel = () => call('image_download_cancel', {}, () => { mockDl = null })
export const imageWrite = () => (inTauri ? invoke('image_write') : unavailable('Opening Raspberry Pi Imager'))
export const imageReveal = () => (inTauri ? invoke('image_reveal') : unavailable('Showing the file'))
export const openToolPage = (which) => call('open_tool_page', { which }, () => window.open(which === 'etcher' ? 'https://etcher.balena.io/' : 'https://www.raspberrypi.com/software/', '_blank'))
// Remote backups: servers this computer is paired with. ?mock=remote-empty | remote | remote-running | remote-unpaired select a state.
const remoteMock = () => new URLSearchParams(location.search).get('mock') || ''
const mockServers = () => {
    const k = remoteMock()
    if (k === 'remote-empty') return []
    const server = (over) => ({
        id: 'a1b2c3d4', name: 'Statesville warehouse', url: 'https://ri.example.org', kind: 'warehouse', fingerprint: '9A77-E967-6525-8550', paired_at: now - 86400 * 9,
        schedule: { frequency: 'daily', hour: 2, minute: 0, weekday: 6 }, scope: 'database', keep: 14, dest_dir: null,
        folder: '/home/me/.local/share/ReliefInventory/remote-backups/a1b2c3d4',
        state: { last_attempt_at: now - 40000, last_success_at: now - 40000, failures: 0, last_error: null, last_file: 'relief-backup-20261025-020001-database-31.zip', needs_pairing: false, running: null },
        next_run_at: now + 46000,
        files: [
            { name: 'relief-backup-20261025-020001-database-31.zip', path: '/x/1', size: 42643000, modified: now - 40000 },
            { name: 'relief-backup-20261024-020002-database-30.zip', path: '/x/2', size: 41957000, modified: now - 126400 },
        ],
        total_bytes: 84600000,
        ...over,
    })
    const a = server({})
    if (k === 'remote-running') a.state.running = { step: 'Downloading the backup', detail: '212 of 640 MB', fraction: 0.33, started_at: now - 60 }
    if (k === 'remote-unpaired') {
        a.state.needs_pairing = true
        a.state.last_error = 'the server no longer recognises this computer: it was un-paired there'
        a.next_run_at = null
    }
    const b = server({ id: 'e5f6a7b8', name: 'Charlotte depot', url: 'http://depot.local:8080', fingerprint: '1C2D-3E4F-5A6B-7C8D', schedule: { frequency: 'off', hour: 2, minute: 0, weekday: 6 }, scope: 'full', files: [], total_bytes: 0, next_run_at: null, state: { last_attempt_at: null, last_success_at: null, failures: 0, last_error: null, last_file: null, needs_pairing: false, running: null } })
    return [a, b]
}
let mockRemote = null
export const remoteList = () => call('remote_list', {}, () => (mockRemote ??= mockServers()))
export const remotePair = (address, code, name, kind = 'warehouse') =>
    inTauri ? invoke('remote_pair', { address, code, name: name || null, kind }) : Promise.reject('Pairing only works in the desktop app.')
export const remoteConfigure = (id, options) => call('remote_configure', { id, options }, () => {
    const s = (mockRemote ??= mockServers()).find((x) => x.id === id)
    if (!s) return
    if (options.name != null) s.name = options.name
    if (options.schedule) s.schedule = options.schedule
    if (options.scope) s.scope = options.scope
    if (options.keep) s.keep = options.keep
    if (options.dest_dir != null) s.dest_dir = options.dest_dir || null
})
export const remoteBackupNow = (id) => (inTauri ? invoke('remote_backup_now', { id }) : unavailable('Backing up'))
export const remoteRemove = (id, deleteFiles) => call('remote_remove', { id, deleteFiles }, () => { mockRemote = (mockRemote ?? mockServers()).filter((s) => s.id !== id) })
export const remoteReveal = (id) => (inTauri ? invoke('remote_reveal', { id }) : unavailable('Showing the folder'))
export const pickFolder = async () => {
    if (!inTauri) return null
    const f = await openDialog({ directory: true, multiple: false })
    return typeof f === 'string' ? f : null
}
/** Errors raised from the tray menu (e.g. Quit couldn't shut the server down). */
export const onTrayError = (fn) => (inTauri ? listen('tray-error', (e) => fn(String(e.payload))) : Promise.resolve(() => {}))
export const pickBackupFile = async () => {
    if (!inTauri) return null
    const f = await openDialog({ multiple: false, filters: [{ name: 'Relief Inventory backup', extensions: ['zip'] }] })
    return typeof f === 'string' ? f : null
}

/** Copy text; falls back to a hidden textarea where the async clipboard API is unavailable. */
export async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text)
        return
    } catch { /* fall through */ }
    const ta = document.createElement('textarea')
    ta.value = text
    ta.style.position = 'fixed'
    ta.style.opacity = '0'
    document.body.appendChild(ta)
    ta.select()
    const ok = document.execCommand('copy')
    ta.remove()
    if (!ok) throw new Error('Could not copy to the clipboard')
}
