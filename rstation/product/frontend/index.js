// Minimal example product frontend — each seam in a line or two
// (docs/PRODUCTS.md, "Frontend seam"). Core code is imported via `@core/`.
import Main from './Main.vue'
import PortalSettings from './PortalSettings.vue'
import PrintersSettings from './PrintersSettings.vue'

export default {
  name: 'portal',
  // Mounted at /kiosk. For a remote-portal product the browser opens
  // KIOSK_URL instead (image/product.env), so this is only a fallback.
  mainView: Main,
  // An extra Settings page and its rail entry.
  settingsRoutes: [
    { path: 'portal', component: PortalSettings, meta: { railPath: '/settings/portal' } },
    { path: 'printers', component: PrintersSettings, meta: { railPath: '/settings/printers' } },
  ],
  railCategories: (t) => [
    { path: '/settings/portal', label: 'Portal' },
    { path: '/settings/printers', label: t('settings.printers.title') },
  ],
  // Merged over the core locale files (docs/PRODUCTS.md).
  messages: {
    en: {
      settings: {
        printers: {
          title: 'Printers',
          loading: 'Looking for printers…',
          none: 'No printers are set up on this device yet. Once a password is set below, use “Open printer setup” to add one.',
          testPage: 'Print test page',
          testing: 'Sending…',
          testSent: 'Test page sent',
          jobs: '1 job waiting | {n} jobs waiting',
          state: { idle: 'Ready', printing: 'Printing', error: 'Needs attention', offline: 'Offline' },
          manageTitle: 'Add or change printers',
          manageHint: 'Adding a printer, choosing its driver and setting the paper or label size is done in the printer setup page (CUPS).',
          loginHint: 'It asks you to sign in. The user name is slideadmin, with the password you set here. This is also the login for this device’s console and keyboard.',
          openCups: 'Open printer setup',
          backHint: 'To return to Settings from there, press the remote’s Back button.',
          password: {
            firstRun: 'Set a password first. Printer setup asks for it, so keep it somewhere safe. If it’s ever lost, a factory reset starts over (it also removes the printers set up here).',
            set: 'Set password',
            change: 'Change password',
            save: 'Save new password',
            saving: 'Saving…',
            cancel: 'Cancel',
            current: 'Current password',
            new: 'New password',
            confirm: 'New password again',
            show: 'Show',
            hide: 'Hide',
            tooShort: 'The password must be at least {n} characters.',
            mismatch: 'The two new passwords don’t match.',
            needCurrent: 'Enter the current password.',
            saved: 'Password saved',
          },
        },
      },
    },
    es: {
      settings: {
        printers: {
          title: 'Impresoras',
          loading: 'Buscando impresoras…',
          none: 'Todavía no hay impresoras configuradas en este dispositivo. Una vez establecida una contraseña abajo, use “Abrir configuración de impresoras” para agregar una.',
          testPage: 'Imprimir página de prueba',
          testing: 'Enviando…',
          testSent: 'Página de prueba enviada',
          jobs: '1 trabajo en espera | {n} trabajos en espera',
          state: { idle: 'Lista', printing: 'Imprimiendo', error: 'Requiere atención', offline: 'Sin conexión' },
          manageTitle: 'Agregar o cambiar impresoras',
          manageHint: 'Agregar una impresora, elegir su controlador y configurar el tamaño del papel o de la etiqueta se hace en la página de configuración de impresoras (CUPS).',
          loginHint: 'Le pedirá iniciar sesión. El usuario es slideadmin, con la contraseña que establezca aquí. Es también el acceso a la consola y el teclado de este dispositivo.',
          openCups: 'Abrir configuración de impresoras',
          backHint: 'Para volver a Configuración desde allí, pulse el botón Atrás del control remoto.',
          password: {
            firstRun: 'Primero establezca una contraseña. La configuración de impresoras la pide, así que guárdela en un lugar seguro. Si se pierde, un restablecimiento de fábrica empieza de nuevo (también elimina las impresoras configuradas aquí).',
            set: 'Establecer contraseña',
            change: 'Cambiar contraseña',
            save: 'Guardar nueva contraseña',
            saving: 'Guardando…',
            cancel: 'Cancelar',
            current: 'Contraseña actual',
            new: 'Nueva contraseña',
            confirm: 'Repita la nueva contraseña',
            show: 'Mostrar',
            hide: 'Ocultar',
            tooShort: 'La contraseña debe tener al menos {n} caracteres.',
            mismatch: 'Las dos contraseñas nuevas no coinciden.',
            needCurrent: 'Ingrese la contraseña actual.',
            saved: 'Contraseña guardada',
          },
        },
      },
    },
  },
}
