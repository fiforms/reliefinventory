<script setup>
import { onMounted, ref } from 'vue'
import * as api from './api'

const props = defineProps({ platform: { type: String, default: '' } })
defineEmits(['close'])

const REPO = 'https://github.com/fiforms/reliefinventory'
const RELEASES = `${REPO}/releases`
const GPL = 'https://www.gnu.org/licenses/gpl-3.0.html'
const SUMATRA = 'https://www.sumatrapdfreader.org'
const SUMATRA_SOURCE = 'https://github.com/sumatrapdfreader/sumatrapdf'

const version = ref('')
onMounted(async () => {
    try { version.value = await api.appVersion() } catch { version.value = 'unknown' }
})
</script>

<template>
    <header class="top">
        <svg class="mark" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v7H6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
        </svg>
        <h1>About RI Print</h1>
        <button class="quiet" @click="$emit('close')">Back</button>
    </header>

    <section class="card">
        <h2>RI Print <span class="muted">version {{ version }}</span></h2>
        <p>
            RI Print is a print client that makes the printers attached to this computer available to the
            Relief Inventory ecosystem, so staff can print labels and reports from the website to them.
        </p>
        <p>
            Project home:
            <a href="#" @click.prevent="api.openExternal(REPO)">github.com/fiforms/reliefinventory</a>
        </p>
    </section>

    <section class="card">
        <h2>Updates</h2>
        <p>
            The latest releases are available from the Relief Inventory
            <a href="#" @click.prevent="api.openExternal(RELEASES)">release page</a>.
            You are running version {{ version }}.
        </p>
    </section>

    <section class="card">
        <h2>License</h2>
        <p>
            RI Print is free software, part of Relief Inventory, released under the
            <a href="#" @click.prevent="api.openExternal(GPL)">GNU General Public License, version 3</a>
            (or, at your option, any later version). You may use, study, share and modify it under those terms.
            It comes with no warranty. The full text and source code are in the project repository.
        </p>
    </section>

    <section v-if="props.platform === 'windows'" class="card">
        <h2>SumatraPDF</h2>
        <p>
            On Windows, RI Print prints through
            <a href="#" @click.prevent="api.openExternal(SUMATRA)">SumatraPDF</a>, a separate program that is
            installed alongside it. SumatraPDF is not part of RI Print and has its own license: it is free
            software under the
            <a href="#" @click.prevent="api.openExternal(GPL)">GNU General Public License, version 3</a>,
            copyright its authors. Its license text is installed with it, and its source code is available from
            <a href="#" @click.prevent="api.openExternal(SUMATRA_SOURCE)">github.com/sumatrapdfreader/sumatrapdf</a>.
        </p>
    </section>
</template>

<style scoped>
.top { display: flex; align-items: center; gap: 10px; }
.top h1 { font-size: 20px; margin: 0; flex: 1; }
.mark { width: 28px; height: 28px; color: var(--accent); }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 10px; padding: 16px; }
h2 { font-size: 15px; margin: 0 0 10px; }
h2 .muted { font-weight: 400; margin-left: 6px; }
.muted { color: var(--muted); }
p { margin: 0 0 8px; }
p:last-child { margin-bottom: 0; }
a { color: var(--accent); }
button.quiet { padding: 6px 12px; font-size: 13px; }
</style>
