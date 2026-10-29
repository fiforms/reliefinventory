# Developer docs

Start here for how to build, test and change the project. Humans and agents use the same docs; `/CLAUDE.md` holds only the
conventions an agent must always have loaded and points back here.

## Processes
| To do this | Read |
|---|---|
| Build and run from source | [BUILDING.md](BUILDING.md) |
| Run tests; the disposable test database | [../scripts/TESTING.md](../scripts/TESTING.md) |
| Look up a table or column | [SCHEMA.md](SCHEMA.md) (generated) |
| Change the schema | Add a migration, then run `dev/scripts/schema-doc.sh` and commit `SCHEMA.md` with it |
| Back up / restore | [../scripts/BACKUPS.md](../scripts/BACKUPS.md) |
| Update an install | [../scripts/DOCKER_PLAN.md](../scripts/DOCKER_PLAN.md), [../scripts/update.sh](../scripts/update.sh) |
| Simulate load / hardware | [../scripts/SIMULATOR.md](../scripts/SIMULATOR.md), [../scripts/REVERB.md](../scripts/REVERB.md) |
| Give an AI agent access | [../scripts/AI_AGENT_ACCESS.md](../scripts/AI_AGENT_ACCESS.md) |

## Planning
[ROADMAP.md](ROADMAP.md) (ideas; PoD design in [POD.md](POD.md), interop in [INTEROPERABILITY.md](INTEROPERABILITY.md)) · [TODO.md](TODO.md) · [notes/PROJECT_ANALYSIS.md](notes/PROJECT_ANALYSIS.md) (stale notes; verify before relying on) · [../MULTI_WAREHOUSE.md](../MULTI_WAREHOUSE.md) (design) · [../CHANGELOG.md](../CHANGELOG.md)

## Implementation notes (`notes/`)
Read the one for the area you're changing.
- [MULTI_WAREHOUSE_NOTES.md](notes/MULTI_WAREHOUSE_NOTES.md): context, accounts, backups/restore, self-update
- [ORDERS_AND_FILLING.md](notes/ORDERS_AND_FILLING.md): status lifecycles, filling, allocation, pick plan
- [STOCK_AND_LOCATIONS.md](notes/STOCK_AND_LOCATIONS.md): pallet stock, Fill Pallet, adjustments, inventory labels
- [RECEIVING.md](notes/RECEIVING.md): pallets/containers, Receiving wizard, donation offers
- [UPC_SCANNING.md](notes/UPC_SCANNING.md): barcode → item, cartons, online lookup
- [FEDERATION.md](notes/FEDERATION.md): identity, pairing, grants, what's built
- [PRINT_QUEUE.md](notes/PRINT_QUEUE.md): print jobs, print servers, device API
- [SUB_PROJECTS.md](notes/SUB_PROJECTS.md): RI Launcher, RI Print, R Station
- [UNTRUSTED_CONTENT.md](notes/UNTRUSTED_CONTENT.md): feedback reports, AI-assisted work
- [FIRST_RUN.md](notes/FIRST_RUN.md): setup wizard and its latch
- [REPORTS.md](notes/REPORTS.md): report structure and definitions
- [TRAINING_WAREHOUSE.md](notes/TRAINING_WAREHOUSE.md): class tooling
- [FRONTEND.md](notes/FRONTEND.md): RIForm, autosave screens, help guides
- [SCANNING_INTERFACE_NOTES.md](notes/SCANNING_INTERFACE_NOTES.md): scanner UX rules and checklist
