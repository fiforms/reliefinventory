<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { request } from '@core/api.js'
import { grantReloadPass } from '@core/pinLock.js'
import DriverSelect from './DriverSelect.vue'

// Settings > Printers. Shows the printers CUPS has, prints a test page, and finds / adds /
// removes printers itself (backend/printer_setup.py, through a root helper) — driverless,
// or with a generic or model-specific driver found by searching the installed driver list
// (DriverSelect.vue). Vendor-supplied drivers that aren't installed, and paper or label sizes, are
// still done in CUPS's own web interface, which this opens under "Advanced" (see
// PRINT_SERVER.md, "Printer setup on the device"). The kiosk is a single Chromium tab, so
// that is a full-page navigation — like captivePortal.js — and the remote's Back key
// comes back through browser history.
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

// --- Adding and removing printers
const searching = ref(false)
const found = ref(null) // null = not searched yet; else the discovered printers
const drafts = ref({}) // discovered uri -> { name, driver }, what staff may edit before adding
const addingKey = ref(null) // discovered uri (or 'address') being added
const addMessage = ref(null) // { ok, text }
const address = ref('')
const addressDraft = ref({ name: '', driver: 'everywhere' })
const removing = ref(null) // queue name waiting for "are you sure"
const removeBusy = ref(null)

async function findPrinters() {
  searching.value = true
  addMessage.value = null
  try {
    const body = await request('/api/local/printers/discover', { method: 'POST' })
    found.value = body.printers
    drafts.value = Object.fromEntries(
      body.printers.map((p) => [p.uri, { name: p.name, driver: p.driverless ? 'everywhere' : 'generic-postscript' }]),
    )
  } catch (err) {
    found.value = found.value ?? []
    addMessage.value = { ok: false, text: err.message }
  } finally {
    searching.value = false
  }
}

async function addPrinter(key, payload, label) {
  addingKey.value = key
  addMessage.value = null
  try {
    await request('/api/local/printers', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload),
    })
    addMessage.value = { ok: true, text: t('settings.printers.add.added', { name: label }) }
    return true
  } catch (err) {
    addMessage.value = { ok: false, text: err.message }
    return false
  } finally {
    addingKey.value = null
    load()
  }
}

async function addFound(p) {
  const draft = drafts.value[p.uri]
  if (await addPrinter(p.uri, { name: draft.name.trim(), uri: p.uri, driver: draft.driver }, draft.name.trim())) p.added = true
}

async function addByAddress() {
  const name = addressDraft.value.name.trim()
  if (await addPrinter('address', { name, address: address.value, driver: addressDraft.value.driver }, name)) {
    address.value = addressDraft.value.name = ''
  }
}

async function removePrinter(name) {
  removeBusy.value = name
  try {
    await request(`/api/local/printers/${encodeURIComponent(name)}`, { method: 'DELETE' })
    delete results.value[name]
  } catch (err) {
    results.value[name] = { ok: false, text: err.message }
  } finally {
    removing.value = removeBusy.value = null
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

// CUPS's own admin page, already signed in: the device makes a one-time login for a dedicated
// account (printer_setup.py cups_login) and we navigate to it as http://user:password@host — a
// top-level navigation, which Chromium still honours. Falls back to the plain URL (CUPS then
// asks for a login) if that can't be prepared.
const openingCups = ref(false)
const cupsMessage = ref(null)
async function openCups() {
  openingCups.value = true
  cupsMessage.value = null
  let url = CUPS_URL
  try {
    url = (await request('/api/local/printers/cups-login', { method: 'POST' })).url
  } catch (err) {
    cupsMessage.value = err.message
  }
  grantReloadPass() // coming back via Back reloads Settings; don't ask for the PIN again
  window.location.href = url
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
            <template v-if="removing === p.name">
              <span class="hint confirm">{{ t('settings.printers.remove.confirm', { name: p.name }) }}</span>
              <button class="tile action danger" :disabled="removeBusy === p.name" @click="removePrinter(p.name)">
                {{ t('settings.printers.remove.yes') }}
              </button>
              <button class="tile action" :disabled="removeBusy === p.name" @click="removing = null">{{ t('settings.printers.remove.no') }}</button>
            </template>
            <button v-else class="tile action" @click="removing = p.name">{{ t('settings.printers.remove.button') }}</button>
            <span v-if="results[p.name]" class="pill" :class="results[p.name].ok ? 'ok' : 'warn'">{{ results[p.name].text }}</span>
          </div>
        </li>
      </ul>
    </section>

    <section class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.printers.add.title') }}</h2></div>
      <p class="hint">{{ t('settings.printers.add.hint') }}</p>
      <div class="actions">
        <button class="tile action primary" :disabled="searching" @click="findPrinters">
          {{ searching ? t('settings.printers.add.searching') : found === null ? t('settings.printers.add.find') : t('settings.printers.add.findAgain') }}
        </button>
        <span v-if="searching" class="hint">{{ t('settings.printers.add.searchingHint') }}</span>
      </div>

      <p v-if="found && !found.length && !searching" class="hint note">{{ t('settings.printers.add.nothingFound') }}</p>
      <ul v-if="found?.length" class="printers found">
        <li v-for="p in found" :key="p.uri" class="printer" :class="{ dim: p.added }">
          <div class="head">
            <strong class="name">{{ p.model }}</strong>
            <span class="pill">{{ p.connection }}</span>
            <span v-if="p.added" class="pill ok">{{ t('settings.printers.add.alreadyAdded') }}</span>
          </div>
          <div class="detail">
            <span v-if="p.location">{{ p.location }}</span>
            <span>{{ p.display_uri }}</span>
          </div>
          <p v-if="!p.driverless && !p.added" class="hint">{{ t('settings.printers.add.needsDriver') }}</p>
          <div v-if="!p.added" class="add-form">
            <label>
              <span>{{ t('settings.printers.add.name') }}</span>
              <input v-model="drafts[p.uri].name" maxlength="63" autocomplete="off" />
            </label>
            <DriverSelect v-model="drafts[p.uri].driver" :model="p.model" />
            <div class="actions">
              <button class="tile action primary" :disabled="addingKey !== null || !drafts[p.uri].name.trim()" @click="addFound(p)">
                {{ addingKey === p.uri ? t('settings.printers.add.adding') : t('settings.printers.add.button') }}
              </button>
            </div>
          </div>
        </li>
      </ul>

      <details class="by-address">
        <summary>{{ t('settings.printers.add.byAddress') }}</summary>
        <p class="hint">{{ t('settings.printers.add.addressHint') }}</p>
        <form class="add-form" @submit.prevent="addByAddress">
          <label>
            <span>{{ t('settings.printers.add.address') }}</span>
            <input v-model="address" placeholder="192.168.1.50" autocomplete="off" autocapitalize="off" />
          </label>
          <label>
            <span>{{ t('settings.printers.add.name') }}</span>
            <input v-model="addressDraft.name" maxlength="63" autocomplete="off" />
          </label>
          <DriverSelect v-model="addressDraft.driver" />
          <div class="actions">
            <button type="submit" class="tile action primary" :disabled="addingKey !== null || !address.trim() || !addressDraft.name.trim()">
              {{ addingKey === 'address' ? t('settings.printers.add.adding') : t('settings.printers.add.button') }}
            </button>
          </div>
        </form>
      </details>

      <p v-if="addMessage" class="pill note" :class="addMessage.ok ? 'ok' : 'warn'">{{ addMessage.text }}</p>
    </section>

    <section class="tile panel">
      <div class="panel-title"><h2>{{ t('settings.printers.manageTitle') }}</h2></div>

      <p class="hint">{{ t('settings.printers.manageHint') }}</p>
      <p class="hint">{{ t('settings.printers.loginHint') }}</p>
      <div class="actions">
        <button class="tile action primary" :disabled="openingCups" @click="openCups">
          {{ openingCups ? t('settings.printers.openingCups') : t('settings.printers.openCups') }}
        </button>
        <button v-if="!changing" class="tile action" @click="changing = true; passwordMessage = null">
          {{ t('settings.printers.password.change') }}
        </button>
      </div>
      <p v-if="cupsMessage" class="pill note warn">{{ cupsMessage }}</p>
      <p class="hint note">{{ t('settings.printers.backHint') }}</p>

      <form v-if="changing" class="password-form" @submit.prevent="savePassword">
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
.dim {
  opacity: 0.6;
}
.confirm {
  margin: 0;
}
.add-form {
  display: grid;
  gap: 0.8rem;
  max-width: 28rem;
  margin: 0.6rem 0 0;
}
.add-form label {
  display: grid;
  gap: 0.25rem;
}
.add-form label span {
  color: var(--text-dim);
  font-weight: 600;
}
.by-address {
  margin: 1.2rem 0 0;
}
.by-address summary {
  cursor: pointer;
  font-weight: 600;
}
.by-address .add-form {
  margin-top: 0.9rem;
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
