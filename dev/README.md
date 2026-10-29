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
[ROADMAP.md](ROADMAP.md) (ideas; PoD design in [pod.md](pod.md), interop in [interoperability.md](interoperability.md)) · [TODO.md](TODO.md) · [notes/PROJECT_ANALYSIS.md](notes/PROJECT_ANALYSIS.md) (stale notes; verify before relying on) · [../MULTI_WAREHOUSE.md](../MULTI_WAREHOUSE.md) (design) · [../CHANGELOG.md](../CHANGELOG.md)

## Implementation notes (`notes/`)
Read the one for the area you're changing.
- [multi-warehouse.md](notes/multi-warehouse.md): context, accounts, backups/restore, self-update
- [orders-and-filling.md](notes/orders-and-filling.md): status lifecycles, filling, allocation, pick plan
- [stock-and-locations.md](notes/stock-and-locations.md): pallet stock, Fill Pallet, adjustments, inventory labels
- [receiving.md](notes/receiving.md): pallets/containers, Receiving wizard, donation offers
- [upc-scanning.md](notes/upc-scanning.md): barcode → item, cartons, online lookup
- [reports.md](notes/reports.md): report structure and definitions
- [training-warehouse.md](notes/training-warehouse.md): class tooling
- [frontend.md](notes/frontend.md): RIForm, autosave screens, help guides
- [SCANNING_INTERFACE_NOTES.md](notes/SCANNING_INTERFACE_NOTES.md): scanner UX rules and checklist
