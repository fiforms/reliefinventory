// Thin wrapper over the Tauri commands in src-tauri/src/main.rs. Outside Tauri (plain `npm run dev`
// in a browser) it serves mock data, so the UI can be developed and screenshotted without the
// native shell: add ?mock=setup | paired | problems | revoked to the URL.
import { invoke } from '@tauri-apps/api/core'
import { listen } from '@tauri-apps/api/event'

export const inTauri = typeof window !== 'undefined' && '__TAURI_INTERNALS__' in window

const ago = (s) => new Date(Date.now() - s * 1000).toISOString()

function mockStatus(kind) {
    const base = {
        paired: false, server_url: null, device_name: null, entity_name: null, platform: 'linux',
        printers: [], printers_error: null, realtime: false, last_heartbeat_at: null,
        last_error: null, notice: null, recent_jobs: [],
    }
    if (kind === 'setup') return base
    if (kind === 'revoked') {
        return { ...base, server_url: 'https://ri.example.org', notice: 'This computer was un-paired from the server. Pair it again with a new code.' }
    }
    const paired = {
        ...base, paired: true, server_url: 'https://ri.example.org', device_name: 'Front Desk PC',
        entity_name: 'Statesville Warehouse', realtime: true, last_heartbeat_at: ago(12),
        printers: [
            { queue_name: 'Zebra_ZD421', state: 'idle', state_message: null },
            { queue_name: 'HP-LaserJet-M426', state: 'printing', state_message: null },
            { queue_name: 'Brother-5490', state: 'idle', state_message: null },
        ],
        recent_jobs: [
            { id: 214, queue_name: 'Zebra_ZD421', title: 'Pallet 118 label', copies: 2, state: 'printed', error: null, at: ago(40) },
            { id: 213, queue_name: 'HP-LaserJet-M426', title: 'Outstanding Orders Report', copies: 1, state: 'printing', error: null, at: ago(8) },
            { id: 211, queue_name: 'Zebra_ZD421', title: 'Pallet 117 label', copies: 1, state: 'printed', error: null, at: ago(900) },
        ],
    }
    if (kind === 'problems') {
        return {
            ...paired, realtime: false, last_error: "can't reach the server: connection failed",
            printers: [
                { queue_name: 'Zebra_ZD421', state: 'error', state_message: 'Out of paper or labels' },
                { queue_name: 'HP-LaserJet-M426', state: 'offline', state_message: 'Printer not reachable: The printer may not exist or is unavailable at this time.' },
                { queue_name: 'Brother-5490', state: 'idle', state_message: null },
            ],
            recent_jobs: [
                { id: 215, queue_name: 'Zebra_ZD421', title: 'Pallet 119 label', copies: 1, state: 'failed', error: "Printer 'Zebra_ZD421' needs attention: Out of paper or labels.", at: ago(30) },
                ...paired.recent_jobs.slice(2),
            ],
        }
    }
    return paired
}

const mockKind = () => new URLSearchParams(location.search).get('mock') || 'paired'

export async function getStatus() {
    return inTauri ? invoke('get_status') : mockStatus(mockKind())
}

export async function onStatus(cb) {
    if (!inTauri) return () => {}
    return listen('status', (e) => cb(e.payload))
}

export const pair = (serverUrl, code, deviceName) =>
    inTauri ? invoke('pair', { serverUrl, code, deviceName: deviceName || null }) : Promise.reject('Pairing only works in the desktop app.')
export const unpair = () => (inTauri ? invoke('unpair') : Promise.resolve())
export const pollNow = () => (inTauri ? invoke('poll_now') : Promise.resolve())
export const openPortal = (serverUrl) =>
    inTauri ? invoke('open_portal_cmd') : Promise.resolve(window.open(`${serverUrl}/dashboard`, '_blank'))
export const getAutostart = () => (inTauri ? invoke('get_autostart') : Promise.resolve(true))
export const setAutostart = (enabled) => (inTauri ? invoke('set_autostart', { enabled }) : Promise.resolve(enabled))
export const appVersion = () => (inTauri ? invoke('app_version') : Promise.resolve('0.1.0'))
export const openExternal = (url) => (inTauri ? invoke('open_external', { url }) : Promise.resolve(window.open(url, '_blank')))
export const openPrinterSettings = () => (inTauri ? invoke('open_printer_settings') : Promise.resolve())
