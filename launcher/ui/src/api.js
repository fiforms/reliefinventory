// Thin wrapper over the Tauri commands in src-tauri/src/main.rs. Outside Tauri (plain `npm run dev` in a
// browser) it serves mock data so the UI can be developed without the native shell:
// add ?mock=notinstalled | installing | reboot | stopped | running | local | failed to the URL.
import { invoke } from '@tauri-apps/api/core'
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
    }
    if (k === 'notinstalled' || k === 'installing' || k === 'reboot' || k === 'failed') return { ...base, state: 'not_installed', proxy_running: false }
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
