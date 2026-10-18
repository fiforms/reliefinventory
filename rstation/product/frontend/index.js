// Minimal example product frontend — each seam in a line or two
// (docs/PRODUCTS.md, "Frontend seam"). Core code is imported via `@core/`.
import Main from './Main.vue'
import PortalSettings from './PortalSettings.vue'
import PrintersSettings from './PrintersSettings.vue'
import SetupServer from './SetupServer.vue'

export default {
  name: 'portal',
  // Mounted at /kiosk, the page Chromium always starts on (the image has no
  // KIOSK_URL): a launcher that hands the browser to the confirmed server once
  // the first-run wizard is done — see Main.vue.
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
  // First-run wizard: confirm which server this device belongs to, after the
  // network is up and before pairing (platform/docs/SERVER_URL.md).
  setupSteps: [{ key: 'server', after: 'network', path: 'server', component: SetupServer }],
  // Merged over the core locale files (docs/PRODUCTS.md).
  messages: {
    en: {
      launcher: {
        opening: 'Opening Relief Inventory…',
        noServer: 'No server has been set up on this device.',
        settings: 'Open Settings',
      },
      setup: {
        steps: { server: 'Server' },
        server: {
          title: 'Relief Inventory server',
          intro: 'Enter the web address of the Relief Inventory site this device belongs to.',
          label: 'Server address',
          hint: 'For example https://relief.example.org. This can’t be changed later without a factory reset.',
          locked: 'This device is set up for this server. To use a different one, do a factory reset.',
          useAnyway: 'Use this address anyway',
        },
      },
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
      launcher: {
        opening: 'Abriendo Relief Inventory…',
        noServer: 'No se ha configurado ningún servidor en este dispositivo.',
        settings: 'Abrir Configuración',
      },
      setup: {
        steps: { server: 'Servidor' },
        server: {
          title: 'Servidor de Relief Inventory',
          intro: 'Ingrese la dirección web del sitio de Relief Inventory al que pertenece este dispositivo.',
          label: 'Dirección del servidor',
          hint: 'Por ejemplo https://relief.example.org. No se puede cambiar después sin un restablecimiento de fábrica.',
          locked: 'Este dispositivo está configurado para este servidor. Para usar otro, haga un restablecimiento de fábrica.',
          useAnyway: 'Usar esta dirección de todos modos',
        },
      },
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
