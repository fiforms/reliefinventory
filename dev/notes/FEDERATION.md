# Federation and trust

Every instance (and every warehouse: each is a separate server to a client) has an **Ed25519 identity**; two parties become a **trust** by pairing, and the trust carries explicit permission grants. There are no bearer tokens. The wire protocol for client implementers is `rstation/FEDERATION_AUTH.md` (+ `rstation/federation.openapi.yaml`); this is the server-side map.

- **Identity:** `App\Services\Federation\InstanceIdentity`; public half at `GET /.well-known/ri-instance.json`. Peers pin it, and site-to-site trusts can be domain-verified (`DomainVerifier`).
- **Pairing:** an admin creates a 10-character single-use code (15 min; `TrustService::createPairingCode`) in the Trust Console (`/setup/trust`, `TrustConsole.vue`) or on `/setup/devices`; the client posts `POST /api/federation/v1/pair` with a proof of key. Kinds (`Trust::KINDS`): `instance`, `print_client`, `rstation`, `backup_client`.
- **Requests** are signed (`RequestSigner`, `VerifiesSignedRequests`, middleware `trust-auth:<grant>`); `tests/Fixtures/federation-signing-vectors.json` holds vectors every implementation (PHP, Rust, Python) must reproduce. Sessions are cache entries keyed to a `session_epoch`; re-approval or revoke bumps it.
- **Everything that creates or changes a trust goes through `TrustService`** so it is audited (`TrustAuditLog`) and takes effect at once. Revocation is immediate.
- **Grants** (`App\Support\TrustPermission`): `print.share`, `feedback.send`/`receive`, `offers.send`/`receive`, `inventory.query_exact`, `supplies.request`/`query_available`, `operator.full_read`.
- **Instance-level trust** (`InstanceTrust`, control schema, `/api/instance/v1`, permission `instance.backup`) is a separate pairing to the whole instance (the RI Launcher's backups). A warehouse trust never passes at its endpoints, nor the reverse. See `MULTI_WAREHOUSE_NOTES.md`.

## What runs on it today
Device pairing (R Station, RI Print), the print queue (`PRINT_QUEUE.md`), feedback aggregation between instances (`/api/federation/v1/feedback`), and operator backups (`/api/federation/v1/backups`, `operator.full_read`).

## Not built
Endpoints that actually share offers, supplies, inventory or reports between instances: the grants exist, nothing serves them yet (check `routes/api.php`). There is no record-level sync; primary keys are local auto-increment ids, so anything exchanged needs globally unique identifiers. Trust expiry and key rotation are open. Design ideas: `dev/INTEROPERABILITY.md`.
