<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { request } from '@core/api.js'

// The device's main screen: a launcher, not a page. Chromium always starts on
// this local app (the image has no KIOSK_URL), so a fresh device gets the
// first-run wizard (the router sends /kiosk there until setup is done); once it
// is, this hands the browser to the Relief Inventory site chosen in the wizard.
// The path is the site's sign-in page for now; the heartbeat's kiosk_path can
// replace it later (IMPLEMENTATION.md §2).
const SITE_PATH = '/login'
const RETRY_MS = 2000

const { t } = useI18n()
const noServer = ref(false)
let timer = null

async function launch() {
  try {
    const status = await request('/api/local/status')
    if (status.server_url) {
      window.location.replace(status.server_url + SITE_PATH)
      return
    }
    noServer.value = true
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
    <p v-else>{{ t('launcher.opening') }}</p>
  </main>
</template>

<style scoped>
.launcher {
  padding: 3rem;
}
</style>
