<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import * as api from './api'
import iconUrl from './assets/icon.png'
import splashUrl from './assets/splash.webp'
import RStationImage from './RStationImage.vue'

const summary = ref(null)
const install = ref(null)
const backups = ref([])
const autostart = ref(null)
const version = ref('')
const busy = ref('') // what is running right now ('' = nothing); disables the buttons that would collide
const msg = ref(null) // { kind: 'ok' | 'bad', text }
const confirming = ref('') // 'uninstall' | 'restore' | ''
const keepData = ref(true)
const blocked = ref(null) // why this computer can't be installed on yet (plain words), or null
const restoreFile = ref('')
const tab = ref(new URLSearchParams(location.search).get('mock')?.startsWith('image') ? 'image' : 'app') // 'app' | 'backups' | 'image'
const form = reactive({ hostname: '', port: 8080, timezone: '' })
let formLoaded = false
let timer

const note = (kind, text) => (msg.value = { kind, text })
const fail = (e) => note('bad', String(e))

async function refresh() {
    try {
        summary.value = await api.getSummary()
        install.value = await api.getInstallStatus()
        if (!formLoaded) {
            const s = await api.getSettings()
            Object.assign(form, { hostname: s.hostname || '', port: s.port, timezone: s.timezone || '' })
            formLoaded = true
        }
        if (summary.value.state === 'running' && !busy.value) backups.value = await api.backupsList().catch(() => backups.value)
    } catch (e) {
        fail(e)
    }
    schedule()
}

function schedule() {
    clearTimeout(timer)
    timer = setTimeout(refresh, install.value?.running ? 1500 : 5000)
}

const checkAgain = async () => {
    try { blocked.value = await api.getPreflight() } catch (e) { blocked.value = String(e) }
}

onMounted(async () => {
    checkAgain()
    api.onTrayError(fail)
    version.value = await api.appVersion()
    api.getAutostart().then((v) => (autostart.value = v)).catch(() => {})
    refresh()
})
onBeforeUnmount(() => clearTimeout(timer))

const state = computed(() => summary.value?.state)
const lan = computed(() => summary.value?.mode === 'lan')
const mac = computed(() => summary.value?.os === 'macos')
const installing = computed(() => install.value?.running)
const needsReboot = computed(() => install.value?.needs_reboot && !install.value?.running)
const linux = computed(() => summary.value?.os === 'linux')
const installError = computed(() => (!install.value?.running ? install.value?.error : null))
const pill = computed(() => {
    if (installing.value) return { cls: 'busy', text: 'Installing…' }
    if (state.value === 'running') return { cls: 'ok', text: lan.value ? 'Running · shared on your network' : 'Running · this computer only' }
    if (state.value === 'stopped') return { cls: 'off', text: 'Stopped' }
    return { cls: 'off', text: 'Not installed' }
})

async function run(label, fn, okText) {
    busy.value = label
    msg.value = null
    try {
        await fn()
        if (okText) note('ok', okText)
    } catch (e) {
        fail(e)
    } finally {
        busy.value = ''
        await refresh()
    }
}

const doInstall = () => run('install', async () => { await api.startInstall() }) // progress then arrives via the status file
const doStart = () => run('start', api.start)
const doStop = () => run('stop', api.stop)
const doUpdate = () => run('update', api.update, 'Updated. Relief Inventory restarted with the latest version.')
const doBackup = () => run('backup', () => api.backupCreate('database'), 'Backup created.')

async function doUninstall() {
    confirming.value = ''
    await run('uninstall', () => api.uninstall(keepData.value), keepData.value
        ? 'Uninstalled. Your data was kept; installing again will bring it back.'
        : 'Uninstalled and all data removed.')
}

async function setMode(e) {
    const mode = e.target.checked ? 'lan' : 'local'
    await run('settings', () => api.applySettings({ mode, hostname: form.hostname, port: form.port, timezone: form.timezone }))
}

const saveSettings = () =>
    run('settings', () => api.applySettings({ mode: summary.value.mode, hostname: form.hostname, port: Number(form.port), timezone: form.timezone }), 'Settings saved.')

async function resetName() {
    form.hostname = ''
    await saveSettings()
}

async function copy(text, what = 'Link') {
    try {
        await api.copyText(text)
        note('ok', `${what} copied: ${text}`)
    } catch (e) {
        fail(e)
    }
}

async function copyLink() {
    try {
        await copy(await api.shareableLink(), 'Shareable link')
    } catch (e) {
        fail(e)
    }
}

async function saveCopy(b) {
    await run('export', async () => {
        const path = await api.backupExport(b.name)
        note('ok', `Saved a read-only copy to ${path}`)
    })
}

async function chooseRestore() {
    const f = await api.pickBackupFile().catch(fail)
    if (f) {
        restoreFile.value = f
        confirming.value = 'restore'
    }
}

async function doRestore() {
    confirming.value = ''
    await run('restore', () => api.backupImport(restoreFile.value), 'Restored from the backup file. A safety backup of what was here before was made first.')
}

async function toggleAutostart() {
    try { autostart.value = await api.setAutostart(!autostart.value) } catch (e) { fail(e) }
}

const size = (n) => (n > 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`)
const when = (s) => new Date(s * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
const alternates = computed(() => (summary.value?.share_urls || []).slice(1))
</script>

<template>
    <main v-if="summary" class="wrap">
        <header class="top">
            <img class="mark" :src="iconUrl" alt="" />
            <h1>RI Launcher</h1>
            <span class="pill" :class="pill.cls">{{ pill.text }}</span>
        </header>

        <p v-if="msg" class="banner" :class="msg.kind === 'ok' ? 'good' : 'bad'" :role="msg.kind === 'ok' ? 'status' : 'alert'">
            {{ msg.text }} <button class="quiet" @click="msg = null">Dismiss</button>
        </p>

        <nav class="tabs" role="tablist">
            <button role="tab" :aria-selected="tab === 'app'" :class="{ on: tab === 'app' }" @click="tab = 'app'">Local RI Server</button>
            <button role="tab" :aria-selected="tab === 'backups'" :class="{ on: tab === 'backups' }" @click="tab = 'backups'">Backups</button>
            <button role="tab" :aria-selected="tab === 'image'" :class="{ on: tab === 'image' }" @click="tab = 'image'">R Station SD card</button>
        </nav>

        <RStationImage v-if="tab === 'image'" @note="note" />

        <!-- Backups tab -->
        <section v-if="tab === 'backups'" class="card">
            <div class="who">
                <h2>Backups</h2>
                <div class="btns">
                    <button class="quiet" @click="doBackup" :disabled="!!busy || state !== 'running'">Back up now</button>
                    <button class="quiet" @click="chooseRestore" :disabled="!!busy || state !== 'running'">Restore from file…</button>
                </div>
            </div>
            <div class="statusline">
                <span class="pill" :class="state === 'not_installed' ? 'off' : 'ok'">{{ state === 'not_installed' ? 'Not installed' : 'Installed' }}</span>
                <span v-if="state !== 'not_installed'" class="pill" :class="state === 'running' ? 'ok' : 'off'">{{ state === 'running' ? 'Running' : 'Stopped' }}</span>
            </div>
            <template v-if="state === 'not_installed'">
                <p class="muted">Relief Inventory isn't installed on this computer, so there is nothing to back up here. You can still restore a backup after installing it.</p>
                <div class="actions"><button @click="tab = 'app'">Go to Local RI Server</button></div>
            </template>
            <p v-else-if="state !== 'running'" class="muted">Start Relief Inventory (on the Local RI Server tab) to see and make backups.</p>
            <ul v-else-if="backups.length" class="rows">
                <li v-for="b in backups.slice(0, 8)" :key="b.path">
                    <div class="grow">
                        <div class="name small">{{ b.name }}</div>
                        <div class="muted small">{{ when(b.modified) }} · {{ size(b.size) }}</div>
                    </div>
                    <button class="quiet" @click="saveCopy(b)" :disabled="!!busy" title="Copy this backup to your Documents folder as a read-only file">Save a copy</button>
                </li>
            </ul>
            <p v-else class="muted">No backups yet. Relief Inventory makes one every day; <strong>Back up now</strong> makes one immediately.</p>
            <p v-if="state !== 'not_installed'" class="muted small foot">
                "Save a copy" puts a read-only file in Documents\Relief Inventory Backups. Keep copies somewhere other than this computer too.
                Restoring replaces everything currently in Relief Inventory.
            </p>
            <div v-if="confirming === 'restore'" class="banner bad" role="alertdialog">
                <p><strong>Replace everything with this backup?</strong><br />{{ restoreFile }}</p>
                <p class="small">A safety backup of the current data is made first. Relief Inventory is unavailable for a few minutes while it restores.</p>
                <div class="actions">
                    <button class="danger" @click="doRestore">Yes, restore</button>
                    <button @click="confirming = ''">Cancel</button>
                </div>
            </div>
        </section>


        <template v-if="tab === 'app'">
        <img v-if="state === 'not_installed'" class="splash" :src="splashUrl" alt="Relief Inventory" />

        <!-- Not installed: install / progress / restart / error -->
        <section v-if="state === 'not_installed'" class="card">
            <template v-if="installing">
                <h2>{{ install.step }}</h2>
                <div class="bar" :class="{ indeterminate: install.fraction == null }"><div :style="install.fraction != null ? { width: Math.round(install.fraction * 100) + '%' } : {}" /></div>
                <p class="muted small">{{ install.detail }}</p>
                <p class="muted small">This can take 10–20 minutes the first time. You can leave this window open and keep working.</p>
            </template>
            <template v-else-if="needsReboot">
                <h2>Restart needed</h2>
                <p>Windows has to restart to finish turning on the features Relief Inventory uses. Save your work, then restart. After you sign back in, open RI Launcher and press <strong>Continue installation</strong>.</p>
                <div class="actions">
                    <button class="primary" @click="run('restart', api.restartComputer, 'Restarting in 20 seconds…')">Restart now</button>
                    <button @click="doInstall" :disabled="!!busy" title="Use this if you already restarted">Continue installation</button>
                </div>
            </template>
            <template v-else>
                <h2>Install Relief Inventory</h2>
                <p class="muted" v-if="linux">
                    This uses Docker on this computer. It downloads Relief Inventory (roughly 1 GB) and takes a few minutes. It does not
                    need administrator permission, but Docker has to be installed and running.
                </p>
                <p class="muted" v-else-if="mac">
                    This sets up everything on this Mac: a small Linux virtual machine, Docker Engine and Relief Inventory itself. It
                    downloads roughly 1 GB and takes 10–20 minutes. It doesn't need an administrator password or a restart.
                </p>
                <p class="muted" v-else>
                    This sets up everything on this computer: a small Linux layer, Docker Engine and Relief Inventory itself. It
                    downloads roughly 1 GB, takes 10–20 minutes, and asks for administrator permission. Windows may need to restart once.
                </p>
                <div v-if="blocked" class="banner bad" role="alert">
                    <p class="pre">{{ blocked }}</p>
                    <button type="button" @click="checkAgain" :disabled="!!busy">Check again</button>
                </div>
                <p v-if="installError" class="banner bad" role="alert">{{ installError }}</p>
                <div class="actions">
                    <button class="primary" @click="doInstall" :disabled="!!busy || !!blocked">{{ installError ? 'Try again' : 'Install Relief Inventory' }}</button>
                </div>
            </template>
        </section>

        <!-- Installed: run -->
        <section v-else class="card">
            <div class="who">
                <div>
                    <h2>Relief Inventory</h2>
                    <p class="muted small" v-if="busy === 'start'">Starting… the first start can take a minute or two.</p>
                    <p class="muted small" v-else-if="busy === 'stop'">Stopping…</p>
                    <p class="muted small" v-else-if="busy === 'update'">Downloading the update and restarting…</p>
                    <p class="muted small" v-else-if="state === 'running'">Open it in your browser on this computer, or share the link below.</p>
                    <p class="muted small" v-else>Not running. Start it to use it.</p>
                </div>
                <div class="btns">
                    <button v-if="state === 'running'" class="primary" @click="api.openApp().catch(fail)" :disabled="!!busy">Open Relief Inventory</button>
                    <button v-if="state === 'running'" @click="doStop" :disabled="!!busy">Stop</button>
                    <button v-else class="primary" @click="doStart" :disabled="!!busy">Start Relief Inventory</button>
                </div>
            </div>
        </section>

        <!-- Network -->
        <section v-if="state !== 'not_installed'" class="card">
            <h2>Sharing</h2>
            <label class="switch">
                <input type="checkbox" :checked="lan" @change="setMode" :disabled="!!busy" />
                <span class="track" aria-hidden="true" />
                <span class="grow">
                    <strong>Run as a LAN service</strong>
                    <span class="muted small block">{{ lan ? 'Other computers, tablets and phones on your network can open Relief Inventory.' : 'Local only: just this computer can open it.' }}</span>
                </span>
            </label>

            <template v-if="lan">
                <div class="link-box">
                    <code>{{ summary.share_urls[0] }}</code>
                    <button class="primary" @click="copyLink">Copy shareable link</button>
                </div>
                <p class="muted small">Send this to others on the same network (the same Wi-Fi or office network).</p>
                <ul v-if="alternates.length" class="rows small">
                    <li v-for="u in alternates" :key="u">
                        <span class="grow muted">If that name doesn't work on someone's device, use <code>{{ u }}</code></span>
                        <button class="quiet" @click="copy(u, 'Address')">Copy</button>
                    </li>
                </ul>
                <form @submit.prevent="saveSettings">
                    <label>Name other devices use
                        <input v-model="form.hostname" :placeholder="summary.default_hostname" autocomplete="off" spellcheck="false" />
                    </label>
                    <p class="muted small">
                        Leave empty to use this computer's name (<code>{{ summary.default_hostname }}</code>). Names ending in <code>.local</code> work on
                        Windows, Mac, iPhone and most Android devices with no setup. You can also enter an IP address.
                    </p>
                    <div class="actions">
                        <button class="primary" :disabled="!!busy">Save name</button>
                        <button type="button" v-if="summary.hostname" @click="resetName" :disabled="!!busy">Use computer name</button>
                    </div>
                </form>
                <p class="muted small" v-if="linux">
                    If this computer has a firewall (such as <code>ufw</code> or <code>firewalld</code>), allow port {{ summary.port }} for the other computers.
                    Names ending in <code>.local</code> also need the Avahi service, which most desktop distributions run already; if a link doesn't
                    open, use one of the IP addresses. The launcher has to stay running (it lives in the tray) for others to connect.
                </p>
                <p class="muted small" v-else-if="mac">
                    If macOS asks whether RI Launcher may accept incoming network connections, choose <strong>Allow</strong>.
                    The launcher has to stay running (it lives in the menu bar) for others to connect, and this Mac must be awake.
                </p>
                <p class="muted small" v-else>
                    The first time, Windows may ask whether to allow RI Launcher through the firewall. Choose <strong>Allow</strong>, on private networks.
                    The launcher has to stay running (it lives in the tray) for others to connect.
                </p>
            </template>
        </section>

        <!-- Maintenance -->
        <section v-if="state !== 'not_installed'" class="card">
            <h2>Maintenance</h2>
            <label v-if="autostart !== null" class="check-row">
                <input type="checkbox" :checked="autostart" @change="toggleAutostart" />
                Start RI Launcher and Relief Inventory when I sign in to this computer
            </label>
            <div class="actions">
                <button @click="doUpdate" :disabled="!!busy || state !== 'running'" title="Download the newest version and restart">Update Relief Inventory</button>
                <button class="danger" @click="confirming = 'uninstall'" :disabled="!!busy">Uninstall…</button>
            </div>
            <div v-if="confirming === 'uninstall'" class="banner bad" role="alertdialog">
                <p><strong>Uninstall Relief Inventory?</strong></p>
                <label class="check-row">
                    <input type="checkbox" v-model="keepData" />
                    Keep my data (the database stays on this computer, so installing again brings everything back)
                </label>
                <p v-if="!keepData" class="small">Everything — donations, orders, inventory — will be permanently deleted. Save a copy of a backup first.</p>
                <div class="actions">
                    <button class="danger" @click="doUninstall">{{ keepData ? 'Uninstall' : 'Uninstall and delete everything' }}</button>
                    <button @click="confirming = ''">Cancel</button>
                </div>
            </div>
            <details>
                <summary>Advanced</summary>
                <form @submit.prevent="saveSettings">
                    <div class="grid2">
                        <label>Port
                            <input v-model.number="form.port" inputmode="numeric" />
                        </label>
                        <label>Timezone
                            <input v-model="form.timezone" placeholder="America/New_York" autocomplete="off" spellcheck="false" />
                        </label>
                    </div>
                    <p class="muted small">Port is the number after the colon in the address (default 8080). Timezone is a name like America/New_York; empty uses the app's default (UTC). Changing these restarts Relief Inventory briefly.</p>
                    <div class="actions"><button :disabled="!!busy">Save</button></div>
                </form>
            </details>
        </section>

        </template>

        <footer class="muted small foot">RI Launcher {{ version }}</footer>
    </main>
</template>

<style scoped>
.wrap { max-width: 560px; margin: 0 auto; padding: 16px; display: flex; flex-direction: column; gap: 14px; }
.top { display: flex; align-items: center; gap: 10px; }
.top h1 { font-size: 20px; margin: 0; flex: 1; }
.mark { width: 32px; height: 32px; }
.pre { white-space: pre-wrap; margin: 0 0 8px; }
.tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); }
.tabs button { border: 0; border-bottom: 3px solid transparent; border-radius: 6px 6px 0 0; background: transparent; padding: 8px 14px; font-weight: 600; color: var(--muted); }
.tabs button.on { color: inherit; border-bottom-color: var(--accent); }
.statusline { display: flex; gap: 8px; margin: 0 0 10px; }
.splash { width: 100%; border-radius: 10px; display: block; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 16px; }
h2 { font-size: 15px; margin: 0 0 10px; }
.muted { color: var(--muted); }
.small { font-size: 13px; }
.block { display: block; font-weight: 400; }
.name { font-weight: 600; word-break: break-word; }
.who { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.who > div:first-child { min-width: 0; }
.who h2 { margin-bottom: 4px; }
.grow { flex: 1; min-width: 0; }
.btns { display: flex; gap: 8px; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end; }
.btns button { white-space: nowrap; }
.rows { list-style: none; margin: 8px 0 0; padding: 0; }
.rows li { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line); }
.rows li:first-child { border-top: 0; }
.foot { margin: 12px 0 0; }
code { font-size: 13px; overflow-wrap: anywhere; }

.pill { font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 999px; white-space: nowrap; background: var(--off-bg); color: var(--off-text); }
.pill.ok { background: var(--ok-bg); color: var(--ok-text); }
.pill.busy { background: var(--busy-bg); color: var(--busy-text); }
.pill.off { background: var(--off-bg); color: var(--off-text); }

.banner { border-radius: 8px; padding: 8px 12px; margin: 10px 0; font-size: 14px; }
.banner.good { background: var(--ok-bg); color: var(--ok-text); }
.banner.bad { background: var(--bad-bg); color: var(--bad-text); }
.banner p { margin: 0 0 8px; }
.banner > button.quiet { margin-left: 8px; }

form { display: flex; flex-direction: column; gap: 12px; margin-top: 12px; }
label { display: flex; flex-direction: column; gap: 4px; font-weight: 600; font-size: 14px; }
label.switch { flex-direction: row; }
label.check-row { flex-direction: row; align-items: center; gap: 8px; font-weight: 400; margin: 6px 0; }
input:not([type=checkbox]) { padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); font-weight: 400; }
input:focus-visible, button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

button { padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line); background: var(--card); cursor: pointer; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
button.primary { background: var(--accent); color: var(--accent-text); border-color: var(--accent); font-weight: 600; }
button.quiet { padding: 6px 12px; font-size: 13px; }
button.danger { color: var(--bad-text); border-color: var(--bad-text); background: transparent; }
p { margin: 0 0 8px; }
</style>
