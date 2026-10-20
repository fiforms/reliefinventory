<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { request } from '@core/api.js'
import WizardNav from '@core/views/setup/WizardNav.vue'
import { nextPath, prevPath } from '@core/setupFlow.js'

// First-run wizard step: which Relief Inventory server this device belongs to.
// The boot yaml's server_url, if there is one, is only the starting value here;
// what is confirmed is saved on the device and never changed from it again (a
// factory reset starts over) — see platform/docs/SERVER_URL.md.
const router = useRouter()
const { t } = useI18n()

const status = ref(null)
const url = ref('')
const saving = ref(false)
const error = ref(null)

onMounted(async () => {
  try {
    status.value = await request('/api/local/status')
    url.value = status.value.server_url_confirmed ? status.value.server_url : status.value.server_url_default || ''
  } catch {
    // an empty field is fine: the save below reports a real failure
  }
})

async function save(verify) {
  saving.value = true
  error.value = null
  try {
    await request('/api/local/server-url', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ url: url.value, verify }),
    })
  } catch (err) {
    error.value = err.message
    return
  } finally {
    saving.value = false
  }
  router.push(nextPath('server'))
}

// An empty field can be skipped (no Internet and no site yet): the launcher then offers Settings, where
// Settings > Local server can make this device's own server its site, and Pairing can be done later.
const skipping = computed(() => !!status.value && !status.value.server_url_locked && !url.value.trim())
const next = () => (status.value?.server_url_locked || skipping.value ? router.push(nextPath('server')) : save(true))
</script>

<template>
  <div class="setup-card">
    <h1>{{ t('setup.server.title') }}</h1>

    <template v-if="status?.server_url_locked">
      <p class="intro">{{ t('setup.server.locked') }}</p>
      <p class="current">{{ status.server_url }}</p>
    </template>
    <template v-else>
      <p class="intro">{{ t('setup.server.intro') }}</p>
      <label class="field">
        <span class="field-label">{{ t('setup.server.label') }}</span>
        <input
          v-model="url"
          type="url"
          inputmode="url"
          autocomplete="off"
          autocapitalize="off"
          spellcheck="false"
          placeholder="https://"
          :disabled="saving"
          @keydown.enter.prevent="next"
        >
      </label>
      <p class="hint">{{ t('setup.server.hint') }}</p>
      <p class="hint">{{ t('setup.server.offlineHint') }}</p>
    </template>

    <p v-if="error" class="pill warn">{{ error }}</p>
    <!-- The server may simply be down right now; it can still be the right one. -->
    <button v-if="error && !status?.server_url_locked" type="button" class="tile anyway" :disabled="saving" @click="save(false)">
      {{ t('setup.server.useAnyway') }}
    </button>

    <WizardNav :back="prevPath('server')" :next-label="skipping ? t('setup.server.skip') : null" :next-disabled="saving || !status" autofocus-next @next="next" />
  </div>
</template>

<style scoped>
h1 { margin-top: 0; }
.intro { color: var(--text-dim); font-size: 1.1rem; }
.field { display: flex; flex-direction: column; gap: 0.5rem; }
.field-label { font-weight: 600; font-size: 1.1rem; }
.field input { width: 100%; }
.hint { margin: 0.75rem 0 0; color: var(--text-dim); }
.current { font-size: 1.2rem; font-weight: 600; overflow-wrap: anywhere; }
.pill { margin-top: 1rem; }
.anyway { margin-top: 0.75rem; padding: 0.6rem 1.2rem; }
</style>
