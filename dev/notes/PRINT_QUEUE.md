# Print queue

The web app never talks to a printer. Print points create **jobs** in a server-side queue; a **print server** (an R Station Pi or the RI Print desktop client, both paired devices) polls, claims and prints them.

- **Web side:** `Printer` / `PrintJob` / `PrintJobStatusLog` models, `PrintQueueService` (what a heartbeat does to printer rows; claim/expire mechanics; stale-claim expiry runs lazily whenever a server polls, since there is no scheduler dependency), `PrintBroadcaster` (optional realtime wake-up over Reverb, `scripts/REVERB.md`; HTTP polling stays authoritative), the `/print-queue` screen, the shared **Print ▾** menu on every print point (Download PDF, each printer, copies), printer setup on `/setup/devices`, and `LabelSetting` for label media.
- **Device API:** `DeviceHeartbeatController`, `DevicePrintJobController`, `routes/api.php`, authenticated by trust-signed requests (`FEDERATION.md`). New printers reported by a heartbeat start inactive and untyped until an admin names them and picks a paper type. A paired device whose Function is unset becomes `print_station` on its first print-queue report.
- **Wire protocol:** `rstation/PRINT_SERVER.md`, `rstation/print-extension.openapi.yaml` (a drift test guards it), and `rstation/PRINT_CLIENT_SPEC.md` for client behaviour rules. Building a client? Start with the spec.
- **Documents:** reports and labels are rendered to PDF with WeasyPrint (every PDF; nothing uses a browser). `DocumentRegistry` lists each print document and its `print-queue` entry.
- Clients: `SUB_PROJECTS.md`.
