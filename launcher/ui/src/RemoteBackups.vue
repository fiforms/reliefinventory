<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import * as api from './api'

const emit = defineEmits(['note'])
const servers = ref(null) // null until the first answer
const loadError = ref('')
const busy = ref('')
const pair = reactive({ address: '', code: '', name: '', kind: 'warehouse' })
const drafts = reactive({}) // server id -> the settings being edited
const confirmRemove = ref('')
const deleteFiles = ref(false)
let timer

const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']
const mb = (n) => (n === 0 ? '0 MB' : n >= 1073741824 ? `${(n / 1073741824).toFixed(1)} GB` : `${Math.max(0.1, n / 1048576).toFixed(n >= 10485760 ? 0 : 1)} MB`)
const when = (s) => (s ? new Date(s * 1000).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : 'never')
const pad = (n) => String(n).padStart(2, '0')

function draftFrom(s) {
    return {
        name: s.name,
        frequency: s.schedule.frequency,
        time: `${pad(s.schedule.hour)}:${pad(s.schedule.minute)}`,
        weekday: s.schedule.weekday,
        scope: s.scope,
        keep: s.keep,
        folder: s.dest_dir || '',
    }
}

async function load() {
    try {
        const list = await api.remoteList()
        // a poll must never overwrite what someone is typing: a draft is made once per server and replaced only after Save
        for (const s of list) if (!drafts[s.id]) drafts[s.id] = draftFrom(s)
        servers.value = list
        loadError.value = ''
    } catch (e) {
        loadError.value = String(e)
    }
    timer = setTimeout(load, servers.value?.some((s) => s.state.running) ? 1000 : 5000)
}
onMounted(load)
onBeforeUnmount(() => clearTimeout(timer))

async function act(label, fn, okText) {
    busy.value = label
    try {
        const r = await fn()
        if (okText) emit('note', 'ok', typeof okText === 'function' ? okText(r) : okText)
    } catch (e) {
        emit('note', 'bad', String(e))
    } finally {
        busy.value = ''
        clearTimeout(timer)
        await load()
    }
}

const doPair = () => act('pair', async () => {
    const r = await api.remotePair(pair.address, pair.code, pair.name, pair.kind)
    Object.assign(pair, { address: '', code: '', name: '', kind: 'warehouse' })
    delete drafts[r.id] // re-pairing an existing server picks its settings up again
    return r
}, (r) => r.warning || 'Paired. A first backup starts shortly, then one runs on the schedule below.')

function optionsOf(id) {
    const d = drafts[id]
    const [hour, minute] = d.time.split(':').map(Number)
    return {
        name: d.name,
        schedule: { frequency: d.frequency, hour: hour || 0, minute: minute || 0, weekday: Number(d.weekday) },
        scope: d.scope,
        keep: Number(d.keep),
        dest_dir: d.folder,
    }
}

const save = (s) => act('save' + s.id, async () => {
    await api.remoteConfigure(s.id, optionsOf(s.id))
    delete drafts[s.id]
}, 'Saved.')

const backupNow = (s) => act('now' + s.id, () => api.remoteBackupNow(s.id))
const reveal = (s) => act('reveal', () => api.remoteReveal(s.id))

async function chooseFolder(s) {
    const f = await api.pickFolder().catch((e) => emit('note', 'bad', String(e)))
    if (f) drafts[s.id].folder = f
}

const remove = (s) => act('remove', async () => {
    await api.remoteRemove(s.id, deleteFiles.value)
    delete drafts[s.id]
    confirmRemove.value = ''
    deleteFiles.value = false
}, deleteFiles.value ? 'Removed, and the backups kept here were deleted.' : 'Removed. The backups already kept here are still in their folder.')

function status(s) {
    if (s.state.running) return { cls: 'busy', text: 'Backing up…' }
    if (s.state.needs_pairing) return { cls: 'bad', text: 'Needs pairing again' }
    if (s.schedule.frequency === 'off') return { cls: 'off', text: 'No schedule' }
    if (s.state.last_error) return { cls: 'warn', text: 'Last backup failed' }
    return { cls: 'ok', text: 'Scheduled' }
}

function scheduleText(s) {
    const { frequency, hour, minute, weekday } = s.schedule
    if (frequency === 'hourly') return `every hour at :${pad(minute)}`
    if (frequency === 'daily') return `every day at ${pad(hour)}:${pad(minute)}`
    if (frequency === 'weekly') return `every ${DAYS[weekday]} at ${pad(hour)}:${pad(minute)}`
    return 'not scheduled'
}

const nextText = (s) => {
    if (s.state.needs_pairing || s.schedule.frequency === 'off' || !s.next_run_at) return ''
    return s.next_run_at * 1000 <= Date.now() + 60000 ? 'Next backup: shortly' : `Next backup: ${when(s.next_run_at)}`
}

const insecure = (s) => s.url.startsWith('http://')
const empty = computed(() => servers.value && servers.value.length === 0)
</script>

<template>
    <section class="card">
        <h2>Remote Backups</h2>
        <p class="muted small">
            Keep copies of another Relief Inventory server's backups on this computer, made automatically on a schedule. The server
            has to trust this computer first (a one-time pairing); after that it can only make and hand over backups, nothing else.
        </p>
        <p v-if="loadError && !servers" class="banner bad" role="alert">{{ loadError }} <button class="quiet" @click="load">Try again</button></p>
    </section>


    <section v-for="s in servers" :key="s.id" class="card">
        <div class="who">
            <div>
                <h2>{{ s.name }} <span v-if="s.kind === 'instance'" class="pill" title="Backs up every warehouse on that server, not just one">Whole instance</span></h2>
                <div class="muted small url">{{ s.url }}</div>
            </div>
            <span class="pill" :class="status(s).cls">{{ status(s).text }}</span>
        </div>
        <p v-if="insecure(s)" class="muted small">This address isn't https. Fine on your own network; backups hold personal data, so don't use it across the internet.</p>

        <template v-if="s.state.running">
            <div class="bar" :class="{ indeterminate: s.state.running.fraction == null }"><div :style="s.state.running.fraction != null ? { width: Math.round(s.state.running.fraction * 100) + '%' } : {}" /></div>
            <p class="small"><strong>{{ s.state.running.step }}</strong> <span class="muted">{{ s.state.running.detail }}</span></p>
        </template>
        <p v-if="s.state.needs_pairing" class="banner bad" role="alert">
            The server no longer recognises this computer, so nothing is backed up. Ask an administrator for a new pairing code and pair again with the same address — your settings and copies are kept.
        </p>
        <p v-else-if="s.state.last_error && !s.state.running" class="banner bad" role="alert">{{ s.state.last_error }}</p>

        <dl class="facts small">
            <div><dt>Schedule</dt><dd>{{ scheduleText(s) }}<span v-if="nextText(s)" class="muted"> · {{ nextText(s) }}</span></dd></div>
            <div><dt>Last backup</dt><dd>{{ when(s.state.last_success_at) }}</dd></div>
            <div><dt>Kept here</dt><dd>{{ s.files.length }} {{ s.files.length === 1 ? 'copy' : 'copies' }} · {{ mb(s.total_bytes) }}</dd></div>
        </dl>
        <div class="actions">
            <button class="primary" @click="backupNow(s)" :disabled="!!busy || !!s.state.running || s.state.needs_pairing">Backup now</button>
            <button @click="reveal(s)" :disabled="!!busy">Show the folder</button>
        </div>

        <ul v-if="s.files.length" class="rows">
            <li v-for="f in s.files.slice(0, 6)" :key="f.name">
                <div class="grow"><div class="name small">{{ f.name }}</div><div class="muted small">{{ when(f.modified) }} · {{ mb(f.size) }}</div></div>
            </li>
            <li v-if="s.files.length > 6" class="muted small">and {{ s.files.length - 6 }} older. Use “Show the folder” to see them all.</li>
        </ul>

        <details v-if="drafts[s.id]">
            <summary>Schedule and storage</summary>
            <form @submit.prevent="save(s)">
                <label>Name
                    <input v-model="drafts[s.id].name" autocomplete="off" required />
                </label>
                <div class="grid2">
                    <label>Back up
                        <select v-model="drafts[s.id].frequency">
                            <option value="off">Never (only when I press Backup now)</option>
                            <option value="hourly">Every hour</option>
                            <option value="daily">Every day</option>
                            <option value="weekly">Every week</option>
                        </select>
                    </label>
                    <label v-if="drafts[s.id].frequency !== 'off'">{{ drafts[s.id].frequency === 'hourly' ? 'Minutes past the hour' : 'At' }}
                        <input v-if="drafts[s.id].frequency === 'hourly'" type="number" min="0" max="59" :value="Number(drafts[s.id].time.split(':')[1])" @input="drafts[s.id].time = `00:${pad($event.target.value || 0)}`" />
                        <input v-else type="time" v-model="drafts[s.id].time" required />
                    </label>
                    <label v-if="drafts[s.id].frequency === 'weekly'">On
                        <select v-model="drafts[s.id].weekday"><option v-for="(d, i) in DAYS" :key="d" :value="i">{{ d }}</option></select>
                    </label>
                </div>
                <div class="grid2">
                    <label>What to copy
                        <select v-model="drafts[s.id].scope">
                            <option value="database">Database only (small and quick)</option>
                            <option value="full">Database and uploaded files (photos, documents)</option>
                        </select>
                    </label>
                    <label>Copies to keep
                        <input type="number" min="1" max="999" v-model.number="drafts[s.id].keep" required />
                    </label>
                </div>
                <label>Keep them in
                    <span class="pick">
                        <input v-model="drafts[s.id].folder" :placeholder="s.folder" autocomplete="off" spellcheck="false" />
                        <button type="button" @click="chooseFolder(s)">Choose…</button>
                    </span>
                </label>
                <p class="muted small">Leave empty to use the launcher's own folder. A drive that is unplugged when a backup is due makes that backup fail and retry later. Older copies beyond the number to keep are deleted after each successful backup.</p>
                <div class="actions"><button class="primary" :disabled="!!busy">Save</button></div>
            </form>
            <p class="muted small">
                The server presents this key: <code>{{ s.fingerprint }}</code>. Backups only run while RI Launcher is running (it lives in the tray; turn on
                “start when I sign in” on the Local RI Server tab). A backup is made as soon as a schedule is turned on, then at each scheduled time; a computer
                that was off at that time catches up when it starts.
            </p>
        </details>

        <div class="actions end">
            <button class="danger" @click="confirmRemove = s.id" :disabled="!!busy || !!s.state.running">Remove…</button>
        </div>
        <div v-if="confirmRemove === s.id" class="banner bad" role="alertdialog">
            <p><strong>Stop backing up {{ s.name }}?</strong></p>
            <p class="small">This only removes it from this computer. The server keeps its record of the pairing until an administrator removes it in the Trust Console.</p>
            <label class="check-row"><input type="checkbox" v-model="deleteFiles" /> Also delete the {{ s.files.length }} {{ s.files.length === 1 ? 'copy' : 'copies' }} kept here</label>
            <div class="actions">
                <button class="danger" @click="remove(s)">{{ deleteFiles ? 'Remove and delete copies' : 'Remove' }}</button>
                <button @click="confirmRemove = ''; deleteFiles = false">Cancel</button>
            </div>
        </div>
    </section>

    <section v-if="servers" class="card">
        <h2>{{ empty ? 'Pair with a server' : 'Pair with another server' }}</h2>
        <ol v-if="empty" class="small muted steps">
            <li>
                For <strong>one warehouse</strong>: on the server, an administrator opens <strong>Setup → Trust Console</strong>, chooses <strong>Backup client (RI Launcher)</strong>
                under "Pair something new" and creates a code. For <strong>the whole instance</strong> (every warehouse): an instance administrator opens
                <strong>Instance Admin → Full Instance Backups</strong> and chooses <strong>Pair a computer</strong>.
            </li>
            <li>Type the server's address and that code here, and say which kind it is. The code works once and expires after 15 minutes.</li>
        </ol>
        <form @submit.prevent="doPair">
            <div class="grid2">
                <label>Server address
                    <input v-model="pair.address" placeholder="ri.example.org" autocomplete="off" spellcheck="false" required />
                </label>
                <label>Pairing code
                    <input v-model="pair.code" placeholder="ABCDE-FGHJK" autocomplete="off" spellcheck="false" required />
                </label>
            </div>
            <div class="kind" role="group" aria-label="The code is for">
                <span class="kind-label">The code is for</span>
                <div class="seg">
                    <button type="button" :class="{ on: pair.kind === 'warehouse' }" :aria-pressed="pair.kind === 'warehouse'" @click="pair.kind = 'warehouse'">One warehouse</button>
                    <button type="button" :class="{ on: pair.kind === 'instance' }" :aria-pressed="pair.kind === 'instance'" @click="pair.kind = 'instance'">The whole instance</button>
                </div>
                <span class="muted small">{{ pair.kind === 'instance' ? 'Every warehouse, with a code from Instance Admin.' : 'A single warehouse, with a code from its Trust Console.' }}</span>
            </div>
            <label>Name (optional)
                <input v-model="pair.name" placeholder="The server's own name is used if you leave this empty" autocomplete="off" />
            </label>
            <div class="actions"><button class="primary" :disabled="!!busy || !pair.address || !pair.code">{{ busy === 'pair' ? 'Pairing…' : 'Pair' }}</button></div>
        </form>
    </section>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 16px; }
h2 { font-size: 15px; margin: 0 0 6px; }
.muted { color: var(--muted); }
.small { font-size: 13px; }
p { margin: 0 0 8px; }
.who { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.url { overflow-wrap: anywhere; }
.steps { margin: 0 0 8px; padding-left: 20px; }
.facts { margin: 8px 0; display: grid; gap: 4px; }
.facts > div { display: flex; gap: 10px; }
.facts dt { width: 90px; color: var(--muted); flex: none; }
.facts dd { margin: 0; min-width: 0; }
.rows { list-style: none; margin: 10px 0 0; padding: 0; }
.rows li { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-top: 1px solid var(--line); }
.grow { flex: 1; min-width: 0; }
.name { font-weight: 600; word-break: break-all; }
.pill { font-size: 12px; font-weight: 600; padding: 3px 9px; border-radius: 999px; white-space: nowrap; background: var(--off-bg); color: var(--off-text); }
.pill.ok { background: var(--ok-bg); color: var(--ok-text); }
.pill.busy { background: var(--busy-bg); color: var(--busy-text); }
.pill.warn { background: var(--warn-bg); color: var(--warn-text); }
.pill.bad { background: var(--bad-bg); color: var(--bad-text); }
.banner { border-radius: 8px; padding: 8px 12px; margin: 10px 0; font-size: 14px; }
.banner.bad { background: var(--bad-bg); color: var(--bad-text); }
.banner p { margin: 0 0 8px; }
form { display: flex; flex-direction: column; gap: 12px; margin-top: 12px; }
label { display: flex; flex-direction: column; gap: 4px; font-weight: 600; font-size: 14px; }
label.check-row { flex-direction: row; align-items: center; gap: 8px; font-weight: 400; margin: 6px 0; }
input:not([type=checkbox]), select { padding: 9px 11px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); font: inherit; font-weight: 400; color: inherit; }
.pick { display: flex; gap: 8px; }
.pick input { flex: 1; min-width: 0; }
.actions.end { justify-content: flex-end; }
button { padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line); background: var(--card); cursor: pointer; }
button:disabled { opacity: .5; cursor: not-allowed; }
button.primary { background: var(--accent); color: var(--accent-text); border-color: var(--accent); font-weight: 600; }
button.quiet { padding: 6px 12px; font-size: 13px; }
button.danger { color: var(--bad-text); border-color: var(--bad-text); background: transparent; }
button:focus-visible, input:focus-visible, select:focus-visible, summary:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
code { font-size: 13px; }
</style>
