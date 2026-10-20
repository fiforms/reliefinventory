<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import QRCode from 'qrcode'
import { request } from '@core/api.js'
import { grantReloadPass } from '@core/pinLock.js'

// Settings > Local server: run a complete Relief Inventory server on this device, for a site with no
// Internet (rstation/LOCAL_SERVER.md). The page only asks the backend (backend/local_server.py) to
// start or stop one of a few fixed units and shows what the root supervisor reports; starting and
// stopping take minutes, so every action returns at once and this polls. The browser that shows it
// is the device's own kiosk, so "Open" is a full-page navigation — the escape hatch brings it back.
const REFRESH_MS = 3000

const { t } = useI18n()
const info = ref(null)
const loadError = ref(null)
const busyAction = ref(null)
const actionError = ref(null)
const confirmingReset = ref(false)
let timer = null

async function load() {
  try {
    info.value = await request('/api/local/local-server')
    loadError.value = null
  } catch (err) {
    loadError.value = err.message
  }
}

async function act(action, body) {
  busyAction.value = action
  actionError.value = null
  try {
    info.value = await request(`/api/local/local-server/${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body ?? {}),
    })
  } catch (err) {
    actionError.value = err.message
  } finally {
    busyAction.value = null
    confirmingReset.value = false
  }
}

// A QR code for one of the addresses, so a phone can scan it instead of someone typing it. White square with a
// quiet zone regardless of the theme, or dark-mode scanners can't read it.
const qrUrl = ref(null)
const qrImage = ref(null)
async function showQr(url) {
  if (qrUrl.value === url) {
    qrUrl.value = qrImage.value = null
    return
  }
  qrUrl.value = url
  qrImage.value = await QRCode.toDataURL(url, { width: 320, margin: 3, errorCorrectionLevel: 'M', color: { dark: '#000000', light: '#ffffff' } })
}

// An external site as this device's server (the same address the wizard's Server step takes).
const siteInput = ref('')
const siteBusy = ref(false)
const siteError = ref(null)
const siteSaved = ref(false)
async function saveSite(verify) {
  siteBusy.value = true
  siteError.value = null
  siteSaved.value = false
  try {
    info.value = await request('/api/local/local-server/site', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ url: siteInput.value, verify }),
    })
    siteSaved.value = true
    siteInput.value = ''
  } catch (err) {
    siteError.value = err.message
  } finally {
    siteBusy.value = false
  }
}

async function setHome(enabled) {
  await act('home', { enabled })
}

function openServer() {
  grantReloadPass() // coming back through the escape hatch reloads Settings; don't ask for the PIN again
  window.location.href = info.value.local_url
}

const phase = computed(() => info.value?.phase ?? 'stopped')
const canStart = computed(() => ['stopped', 'error'].includes(phase.value))
const canStop = computed(() => ['starting', 'loading-images', 'running', 'degraded'].includes(phase.value))
const working = computed(() => !!busyAction.value || ['stopping', 'resetting'].includes(phase.value))
const pillClass = computed(() => ({ running: 'ok', error: 'warn', degraded: 'warn' })[phase.value] ?? '')
const mb = (bytes) => Math.round(bytes / 1e6).toLocaleString()
const gb = (bytes) => (bytes / 1e9).toFixed(1)
const when = (epoch) => new Date(epoch * 1000).toLocaleString()

onMounted(() => {
  load()
  timer = setInterval(load, REFRESH_MS)
})
onUnmounted(() => clearInterval(timer))
</script>

<template>
  <div class="settings-page">
    <section class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.localServer.title') }}</h2></div>
      <p class="hint">{{ t('settings.localServer.intro') }}</p>

      <p v-if="loadError && !info" class="pill warn note">{{ loadError }}</p>
      <p v-else-if="!info" class="hint">{{ t('settings.localServer.loading') }}</p>
      <p v-else-if="!info.available" class="pill warn note">{{ t('settings.localServer.notIncluded') }}</p>

      <template v-else>
        <div class="head">
          <span class="pill" :class="pillClass">{{ t(`settings.localServer.phase.${phase}`) }}</span>
          <span v-if="info.message && phase !== 'running'" class="hint">{{ info.message }}</span>
          <span v-if="info.app?.app_commit" class="hint">{{ t('settings.localServer.version', { commit: info.app.app_commit.slice(0, 7) }) }}</span>
        </div>

        <p v-if="info.low_space && canStart" class="pill warn note">
          {{ t('settings.localServer.lowSpace', { free: gb(info.free_bytes), needed: gb(info.needed_bytes) }) }}
        </p>
        <p v-if="info.low_memory && canStart" class="pill warn note">
          {{ t('settings.localServer.lowMemory', { ram: gb(info.ram_bytes) }) }}
        </p>
        <p v-if="phase === 'loading-images'" class="hint note">{{ t('settings.localServer.firstStart') }}</p>

        <div class="actions">
          <button v-if="canStart" class="tile action primary" :disabled="working" @click="act('enable')">
            {{ t('settings.localServer.start') }}
          </button>
          <button v-if="phase === 'running'" class="tile action primary" @click="openServer">
            {{ t('settings.localServer.open') }}
          </button>
          <button v-if="canStop" class="tile action" :disabled="working" @click="act('disable')">
            {{ t('settings.localServer.stop') }}
          </button>
        </div>
        <p v-if="canStart" class="hint note">{{ t('settings.localServer.startHint') }}</p>
        <p v-if="canStop" class="hint note">{{ t('settings.localServer.stopHint') }}</p>
        <p v-if="actionError" class="pill warn note">{{ actionError }}</p>
      </template>
    </section>

    <section v-if="info?.running && info.setup_pending" class="tile panel setup">
      <div class="panel-title"><h2>{{ t('settings.localServer.setupTitle') }}</h2></div>
      <p class="hint">{{ t('settings.localServer.setupHint') }}</p>
      <p v-if="info.setup_code" class="code">{{ info.setup_code }}</p>
      <ol class="steps">
        <li>{{ t('settings.localServer.setupStep1') }}</li>
        <li>{{ t('settings.localServer.setupStep2') }}</li>
        <li>{{ t('settings.localServer.setupStep3') }}</li>
      </ol>
      <p class="hint note">{{ t('settings.localServer.setupReturn') }}</p>
    </section>

    <section v-if="info?.running" class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.localServer.connectTitle') }}</h2></div>
      <p class="hint">{{ t('settings.localServer.connectHint') }}</p>
      <ul class="urls">
        <li>
          <span class="hint">{{ t('settings.localServer.byName') }}</span>
          <strong>{{ info.hostname_url }}</strong>
          <button class="tile action small" @click="showQr(info.hostname_url)">{{ qrUrl === info.hostname_url ? t('settings.localServer.hideQr') : t('settings.localServer.showQr') }}</button>
        </li>
        <li v-for="url in info.addresses" :key="url">
          <span class="hint">{{ t('settings.localServer.byAddress') }}</span>
          <strong>{{ url }}</strong>
          <button class="tile action small" @click="showQr(url)">{{ qrUrl === url ? t('settings.localServer.hideQr') : t('settings.localServer.showQr') }}</button>
        </li>
        <li v-if="!info.addresses.length" class="hint">{{ t('settings.localServer.noNetwork') }}</li>
      </ul>
      <p v-if="info.hostname_change_pending" class="pill warn note">{{ t('settings.localServer.renamePending') }}</p>
      <div v-if="qrImage" class="qr">
        <img :src="qrImage" :alt="qrUrl" width="320" height="320">
        <p class="hint">{{ t('settings.localServer.qrHint', { url: qrUrl }) }}</p>
      </div>
      <p class="hint note">{{ t('settings.localServer.nameTrouble') }}</p>

    </section>

    <section v-if="info?.available" class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.localServer.homeTitle') }}</h2></div>
      <p class="hint">{{ t('settings.localServer.homeHint') }}</p>
      <p class="current">
        <span class="hint">{{ t('settings.localServer.siteCurrent') }}</span>
        <strong>{{ info.site_url || t('settings.localServer.siteNone') }}</strong>
      </p>
      <p v-if="info.paired" class="pill warn note">{{ t('settings.localServer.homePaired') }}</p>

      <div class="actions">
        <span v-if="info.is_home" class="pill ok">{{ t('settings.localServer.homeOn') }}</span>
        <button v-if="!info.is_home" class="tile action" :disabled="working || info.paired" @click="setHome(true)">{{ t('settings.localServer.homeUse') }}</button>
        <button v-else class="tile action" :disabled="working || info.paired" @click="setHome(false)">{{ t('settings.localServer.homeStop') }}</button>
      </div>

      <h3>{{ t('settings.localServer.siteTitle') }}</h3>
      <p class="hint">{{ t('settings.localServer.siteHint') }}</p>
      <form class="site-form" @submit.prevent="saveSite(true)">
        <input v-model="siteInput" type="url" inputmode="url" placeholder="https://" autocomplete="off" autocapitalize="off" spellcheck="false" :disabled="siteBusy || info.paired">
        <div class="actions">
          <button type="submit" class="tile action" :disabled="siteBusy || info.paired || !siteInput.trim()">{{ t('settings.localServer.siteSave') }}</button>
          <button v-if="siteError" type="button" class="tile action" :disabled="siteBusy" @click="saveSite(false)">{{ t('settings.localServer.siteAnyway') }}</button>
        </div>
      </form>
      <p v-if="siteError" class="pill warn note">{{ siteError }}</p>
      <p v-if="siteSaved" class="pill ok note">{{ t('settings.localServer.siteSaved') }}</p>
    </section>

    <section v-if="info?.available" class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.localServer.dataTitle') }}</h2></div>
      <p class="hint">{{ t('settings.localServer.dataHint', { dir: info.backup_dir }) }}</p>
      <ul v-if="info.backups.length" class="backups">
        <li v-for="b in info.backups" :key="b.name">
          <strong>{{ b.name }}</strong> <span class="hint">{{ mb(b.bytes) }} MB · {{ when(b.modified) }}</span>
        </li>
      </ul>
      <p v-else class="hint">{{ t('settings.localServer.noBackups') }}</p>
      <p class="hint note">{{ t('settings.localServer.powerHint') }}</p>

      <h3>{{ t('settings.localServer.eraseTitle') }}</h3>
      <p class="hint">{{ t('settings.localServer.eraseHint') }}</p>
      <div class="actions">
        <template v-if="confirmingReset">
          <span class="hint confirm">{{ t('settings.localServer.eraseConfirm') }}</span>
          <button class="tile action danger" :disabled="working" @click="act('reset', { confirm: true })">
            {{ t('settings.localServer.eraseYes') }}
          </button>
          <button class="tile action" @click="confirmingReset = false">{{ t('settings.localServer.eraseNo') }}</button>
        </template>
        <button v-else class="tile action danger" :disabled="working" @click="confirmingReset = true">
          {{ t('settings.localServer.erase') }}
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.hint {
  color: var(--text-dim);
  margin: 0 0 0.9rem;
}
.note {
  margin: 0.6rem 0 0;
}
.head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.2rem 0.8rem;
  margin: 0 0 0.9rem;
}
.head .hint {
  margin: 0;
}
.actions {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}
.confirm {
  margin: 0;
}
.urls,
.backups {
  list-style: none;
  margin: 0 0 1rem;
  padding: 0;
  display: grid;
  gap: 0.4rem;
  overflow-wrap: anywhere;
}
.urls li {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.3rem 0.8rem;
}
.urls strong {
  font-size: 1.15rem;
}
.current {
  margin: 0 0 0.9rem;
  overflow-wrap: anywhere;
}
.site-form {
  display: grid;
  gap: 0.8rem;
  max-width: 28rem;
}
.qr {
  margin: 0 0 1rem;
}
.qr img {
  display: block;
  border-radius: 0.5rem;
  max-width: 100%;
  height: auto;
}
.action.small {
  padding: 0.3rem 0.9rem;
  font-size: 0.9rem;
}
.setup {
  border: 3px solid var(--accent, #4a90d9);
}
.code {
  font-family: monospace;
  font-size: 3.2rem;
  font-weight: 700;
  letter-spacing: 0.2em;
  text-align: center;
  margin: 0.4rem 0 0.9rem;
  user-select: all;
}
.steps {
  margin: 0;
  padding-left: 1.5rem;
  font-size: 1.1rem;
}
.steps li {
  margin: 0.3rem 0;
}
h3 {
  margin: 1.2rem 0 0.4rem;
}
.action {
  padding: 0.75rem 1.5rem;
  font-size: 1rem;
}
.action.danger {
  border-color: var(--warn, #c0392b);
}
.action.primary {
  border-color: var(--accent);
  background: var(--accent);
  color: #fff;
  font-weight: 600;
}
</style>
