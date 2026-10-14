// Minimal example product frontend — each seam in a line or two
// (docs/PRODUCTS.md, "Frontend seam"). Core code is imported via `@core/`.
import Main from './Main.vue'
import PortalSettings from './PortalSettings.vue'

export default {
  name: 'portal',
  // Mounted at /kiosk. For a remote-portal product the browser opens
  // KIOSK_URL instead (image/product.env), so this is only a fallback.
  mainView: Main,
  // An extra Settings page and its rail entry.
  settingsRoutes: [{ path: 'portal', component: PortalSettings, meta: { railPath: '/settings/portal' } }],
  railCategories: () => [{ path: '/settings/portal', label: 'Portal' }],
}
