# Federation authentication — pairing, signed requests, sessions

How a client app (the desktop print client, an R Station) or another Relief Inventory instance proves who
it is. Machine-readable form: `rstation/federation.openapi.yaml`. Server design and the Trust Console are
described in `dev/notes/FEDERATION.md`; this file is what a **client implementer** needs. It replaces the
older bearer-token pairing in `rstation/platform/docs/DEVICE_CONTRACT.md` for this app: **there are no bearer tokens any more**, and a device paired before federation is refused (401) and must pair again.

## Keys

- Every party has an **Ed25519** key pair. The private key never leaves its owner; the public key is shared.
- Keys and signatures are **base64url without padding**. The private key is stored as its 32-byte **seed**.
- A client generates its key once (first run), keeps it across un-pairing, and keeps the file private
  (0600). The server's identity is public: `GET /.well-known/ri-instance.json` →
  `{protocol, uuid, name, url, public_key, fingerprint}`.
- **Fingerprint** = SHA-256 of the raw public key, first 16 hex digits upper-cased, grouped `AAAA-BBBB-CCCC-DDDD`.

## Pairing

1. An administrator creates a **pairing code** (Trust Console, or Devices page for a device/print server):
   10 characters from `A–Z` (no I, O) and `2–9`, shown `ABCDE-FGHJK`. Single use, 15 minutes.
2. The client posts `POST /api/federation/v1/pair`:
   `{code, kind, uuid, name, public_key, signature, base_url?, mac_address?, language?}` where `kind` is
   `print_client`, `rstation`, `backup_client` or `instance`, `uuid` is the client's stable id (≤ 64 chars) and
   `signature` is the Ed25519 signature, by the key being submitted, over the UTF-8 string

       "ri-pair-v1\n" + CODE + "\n" + public_key + "\n" + uuid

   with CODE upper-cased and stripped of everything but letters and digits.
3. `201` returns the server's identity (`server.public_key` — pin it), the grants the trust starts with and,
   for a device, `device_id`, `entity_*`, `sibling_hostnames`, `language*`. There is **no token**.
4. Any failure of the code or the proof is one generic `422`; `429` means slow down.

Pairing again with the same `uuid` re-attaches the existing record; a different key is accepted only with a
fresh code and is recorded as a key change. Un-pairing on the client forgets the server, not the key.

## Warehouses: the server address can carry a path

An instance can host several warehouses (`MULTI_WAREHOUSE.md`). Each is a separate server as far as a client is
concerned — its own identity key, trusts, pairing codes and grants — and is addressed **by URL**: its own hostname,
or `https://host/w/<id>` on a host shared with other warehouses (`<id>` is 8 letters and digits). A client keeps the
whole URL it was given as its server address and puts every path after it: `https://host/w/K7Q2X9MD/api/federation/v1/pair`.
An address without `/w/<id>` reaches the instance's default warehouse, which is where everything paired before there
were several warehouses lives, so existing clients need no change. `/.well-known/ri-instance.json` also reports the
`warehouse` id and `shared_host` (true when the address is shared, so its domain can't vouch for this warehouse alone).

## Signing a request

Send these headers: `X-RI-Trust` (the client's `uuid`), `X-RI-Timestamp` (unix seconds), `X-RI-Nonce`
(unique, 8–64 chars, never reused), `X-RI-Signature` — the signature over the UTF-8 string, lines joined
with `\n`:

    METHOD                      upper-case
    PATH?QUERY                  exactly as sent, e.g. /api/devices/print-jobs?x=1 — including any /w/<id> prefix
    HOST                        lower-case, with the port only if it is not the scheme's default
    TIMESTAMP
    NONCE
    SHA256-hex(body)            lower-case hex of the raw request body; empty body for a GET

Sign the exact bytes you send (serialize JSON once). `tests/Fixtures/federation-signing-vectors.json` holds
vectors every implementation must reproduce (the PHP server, the Rust client and the Python Pi code all test
against the same file). The server accepts a timestamp within ±5 minutes and refuses a repeated nonce.

## Sessions (so every request need not be signed)

`POST /api/federation/v1/session` with a signed request (body `{}`) returns
`{session_token: "RIS-…", expires_in: 3600}`. Send `Authorization: Bearer <session_token>` on later requests.
The session endpoint itself always needs a signature. Refresh the session shortly before it expires, and
whenever a request with one gets `401`: sign once for a new one before concluding anything. The server
re-reads the trust's status and grants on every request, so revocation and permission changes are immediate.

## Key-signed bearer credential (for a client that can only send one fixed `Authorization` value)

The Pi's platform code (`rstation/platform`, an unmodified submodule) sends the contents of one file as its
bearer token and cannot sign per request. Such a client keeps a **credential signed by its own key** in that
place and refreshes it in the background:

    RIK-<base64url uuid>.<issued-at>.<expires-at>.<signature>

`signature` is Ed25519 over (lines joined with `\n`)

    ri-bearer-v1
    <uuid>
    <server host, lower-case, port only if not the scheme's default>[/w/<id>]
    <issued-at>
    <expires-at>

The audience line is the host, followed by the server address's `/w/<id>` warehouse prefix when it has one
(lower-cased with the rest), so a credential for one warehouse is refused by another on the same host — even when
the client paired both with the same key. The server accepts it only for its own address, only for a `rstation` or `print_client` trust, only if
`expires-at − issued-at` is at most 31 days, and only within `[issued-at − 5 min, expires-at)`. It carries no
server-side state, so a cache flush or a database restore cannot invalidate it. Stealing one lets the thief act as
that device until it expires or the trust is revoked — which is why a client that *can* sign per request should.
It cannot be used on `/session`, which always needs a signature.

A genuine credential that is **expired or issued by a wrong clock** gets **`419`**, not `401`: the Pi platform wipes
its pairing on any `401`, and a stale credential is cured by minting a new one. Mint a fresh one when the old one
is a few hours old (the R Station does so every 6 h, with a 14-day lifetime).

**Stamp it with the server's time, not the device's.** A device with no real-time clock (every Pi) boots with the
time it last saved, which after a long power-off can be weeks behind, and in an offline site may never be
corrected. Read the server's time from the `Date` header of any response (the R Station uses `GET /up`) and use that
for `issued-at` / `expires-at`; if the server cannot be reached, leave the existing credential alone rather than
replace it with one made from a bad clock. The server tolerates 5 minutes either way.

## The Raspberry Pi (R Station)

Everything lives in `rstation/product/backend/federation.py`; the platform is untouched. It depends on these
platform internals, which a platform update must keep (a test fails if the first changes shape):
`pairing.pair(code, device_name)` is called by `main.py` through the module (the product replaces that attribute);
`pairing.DEVICE_TOKEN_FILE` is read for the bearer by `heartbeat.py`, `system/scripts/rauc-update.py` and
`print_api.py`; `pairing.read_server_url()`, `write_device_name`, `write_entity_name`, `apply_server_language`,
`slugify_hostname`, `write_hostname`, `identity.get_device_uuid()` / `get_mac_address()`. A small `Product.pair`
hook and a bearer-provider hook in the platform would replace the module-attribute swap and the credential file.
The key is `/data/federation-key` (0600), outside the platform's wipe list. The Pi has no real-time clock, so
credentials use the server's time (above); a wrong or later-corrected Pi clock changes nothing. HTTPS itself still
needs the Pi's clock to be inside the server certificate's validity, which is outside this code's control: until it
is, the Pi can't reach the server at all and simply keeps retrying. The Ed25519 signer is dependency-free
(RFC 8032) because the OS image's virtualenv declares no crypto library; `cryptography` is used if installed.

## Errors

| Status | Meaning | Client does |
|---|---|---|
| `401` | unknown, revoked or badly signed (after a fresh signature) | forget the pairing, keep the key, return to pairing |
| `400` + `X-RI-Server-Time` | clock more than 5 minutes off | adopt `server_time - local_time` as an offset, repeat once; **never** treat as revocation |
| `419` | a genuine credential that is expired / from a wrong clock (credential auth only) | mint a new credential; never wipe |
| `403` | the trust lacks the needed grant (e.g. `print.share`) | stop that activity, surface the message |
| `429` | rate limited | back off |

## Grants

What a peer may do is a set of permission keys on its trust: `print.share`, `feedback.send`,
`feedback.receive`, `offers.send`, `offers.receive`, `inventory.query_exact`, `supplies.request`,
`supplies.query_available`, `operator.full_read`. Print servers need `print.share` for the job endpoints.
`GET /api/federation/v1/grants` returns the caller's current set.

## Backups (`operator.full_read`)

A `backup_client` (the RI Launcher) pairs like any client — a code made in the Trust Console, its own key, no location — and
holds `operator.full_read`. It then uses `/api/federation/v1/backups` (session token or signed request):

1. `POST /backups {"scope":"database"|"full"}` → `202 {backup}`; `409 {backup}` if one is already running (wait for that one).
2. Poll `GET /backups/{id}` until `status` is `complete` (or `failed`, with `error`). A complete one carries `sha256`, the hash of the
   whole zip.
3. `GET /backups/{id}/download` streams the zip and honours `Range`, so an interrupted download resumes. Verify the hash before
   keeping the file, and keep it: the server deletes its copy after `keep_days`.

The zip never contains `APP_KEY` (see `scripts/BACKUPS.md`), but a database backup does hold password hashes and personal data, which is
why this grant is rated high risk.

## Instance-level trust: whole-instance backups (`/api/instance/v1`, `instance.backup`)

Everything above is per **warehouse** (its trusts, grants and codes live in that warehouse's schema). One thing belongs to the whole
instance: a backup of every warehouse and the shared databases (`InstanceBackupService`). A computer that may take those is an
**instance trust** (`App\Models\Control\InstanceTrust`, control schema), made by an **instance administrator** on Instance Admin
(Full Instance Backups → *Pair a computer*), and it does not depend on any one warehouse. Its one permission is `instance.backup`
(`App\Support\InstancePermission`, deliberately not in the warehouse catalogue, so a Trust Console can never grant it).

It is the same protocol: the same Ed25519 keys, the same 10-character code (15 minutes, one use, only its hash stored), the same pairing
proof (`ri-pair-v1\n<code>\n<public key>\n<uuid>`), the same signed-request canonical string, clock window and one-use nonces
(`VerifiesSignedRequests`, shared with the warehouse middleware), and the same `RIS`-style sessions (here `RII-…`, from `POST /session`).
Only the table and the paths differ, and a warehouse trust is nothing here (nor the reverse; a test pins it):

| | |
|---|---|
| `POST /api/instance/v1/pair` | body as `federation/v1/pair` but `kind` must be `instance_backup` (no `base_url`); answers `{server, trust_id, permissions: ["instance.backup"]}` |
| `POST /api/instance/v1/session` | signed → `{session_token, expires_in}` |
| `GET /api/instance/v1/grants` | `{permissions}` |
| `GET\|POST /api/instance/v1/backups`, `GET /backups/{id}`, `GET /backups/{id}/download` | the same shapes as the warehouse backups (`Backup` in the OpenAPI file); `scope` is `database` or `full` (every warehouse's files) |

Ids: a request in flight is its small provisioning id; a finished zip is its 14-digit stamp (`YmdHis` of its file name,
`relief-instance-<stamp>-<database|full>[-label].zip`). Both reach the same file once the request is done, so the id returned when asking
still downloads. `POST /backups` writes the fixed `backup-now` / `backup-now-files` request for the provisioner (the web container cannot
make the zip: it needs the database administrator's password) and answers `202` with the request as a `queued` backup; `409` carries the one
already being made. A zip never holds a warehouse key. Served at the instance's address, through the default warehouse's front door like
`/api/instance/update`. 401 = unknown, revoked or badly signed (the Launcher un-pairs itself); revoking is immediate (live lookup, session epoch).
The Launcher pairs with `ri-launcher remote pair <address> <code> --instance` or the *Whole instance* choice in its pair form.

