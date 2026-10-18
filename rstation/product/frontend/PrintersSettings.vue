<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { request } from '@core/api.js'
import { grantReloadPass } from '@core/pinLock.js'

// Settings > Printers. Shows the printers CUPS has and prints a test page; adding a
// printer, drivers and media size are done in CUPS's own web interface, which this
// opens (see PRINT_SERVER.md, "Printer setup on the device"). The kiosk is a single
// Chromium tab, so that is a full-page navigation — like captivePortal.js — and the
// remote's Back key comes back through browser history.
const CUPS_URL = 'http://localhost:631/admin'
const REFRESH_MS = 5000

const { t } = useI18n()
const printers = ref(null)
const cupsError = ref(null)
const testing = ref(null) // queue name with a test page in flight
const results = ref({}) // queue name -> { ok, text }
let timer = null

async function load() {
  try {
    const body = await request('/api/local/printers')
    printers.value = body.printers
    cupsError.value = body.error
  } catch (err) {
    printers.value = printers.value ?? []
    cupsError.value = err.message
  }
}

async function printTestPage(name) {
  testing.value = name
  delete results.value[name]
  try {
    await request(`/api/local/printers/${encodeURIComponent(name)}/test-page`, { method: 'POST' })
    results.value[name] = { ok: true, text: t('settings.printers.testSent') }
  } catch (err) {
    results.value[name] = { ok: false, text: err.message }
  } finally {
    testing.value = null
    load()
  }
}

// The CUPS login is the device's local admin account. Its password is set here: the
// first time needs no old one (a factory reset puts a device back to that state); after
// that the current password is required. The root helper enforces this, not this page.
const MIN_PASSWORD = 8
const passwordSet = ref(null) // null = not known yet
const changing = ref(false) // the change form is open (always open on first run)
const oldPassword = ref('')
const newPassword = ref('')
const confirmPassword = ref('')
const showPasswords = ref(false)
const savingPassword = ref(false)
const passwordMessage = ref(null) // { ok, text }

async function loadPasswordState() {
  try {
    passwordSet.value = (await request('/api/local/printers/admin-password')).set
  } catch {
    passwordSet.value = null
  }
}

function passwordProblem() {
  if (newPassword.value.length < MIN_PASSWORD) return t('settings.printers.password.tooShort', { n: MIN_PASSWORD })
  if (newPassword.value !== confirmPassword.value) return t('settings.printers.password.mismatch')
  if (passwordSet.value && !oldPassword.value) return t('settings.printers.password.needCurrent')
  return null
}

async function savePassword() {
  const problem = passwordProblem()
  if (problem) {
    passwordMessage.value = { ok: false, text: problem }
    return
  }
  savingPassword.value = true
  passwordMessage.value = null
  try {
    await request('/api/local/printers/admin-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ old: passwordSet.value ? oldPassword.value : null, new: newPassword.value }),
    })
    oldPassword.value = newPassword.value = confirmPassword.value = ''
    changing.value = false
    showPasswords.value = false
    passwordMessage.value = { ok: true, text: t('settings.printers.password.saved') }
    await loadPasswordState()
  } catch (err) {
    passwordMessage.value = { ok: false, text: err.message }
  } finally {
    savingPassword.value = false
  }
}

function cancelChange() {
  changing.value = false
  oldPassword.value = newPassword.value = confirmPassword.value = ''
  passwordMessage.value = null
}

function openCups() {
  grantReloadPass() // coming back via Back reloads Settings; don't ask for the PIN again
  window.location.href = CUPS_URL
}

const STATES = ['idle', 'printing', 'error', 'offline']
const stateLabel = (state) => t(`settings.printers.state.${STATES.includes(state) ? state : 'offline'}`)

onMounted(() => {
  loadPasswordState()
  load()
  timer = setInterval(load, REFRESH_MS)
})
onUnmounted(() => clearInterval(timer))
</script>

<template>
  <div class="settings-page">
    <section class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.printers.title') }}</h2></div>

      <p v-if="cupsError" class="pill warn note">{{ cupsError }}</p>
      <p v-else-if="printers === null" class="hint">{{ t('settings.printers.loading') }}</p>
      <p v-else-if="!printers.length" class="hint">{{ t('settings.printers.none') }}</p>

      <ul v-if="printers?.length" class="printers">
        <li v-for="p in printers" :key="p.name" class="printer">
          <div class="head">
            <strong class="name">{{ p.name }}</strong>
            <span class="pill" :class="p.state === 'idle' || p.state === 'printing' ? 'ok' : 'warn'">{{ stateLabel(p.state) }}</span>
            <span v-if="p.message" class="hint">{{ p.message }}</span>
          </div>
          <div class="detail">
            <span v-if="p.model">{{ p.model }}</span>
            <span v-if="p.device">{{ p.device }}</span>
            <span v-if="p.location">{{ p.location }}</span>
            <span v-if="p.jobs">{{ t('settings.printers.jobs', p.jobs) }}</span>
          </div>
          <div class="actions">
            <button class="tile action" :disabled="testing === p.name" @click="printTestPage(p.name)">
              {{ testing === p.name ? t('settings.printers.testing') : t('settings.printers.testPage') }}
            </button>
            <span v-if="results[p.name]" class="pill" :class="results[p.name].ok ? 'ok' : 'warn'">{{ results[p.name].text }}</span>
          </div>
        </li>
      </ul>
    </section>

    <section class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.printers.manageTitle') }}</h2></div>

      <template v-if="passwordSet">
        <p class="hint">{{ t('settings.printers.manageHint') }}</p>
        <p class="hint">{{ t('settings.printers.loginHint') }}</p>
        <div class="actions">
          <button class="tile action primary" @click="openCups">{{ t('settings.printers.openCups') }}</button>
          <button v-if="!changing" class="tile action" @click="changing = true; passwordMessage = null">
            {{ t('settings.printers.password.change') }}
          </button>
        </div>
        <p class="hint note">{{ t('settings.printers.backHint') }}</p>
      </template>
      <p v-else-if="passwordSet === false" class="hint">{{ t('settings.printers.password.firstRun') }}</p>

      <form v-if="passwordSet === false || changing" class="password-form" @submit.prevent="savePassword">
        <label v-if="passwordSet">
          <span>{{ t('settings.printers.password.current') }}</span>
          <input v-model="oldPassword" :type="showPasswords ? 'text' : 'password'" autocomplete="off" />
        </label>
        <label>
          <span>{{ t('settings.printers.password.new') }}</span>
          <input v-model="newPassword" :type="showPasswords ? 'text' : 'password'" autocomplete="off" />
        </label>
        <label>
          <span>{{ t('settings.printers.password.confirm') }}</span>
          <input v-model="confirmPassword" :type="showPasswords ? 'text' : 'password'" autocomplete="off" />
        </label>
        <div class="actions">
          <button type="submit" class="tile action primary" :disabled="savingPassword">
            {{ savingPassword ? t('settings.printers.password.saving') : passwordSet ? t('settings.printers.password.save') : t('settings.printers.password.set') }}
          </button>
          <button type="button" class="tile action" @click="showPasswords = !showPasswords">
            {{ showPasswords ? t('settings.printers.password.hide') : t('settings.printers.password.show') }}
          </button>
          <button v-if="passwordSet" type="button" class="tile action" @click="cancelChange">{{ t('settings.printers.password.cancel') }}</button>
        </div>
      </form>
      <p v-if="passwordMessage" class="pill note" :class="passwordMessage.ok ? 'ok' : 'warn'">{{ passwordMessage.text }}</p>
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
.printers {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 1rem;
}
.printer + .printer {
  border-top: 1px solid var(--text-dim);
  padding-top: 1rem;
}
.head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.2rem 0.8rem;
}
.head .hint {
  margin: 0;
}
.name {
  font-size: 1.1rem;
}
.detail {
  display: flex;
  flex-wrap: wrap;
  gap: 0.2rem 1.2rem;
  color: var(--text-dim);
  margin: 0.3rem 0 0.7rem;
  overflow-wrap: anywhere;
}
.actions {
  display: flex;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
}
.password-form {
  display: grid;
  gap: 0.8rem;
  max-width: 28rem;
  margin: 0.9rem 0 0;
}
.password-form label {
  display: grid;
  gap: 0.25rem;
}
.password-form label span {
  color: var(--text-dim);
  font-weight: 600;
}
.action {
  padding: 0.75rem 1.5rem;
  font-size: 1rem;
}
.action.primary {
  border-color: var(--accent);
  background: var(--accent);
  color: #fff;
  font-weight: 600;
}
</style>
