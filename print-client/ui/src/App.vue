<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import * as api from './api'
import About from './About.vue'

const status = ref(null)
const now = ref(Date.now())
const autostart = ref(null)
const confirmingUnpair = ref(false)
const showAbout = ref(false)
let unlisten = () => {}
let ticker

// setup form
const serverUrl = ref('')
const code = ref('')
const deviceName = ref('')
const pairing = ref(false)
const pairError = ref('')

onMounted(async () => {
    status.value = await api.getStatus()
    serverUrl.value = status.value.server_url || ''
    unlisten = await api.onStatus((s) => (status.value = s))
    api.getAutostart().then((v) => (autostart.value = v)).catch(() => {})
    ticker = setInterval(() => (now.value = Date.now()), 15000)
})
onBeforeUnmount(() => {
    unlisten()
    clearInterval(ticker)
})

const paired = computed(() => status.value?.paired)

const STATE = {
    idle: { label: 'Ready', cls: 'ok' },
    printing: { label: 'Printing', cls: 'busy' },
    error: { label: 'Needs attention', cls: 'warn' },
    offline: { label: 'Offline', cls: 'off' },
}
const stateOf = (p) => STATE[p.state] || STATE.idle

const JOB = {
    printing: { label: 'Printing', cls: 'busy' },
    printed: { label: 'Printed', cls: 'ok' },
    failed: { label: 'Failed', cls: 'bad' },
}

function ago(iso) {
    if (!iso) return ''
    const s = Math.max(0, Math.round((now.value - new Date(iso).getTime()) / 1000))
    if (s < 45) return 'just now'
    if (s < 3600) return `${Math.round(s / 60)} min ago`
    if (s < 86400) return `${Math.round(s / 3600)} h ago`
    return `${Math.round(s / 86400)} d ago`
}

const host = computed(() => {
    try { return new URL(status.value.server_url).host } catch { return status.value?.server_url || '' }
})

async function doPair() {
    pairError.value = ''
    pairing.value = true
    try {
        await api.pair(serverUrl.value, code.value, deviceName.value)
        code.value = ''
    } catch (e) {
        pairError.value = String(e)
    } finally {
        pairing.value = false
    }
}

async function doUnpair() {
    if (!confirmingUnpair.value) {
        confirmingUnpair.value = true
        return
    }
    confirmingUnpair.value = false
    await api.unpair()
}

async function toggleAutostart() {
    try { autostart.value = await api.setAutostart(!autostart.value) } catch (e) { pairError.value = String(e) }
}
</script>

<template>
    <main v-if="status && showAbout" class="wrap">
        <About :platform="status.platform" @close="showAbout = false" />
    </main>
    <main v-else-if="status" class="wrap">
        <header class="top">
            <svg class="mark" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
            </svg>
            <h1>RI Print</h1>
            <span v-if="paired" class="pill" :class="status.last_error ? 'warn' : 'ok'">
                {{ status.last_error ? 'Connection problem' : status.realtime ? 'Connected · instant' : 'Connected' }}
            </span>
            <button class="quiet" @click="showAbout = true" title="About RI Print: version, license and links">About</button>
        </header>

        <!-- Not paired: setup -->
        <section v-if="!paired" class="card">
            <h2>Connect this computer</h2>
            <p class="muted">
                This makes the printers on this computer available to your Relief Inventory website. On the website, open
                <strong>Setup → Devices → Pair a New Device or Print Server</strong> and enter the 6-digit code it shows.
            </p>
            <p v-if="status.notice" class="banner warn" role="status">{{ status.notice }}</p>
            <form @submit.prevent="doPair">
                <label>Server address
                    <input v-model="serverUrl" placeholder="ri.example.org" autocomplete="off" spellcheck="false" required />
                </label>
                <label>Pairing code
                    <input v-model="code" class="code" inputmode="numeric" maxlength="6" pattern="[0-9]{6}" placeholder="123456" autocomplete="off" required />
                </label>
                <label><span>Name for this computer <span class="muted">(optional)</span></span>
                    <input v-model="deviceName" placeholder="Front Desk PC" autocomplete="off" />
                </label>
                <p v-if="pairError" class="banner bad" role="alert">{{ pairError }}</p>
                <button class="primary" :disabled="pairing || code.length !== 6 || !serverUrl.trim()">
                    {{ pairing ? 'Pairing…' : 'Pair' }}
                </button>
            </form>
        </section>

        <!-- Paired: status -->
        <template v-else>
            <section class="card">
                <div class="who">
                    <div>
                        <div class="name">{{ status.device_name || 'This computer' }}</div>
                        <div class="muted">{{ status.entity_name }}<template v-if="status.entity_name"> · </template>{{ host }}</div>
                    </div>
                    <div class="btns">
                        <button class="quiet" @click="api.openPortal(status.server_url)" title="Open the Relief Inventory dashboard in your web browser">Open portal</button>
                        <button class="quiet" @click="api.pollNow()" title="Look for waiting print jobs right now">Check now</button>
                    </div>
                </div>
                <p v-if="status.last_error" class="banner bad" role="alert">{{ status.last_error }}</p>
                <p v-else class="muted small">
                    Last contact {{ ago(status.last_heartbeat_at) || '—' }} ·
                    {{ status.realtime ? 'new jobs arrive instantly' : 'checking for jobs every few seconds' }}
                </p>
            </section>

            <section class="card">
                <div class="who">
                    <h2>Printers on this computer</h2>
                    <button class="quiet" @click="api.openPrinterSettings().catch((e) => (pairError = String(e)))" title="Open this computer's own printer settings to add or fix printers">Printer settings</button>
                </div>
                <p v-if="status.printers_error" class="banner bad" role="alert">{{ status.printers_error }}</p>
                <ul v-if="status.printers.length" class="rows">
                    <li v-for="p in status.printers" :key="p.queue_name">
                        <div class="grow">
                            <div class="name">{{ p.queue_name }}</div>
                            <div v-if="p.state_message" class="muted small">{{ p.state_message }}</div>
                        </div>
                        <span class="pill" :class="stateOf(p).cls">{{ stateOf(p).label }}</span>
                    </li>
                </ul>
                <p v-else-if="!status.printers_error" class="muted">
                    No printers found. Add one in this computer's printer settings; it will show up here within a few seconds.
                </p>
                <p class="muted small foot">
                    Name each printer and choose its paper type on the website's Devices page before it can receive jobs.
                </p>
            </section>

            <section class="card">
                <h2>Recent print jobs</h2>
                <ul v-if="status.recent_jobs.length" class="rows">
                    <li v-for="j in status.recent_jobs" :key="j.id">
                        <div class="grow">
                            <div class="name">{{ j.title || `Job #${j.id}` }}<span v-if="j.copies > 1" class="muted"> × {{ j.copies }}</span></div>
                            <div class="muted small">{{ j.queue_name }} · {{ ago(j.at) }}</div>
                            <div v-if="j.error" class="small err">{{ j.error }}</div>
                        </div>
                        <span class="pill" :class="(JOB[j.state] || JOB.printing).cls">{{ (JOB[j.state] || JOB.printing).label }}</span>
                    </li>
                </ul>
                <p v-else class="muted">Nothing printed yet since this app started.</p>
            </section>

            <section class="card settings">
                <label v-if="autostart !== null" class="check">
                    <input type="checkbox" :checked="autostart" @change="toggleAutostart" />
                    Start RI Print when I sign in to this computer
                </label>
                <p class="muted small">Closing this window leaves printing running in the background; use the tray icon to reopen or quit.</p>
                <div class="actions">
                    <button class="danger" @click="doUnpair" @blur="confirmingUnpair = false">
                        {{ confirmingUnpair ? 'Click again to un-pair this computer' : 'Un-pair this computer' }}
                    </button>
                </div>
            </section>
        </template>
    </main>
</template>

<style scoped>
.wrap { max-width: 560px; margin: 0 auto; padding: 16px; display: flex; flex-direction: column; gap: 14px; }
.top { display: flex; align-items: center; gap: 10px; }
.top h1 { font-size: 20px; margin: 0; flex: 1; }
.mark { width: 28px; height: 28px; color: var(--accent); }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 16px; }
h2 { font-size: 15px; margin: 0 0 10px; }
.muted { color: var(--muted); }
.small { font-size: 13px; }
.name { font-weight: 600; word-break: break-word; }
.who { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.grow { flex: 1; min-width: 0; }
.btns { display: flex; gap: 8px; flex-shrink: 0; }
.btns button { white-space: nowrap; }
.who > div:first-child { min-width: 0; }
.rows { list-style: none; margin: 0; padding: 0; }
.rows li { display: flex; align-items: flex-start; gap: 12px; padding: 10px 0; border-top: 1px solid var(--line); }
.rows li:first-child { border-top: 0; padding-top: 0; }
.foot { margin: 12px 0 0; }
.err { color: var(--bad-text); margin-top: 2px; }

.pill { font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 999px; white-space: nowrap; background: var(--off-bg); color: var(--off-text); }
.pill.ok { background: var(--ok-bg); color: var(--ok-text); }
.pill.busy { background: var(--busy-bg); color: var(--busy-text); }
.pill.warn { background: var(--warn-bg); color: var(--warn-text); }
.pill.bad { background: var(--bad-bg); color: var(--bad-text); }
.pill.off { background: var(--off-bg); color: var(--off-text); }

.banner { border-radius: 8px; padding: 8px 12px; margin: 10px 0; font-size: 14px; }
.banner.warn { background: var(--warn-bg); color: var(--warn-text); }
.banner.bad { background: var(--bad-bg); color: var(--bad-text); }

form { display: flex; flex-direction: column; gap: 12px; margin-top: 12px; }
label { display: flex; flex-direction: column; gap: 4px; font-weight: 600; font-size: 14px; }
input:not([type=checkbox]) { padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); font-weight: 400; }
input:focus-visible, button:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
input.code { font-size: 22px; letter-spacing: 0.3em; text-align: center; font-variant-numeric: tabular-nums; }

button { padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line); background: var(--card); cursor: pointer; }
button:disabled { opacity: 0.5; cursor: not-allowed; }
button.primary { background: var(--accent); color: var(--accent-text); border-color: var(--accent); font-weight: 600; }
button.quiet { padding: 6px 12px; font-size: 13px; }
button.danger { color: var(--bad-text); border-color: var(--bad-text); background: transparent; }

.settings .check { flex-direction: row; align-items: center; gap: 8px; font-weight: 400; }
.actions { margin-top: 12px; }
p { margin: 0 0 8px; }
</style>
