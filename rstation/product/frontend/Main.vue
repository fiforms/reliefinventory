<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { request } from '@core/api.js'

// The device's main screen: a launcher, not a page. Chromium always starts on
// this local app (the image has no KIOSK_URL), so a fresh device gets the
// first-run wizard (the router sends /kiosk there until setup is done); once it
// is, this hands the browser to the Relief Inventory site chosen in the wizard.
// Where it lands depends on the Function an admin chose for this device: a paired device first
// trades the backend's token for a single-use ticket (POST /api/local/browser-ticket) and opens
// {server}/device-session/{ticket}, which gives the browser a session for that Function's page
// (a stats display, the volunteer kiosk, …) with nobody logged in. A device with no page for its
// Function, an unpaired one, or any failure along the way gets the site's sign-in page, as before.
//
// If the escape hatch had to restart the browser (platform/docs/ESCAPE_HATCH.md),
// this opens Settings once instead of going straight back to the site.
const SITE_PATH = '/login'
const RETRY_MS = 2000

const { t } = useI18n()
const router = useRouter()
const noServer = ref(false)
const waitingLocal = ref(false)
let timer = null

// A server on this very device (Settings > Local server, http://localhost:8080) takes a minute or two
// to come up at boot, and Chromium shows an error page rather than retrying; wait for it here instead.
// no-cors: the answer can't be read from another origin, but any answer at all proves it is listening.
const isLocalServer = (url) => /^https?:\/\/(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(\/|$)/i.test(url)
async function localServerIsUp(url) {
  try {
    await fetch(new URL('/up', url), { mode: 'no-cors', cache: 'no-store', signal: AbortSignal.timeout(4000) })
    return true
  } catch {
    return false
  }
}

// The URL to hand the browser to. Never throws: the ticket is an optimisation over plain sign-in.
async function destination(serverUrl) {
  try {
    const { ticket } = await request('/api/local/browser-ticket', { method: 'POST' })
    if (ticket) return `${serverUrl}/device-session/${encodeURIComponent(ticket)}`
  } catch {
    // fall through to sign-in
  }
  return serverUrl + SITE_PATH
}

async function launch() {
  try {
    const status = await request('/api/local/status')
    if (status.setup_complete && (await request('/api/local/escape-hold/consume', { method: 'POST' })).held) {
      router.replace('/settings')
      return
    }
    if (status.server_url) {
      waitingLocal.value = isLocalServer(status.server_url) && !(await localServerIsUp(status.server_url))
      if (!waitingLocal.value) {
        window.location.replace(await destination(status.server_url))
        return
      }
    } else {
      noServer.value = true
    }
  } catch {
    // the backend is still starting; try again
  }
  timer = setTimeout(launch, RETRY_MS)
}

onMounted(launch)
onUnmounted(() => clearTimeout(timer))
</script>

<template>
  <main class="launcher">
    <h1>R Station</h1>
    <p v-if="noServer">{{ t('launcher.noServer') }} <router-link to="/settings">{{ t('launcher.settings') }}</router-link></p>
    <p v-else-if="waitingLocal">{{ t('launcher.waitingLocal') }} <router-link to="/settings/local-server">{{ t('launcher.localSettings') }}</router-link></p>
    <p v-else>{{ t('launcher.opening') }}</p>
  </main>
</template>

<style scoped>
.launcher {
  padding: 3rem;
}
</style>
