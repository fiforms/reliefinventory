<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import * as api from './api'

const emit = defineEmits(['note'])
const info = ref(null)
const loadError = ref('')
const dl = ref({ running: false, done: 0, total: 0, error: null, finished: false })
const busy = ref('')
let timer

const mb = (n) => `${Math.round(n / 1048576).toLocaleString()} MB`
const gb = (n) => `${(n / 1073741824).toFixed(2)} GB`
const released = computed(() => {
    const d = new Date(info.value?.manifest.released)
    return isNaN(d) ? '' : d.toLocaleDateString([], { dateStyle: 'medium' })
})
const fraction = computed(() => (dl.value.total ? Math.min(1, dl.value.done / dl.value.total) : null))
const checkFailed = computed(() => !info.value?.downloaded && !dl.value.running && /checksum/i.test(dl.value.error || ''))
const ready = computed(() => info.value?.downloaded && !dl.value.running)

async function load() {
    loadError.value = ''
    try {
        info.value = await api.imageInfo()
    } catch (e) {
        loadError.value = String(e)
    }
}

async function poll() {
    try {
        const was = dl.value.running
        dl.value = await api.imageDownloadStatus()
        if (was && !dl.value.running) await load() // finished, failed or cancelled: re-read what is on disk
    } catch { /* keep the last status */ }
    timer = setTimeout(poll, dl.value.running ? 700 : 3000)
}

onMounted(async () => {
    await load()
    poll()
})
onBeforeUnmount(() => clearTimeout(timer))

async function act(label, fn) {
    busy.value = label
    try {
        await fn()
    } catch (e) {
        emit('note', 'bad', String(e))
    } finally {
        busy.value = ''
    }
}

const start = () => act('download', async () => {
    await api.imageDownloadStart()
    clearTimeout(timer)
    poll()
})
const cancel = () => api.imageDownloadCancel()
const write = () => act('write', async () => {
    await api.imageWrite()
    emit('note', 'ok', 'Raspberry Pi Imager is opening with the R Station image chosen. In it, pick your SD card and press Write.')
})
</script>

<template>
    <section class="card">
        <h2>R Station SD card image</h2>
        <p class="muted small">
            The R Station is the Raspberry Pi device that shows reports and prints labels for Relief Inventory. This puts its software
            on an SD card in three steps: download the image, check it, then write it with Raspberry Pi Imager.
        </p>

        <p v-if="loadError && !info" class="banner bad" role="alert">
            {{ loadError }}
            <button class="quiet" @click="load">Try again</button>
        </p>

        <template v-if="info">
            <p v-if="info.offline" class="banner bad" role="status">Couldn't reach the image list, so this is the last version seen. A new one may be available.</p>
            <p v-if="info.needs_newer_launcher" class="banner bad" role="alert">This image needs a newer RI Launcher than you have. Update the launcher first.</p>

            <div class="meta">
                <div><strong>Version {{ info.manifest.version }}</strong><span v-if="released" class="muted"> · {{ released }}</span></div>
                <div class="muted small">{{ info.manifest.filename }} · {{ gb(info.manifest.size) }}</div>
                <p v-if="info.manifest.notes" class="small notes">{{ info.manifest.notes }}</p>
            </div>

            <ol class="steps">
                <li :class="{ done: info.downloaded }">
                    <div class="head"><span class="n">1</span><strong>Download</strong></div>
                    <template v-if="dl.running">
                        <div class="bar" :class="{ indeterminate: fraction == null }"><div :style="fraction != null ? { width: Math.round(fraction * 100) + '%' } : {}" /></div>
                        <p class="muted small">{{ mb(dl.done) }} of {{ mb(dl.total) }}. You can switch tabs; it keeps going while the launcher is open.</p>
                        <div class="actions"><button @click="cancel">Pause</button></div>
                    </template>
                    <template v-else-if="info.downloaded">
                        <p class="muted small">Downloaded and checked against the published checksum. ✓</p>
                        <div class="actions"><button class="quiet" @click="act('reveal', api.imageReveal)">Show the file</button></div>
                    </template>
                    <template v-else>
                        <p v-if="dl.error" class="banner bad" role="alert">{{ dl.error }}</p>
                        <p class="muted small">A download of {{ gb(info.manifest.size) }}. If it's interrupted or paused, pressing Download again carries on where it stopped.</p>
                        <div class="actions"><button class="primary" @click="start" :disabled="!!busy || info.needs_newer_launcher">Download image</button></div>
                    </template>
                </li>

                <li :class="{ done: info.downloaded && !checkFailed, failed: checkFailed }">
                    <div class="head">
                        <span class="n">2</span><strong>Check</strong>
                        <span v-if="checkFailed" class="result fail">Failed</span>
                        <span v-else-if="info.downloaded" class="result pass">Pass</span>
                        <span v-else class="muted small">Not checked yet</span>
                    </div>
                </li>

                <li :class="{ off: !ready }">
                    <div class="head"><span class="n">3</span><strong>Write to the SD card</strong></div>
                    <template v-if="info.imager_found">
                        <p class="muted small">Opens Raspberry Pi Imager with this image already chosen. There, pick <strong>your SD card</strong> as the storage and press Write.</p>
                        <div class="actions"><button class="primary" @click="write" :disabled="!ready || !!busy">Open Raspberry Pi Imager</button></div>
                    </template>
                    <template v-else>
                        <p class="muted small">Raspberry Pi Imager is a free tool that writes images to SD cards and takes care not to touch your other drives. Install it, then come back here.</p>
                        <div class="actions">
                            <button class="primary" @click="act('page', () => api.openToolPage('imager'))">Get Raspberry Pi Imager</button>
                            <button @click="load" title="Look for it again after installing">I've installed it</button>
                        </div>
                    </template>
                    <p class="muted small foot">
                        Imager will say the image is custom and ask about settings; no changes are needed (the R Station asks for its Wi-Fi and server on its own screen).
                        <template v-if="!info.imager_found">Prefer balenaEtcher? <a href="#" @click.prevent="act('page', () => api.openToolPage('etcher'))">Get it here</a>, then choose "Flash from file" and the file from step 1 (use <em>Show the file</em> to find it).</template>
                    </p>
                </li>
            </ol>
        </template>
    </section>
</template>

<style scoped>
.card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 16px; }
h2 { font-size: 15px; margin: 0 0 10px; }
.muted { color: var(--muted); }
.small { font-size: 13px; }
p { margin: 0 0 8px; }
.banner { border-radius: 8px; padding: 8px 12px; margin: 10px 0; font-size: 14px; }
.banner.bad { background: var(--bad-bg); color: var(--bad-text); }
.meta { margin: 12px 0; padding: 10px 12px; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; }
.notes { margin: 6px 0 0; white-space: pre-wrap; }
.steps { list-style: none; margin: 0; padding: 0; }
.steps li { padding: 12px 0; border-top: 1px solid var(--line); }
.steps li.off { opacity: .6; }
.head { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; }
.n { width: 22px; height: 22px; border-radius: 50%; background: var(--off-bg); color: var(--off-text); font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; }
.steps li.done .n { background: var(--ok-bg); color: var(--ok-text); }
.steps li.failed .n { background: var(--bad-bg); color: var(--bad-text); }
.result { font-size: 12px; font-weight: 700; padding: 2px 10px; border-radius: 999px; }
.result.pass { background: var(--ok-bg); color: var(--ok-text); }
.result.fail { background: var(--bad-bg); color: var(--bad-text); }
.foot { margin: 10px 0 0; }
a { color: var(--accent); }
button { padding: 9px 16px; border-radius: 8px; border: 1px solid var(--line); background: var(--card); cursor: pointer; }
button:disabled { opacity: .5; cursor: not-allowed; }
button.primary { background: var(--accent); color: var(--accent-text); border-color: var(--accent); font-weight: 600; }
button.quiet { padding: 6px 12px; font-size: 13px; }
button:focus-visible, a:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
</style>
