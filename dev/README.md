# Developer docs

Start here for how to build, test and change the project. Humans and agents use the same docs; `/CLAUDE.md` holds only the
conventions an agent must always have loaded and points back here.

## Processes
| To do this | Read |
|---|---|
| Find the code for a feature | [CODE_MAP.md](CODE_MAP.md) |
| Build and run from source; update a bare-metal install by hand | [BUILDING.md](BUILDING.md) |
| Run tests; the disposable test database | [TESTING.md](TESTING.md) |
| Look up a table or column | [SCHEMA.md](SCHEMA.md) (generated) |
| Change the schema | Add a migration, then run `dev/scripts/schema-doc.sh` and commit `SCHEMA.md` with it |
| Back up / restore | [notes/BACKUPS.md](notes/BACKUPS.md) |
| Run or update a Docker install | [../docker/README.md](../docker/README.md), [../docker/INSTALL.md](../docker/INSTALL.md); design history: [DOCKER_PLAN.md](DOCKER_PLAN.md) |
| Simulate load / hardware | [notes/SIMULATOR.md](notes/SIMULATOR.md), [notes/REVERB.md](notes/REVERB.md) |
| Give an AI agent access | [AI_AGENT_ACCESS.md](AI_AGENT_ACCESS.md) |
| Convert an old rsync-deployed bare-metal server to git (legacy; only for the deprecated `scripts/update.sh`) | [CONVERT_TO_GIT.md](CONVERT_TO_GIT.md) |

Bare-metal (systemd) installs are supported as a single warehouse. Their in-app web updater is deprecated and slated for removal
([TODO.md](TODO.md)); `scripts/update.sh` is deprecated too but stays for now (shell use only). Administrators update by hand as described in
[BUILDING.md](BUILDING.md). `scripts/` holds only executables and
systemd units; documentation lives here.

## Planning
[ROADMAP.md](ROADMAP.md) (ideas; PoD design in [POD.md](POD.md), interop in [INTEROPERABILITY.md](INTEROPERABILITY.md)) · [TODO.md](TODO.md) (open work only) · [FACILITY_NETWORK.md](FACILITY_NETWORK.md) (unbuilt facility-network design) · [notes/MULTI_WAREHOUSE.md](notes/MULTI_WAREHOUSE.md) (design and rationale; [the notes](notes/MULTI_WAREHOUSE_NOTES.md) are the quick version) · [../CHANGELOG.md](../CHANGELOG.md) (what shipped; currently incomplete)

## Retired docs
- **`PROJECT_ANALYSIS.md`** (the August 2026 codebase analysis and completion plan) was deleted once most of it was built or fixed.
  Its unbuilt facility-network design is now [FACILITY_NETWORK.md](FACILITY_NETWORK.md); its open items went to [TODO.md](TODO.md) and
  [ROADMAP.md](ROADMAP.md); what was built is described in [notes/](notes/). Some migration comments still cite its "Part N"; to read the
  old text: `git show b94ae09^:dev/notes/PROJECT_ANALYSIS.md`.

## Implementation notes (`notes/`)
Read the one for the area you're changing.
- [MULTI_WAREHOUSE_NOTES.md](notes/MULTI_WAREHOUSE_NOTES.md): context, accounts, backups/restore, self-update (rationale: [MULTI_WAREHOUSE.md](notes/MULTI_WAREHOUSE.md))
- [ORDERS_AND_FILLING.md](notes/ORDERS_AND_FILLING.md): status lifecycles, filling, allocation, pick plan
- [STOCK_AND_LOCATIONS.md](notes/STOCK_AND_LOCATIONS.md): pallet stock, Fill Pallet, adjustments, inventory labels
- [RECEIVING.md](notes/RECEIVING.md): pallets/containers, Receiving wizard, donation offers
- [UPC_SCANNING.md](notes/UPC_SCANNING.md): barcode → item, cartons, online lookup
- [FEDERATION.md](notes/FEDERATION.md): identity, pairing, grants, what's built
- [PRINT_QUEUE.md](notes/PRINT_QUEUE.md): print jobs, print servers, device API
- [SUB_PROJECTS.md](notes/SUB_PROJECTS.md): RI Launcher, RI Print, R Station. Their own docs: [launcher/README.md](../launcher/README.md), [print-client/README.md](../print-client/README.md), and in `rstation/`: [IMPLEMENTATION.md](../rstation/IMPLEMENTATION.md), [LOCAL_SERVER.md](../rstation/LOCAL_SERVER.md), [PRINT_SERVER.md](../rstation/PRINT_SERVER.md), [PRINT_CLIENT_SPEC.md](../rstation/PRINT_CLIENT_SPEC.md), [FEDERATION_AUTH.md](../rstation/FEDERATION_AUTH.md), [CUPS_BACKPORT.md](../rstation/CUPS_BACKPORT.md)
- [BACKUPS.md](notes/BACKUPS.md): backup zips, tiers, restore, recovery key
- [REVERB.md](notes/REVERB.md): realtime server provisioning
- [SIMULATOR.md](notes/SIMULATOR.md): load and hardware simulation
- [UNTRUSTED_CONTENT.md](notes/UNTRUSTED_CONTENT.md): feedback reports, AI-assisted work
- [FIRST_RUN.md](notes/FIRST_RUN.md): setup wizard and its latch
- [REPORTS.md](notes/REPORTS.md): report structure and definitions
- [TRAINING_WAREHOUSE.md](notes/TRAINING_WAREHOUSE.md): class tooling
- [FRONTEND.md](notes/FRONTEND.md): RIForm, autosave screens, help guides
- [SCANNING_INTERFACE_NOTES.md](notes/SCANNING_INTERFACE_NOTES.md): scanner UX rules and checklist
