// Minimal example product frontend — each seam in a line or two
// (docs/PRODUCTS.md, "Frontend seam"). Core code is imported via `@core/`.
import Main from './Main.vue'
import PortalSettings from './PortalSettings.vue'
import PrintersSettings from './PrintersSettings.vue'
import SetupServer from './SetupServer.vue'
import EscapeHint from './EscapeHint.vue'

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
  // Tells the person setting the device up how to get back to Settings once it
  // has handed the screen to the website (platform/docs/ESCAPE_HATCH.md).
  setupDoneNote: EscapeHint,
  // Merged over the core locale files (docs/PRODUCTS.md).
  messages: {
    en: {
      settingsLayout: { backToSlideshow: '← Back to R Station' },
      pinGate: { countdown: 'Returning to R Station in {seconds}s…' },
      launcher: {
        opening: 'Opening Relief Inventory…',
        noServer: 'No server has been set up on this device.',
        settings: 'Open Settings',
      },
      escapeHint: {
        title: 'Getting back to Settings',
        body: 'Once this device shows the Relief Inventory site, press Ctrl+Alt+Home on a keyboard (Ctrl+Alt+S if it has no Home key), or hold the remote’s Home button for 3 seconds.',
      },
      setup: {
        welcome: { title: 'Welcome to R Station' },
        name: { intro: 'Give this device a name that says where it is — or keep the default.' },
        pairing: { title: 'Pair with your site' },
        done: { start: 'Launch R Station' },
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
        pairing: {
          goToSlideshow: 'Go to R Station',
          generateHint: 'Log in to the Relief Inventory Portal, open R Station Devices, choose your site and generate a pairing code. Then enter the code below.',
          generateHintUrl: 'Log in to the Relief Inventory Portal, open R Station Devices, choose your site and generate a pairing code. Then enter the code below. Your Relief Inventory Portal is at:',
        },
        printers: {
          title: 'Printers',
          loading: 'Looking for printers…',
          none: 'No printers are set up on this device yet. Use “Find printers” below to add one.',
          testPage: 'Print test page',
          testing: 'Sending…',
          testSent: 'Test page sent',
          jobs: '1 job waiting | {n} jobs waiting',
          state: { idle: 'Ready', printing: 'Printing', error: 'Needs attention', offline: 'Offline' },
          remove: {
            button: 'Remove',
            confirm: 'Remove {name}?',
            yes: 'Yes, remove',
            no: 'Keep',
          },
          add: {
            title: 'Add a printer',
            hint: 'Printers on the same network, and printers plugged into this device, can be found automatically.',
            find: 'Find printers',
            findAgain: 'Search again',
            searching: 'Searching…',
            searchingHint: 'This takes about 15 seconds.',
            nothingFound: 'No printers were found. Check that the printer is on and on the same network, or add it by its address below.',
            alreadyAdded: 'Already added',
            needsDriver: 'This printer doesn’t announce support for driverless printing, so a generic driver is selected. If the test page doesn’t print, try the other one.',
            name: 'Name on this device',
            driver: 'Driver',
            drivers: {
              everywhere: 'Automatic (driverless) — most modern printers',
              'generic-postscript': 'Generic PostScript',
              'generic-pcl': 'Generic PCL (black and white)',
            },
            button: 'Add printer',
            adding: 'Adding… this can take a minute',
            added: '{name} was added. Print a test page to check it.',
            byAddress: 'Add by address instead',
            addressHint: 'Use this if the printer isn’t found — for example when it is on a different network. Enter its IP address or host name.',
            address: 'Printer address',
          },
          manageTitle: 'Advanced printer setup',
          manageHint: 'For printers that need a vendor driver, or to set the paper or label size, use the printer setup page (CUPS).',
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
      settingsLayout: { backToSlideshow: '← Volver a R Station' },
      pinGate: { countdown: 'Volviendo a R Station en {seconds}s…' },
      launcher: {
        opening: 'Abriendo Relief Inventory…',
        noServer: 'No se ha configurado ningún servidor en este dispositivo.',
        settings: 'Abrir Configuración',
      },
      escapeHint: {
        title: 'Volver a Configuración',
        body: 'Cuando este dispositivo muestre el sitio de Relief Inventory, pulse Ctrl+Alt+Inicio en un teclado (Ctrl+Alt+S si no tiene tecla Inicio), o mantenga pulsado el botón Inicio del control remoto durante 3 segundos.',
      },
      setup: {
        welcome: { title: 'Bienvenido a R Station' },
        name: { intro: 'Dele a este dispositivo un nombre que indique dónde está — o mantenga el predeterminado.' },
        pairing: { title: 'Emparejar con su sitio' },
        done: { start: 'Iniciar R Station' },
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
        pairing: {
          goToSlideshow: 'Ir a R Station',
          generateHint: 'Inicie sesión en el Portal de Relief Inventory, abra R Station Devices, elija su sitio y genere un código de emparejamiento. Luego escriba el código abajo.',
          generateHintUrl: 'Inicie sesión en el Portal de Relief Inventory, abra R Station Devices, elija su sitio y genere un código de emparejamiento. Luego escriba el código abajo. Su Portal de Relief Inventory está en:',
        },
        printers: {
          title: 'Impresoras',
          loading: 'Buscando impresoras…',
          none: 'Todavía no hay impresoras configuradas en este dispositivo. Use “Buscar impresoras” abajo para agregar una.',
          testPage: 'Imprimir página de prueba',
          testing: 'Enviando…',
          testSent: 'Página de prueba enviada',
          jobs: '1 trabajo en espera | {n} trabajos en espera',
          state: { idle: 'Lista', printing: 'Imprimiendo', error: 'Requiere atención', offline: 'Sin conexión' },
          remove: {
            button: 'Quitar',
            confirm: '¿Quitar {name}?',
            yes: 'Sí, quitar',
            no: 'Conservar',
          },
          add: {
            title: 'Agregar una impresora',
            hint: 'Las impresoras de la misma red y las conectadas a este dispositivo se pueden encontrar automáticamente.',
            find: 'Buscar impresoras',
            findAgain: 'Buscar de nuevo',
            searching: 'Buscando…',
            searchingHint: 'Tarda unos 15 segundos.',
            nothingFound: 'No se encontraron impresoras. Verifique que la impresora esté encendida y en la misma red, o agréguela por su dirección abajo.',
            alreadyAdded: 'Ya agregada',
            needsDriver: 'Esta impresora no indica compatibilidad con impresión sin controlador, así que se seleccionó un controlador genérico. Si la página de prueba no se imprime, pruebe el otro.',
            name: 'Nombre en este dispositivo',
            driver: 'Controlador',
            drivers: {
              everywhere: 'Automático (sin controlador) — la mayoría de las impresoras modernas',
              'generic-postscript': 'PostScript genérico',
              'generic-pcl': 'PCL genérico (blanco y negro)',
            },
            button: 'Agregar impresora',
            adding: 'Agregando… puede tardar un minuto',
            added: 'Se agregó {name}. Imprima una página de prueba para comprobarla.',
            byAddress: 'Agregar por dirección',
            addressHint: 'Úselo si no se encuentra la impresora, por ejemplo cuando está en otra red. Ingrese su dirección IP o nombre de host.',
            address: 'Dirección de la impresora',
          },
          manageTitle: 'Configuración avanzada de impresoras',
          manageHint: 'Para impresoras que necesitan un controlador del fabricante, o para configurar el tamaño del papel o de la etiqueta, use la página de configuración de impresoras (CUPS).',
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
