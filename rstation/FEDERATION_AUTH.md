# Federation authentication — pairing, signed requests, sessions

How a client app (the desktop print client, an R Station) or another Relief Inventory instance proves who
it is. Machine-readable form: `rstation/federation.openapi.yaml`. Server design and the Trust Console are
described in `CLAUDE.md` ("Federation"); this file is what a **client implementer** needs. It replaces the
older bearer-token pairing in `rstation/platform/docs/DEVICE_CONTRACT.md` for this app.

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
   `print_client`, `rstation` or `instance`, `uuid` is the client's stable id (≤ 64 chars) and
   `signature` is the Ed25519 signature, by the key being submitted, over the UTF-8 string

       "ri-pair-v1\n" + CODE + "\n" + public_key + "\n" + uuid

   with CODE upper-cased and stripped of everything but letters and digits.
3. `201` returns the server's identity (`server.public_key` — pin it), the grants the trust starts with and,
   for a device, `device_id`, `entity_*`, `sibling_hostnames`, `language*`. There is **no token**.
4. Any failure of the code or the proof is one generic `422`; `429` means slow down.

Pairing again with the same `uuid` re-attaches the existing record; a different key is accepted only with a
fresh code and is recorded as a key change. Un-pairing on the client forgets the server, not the key.

## Signing a request

Send these headers: `X-RI-Trust` (the client's `uuid`), `X-RI-Timestamp` (unix seconds), `X-RI-Nonce`
(unique, 8–64 chars, never reused), `X-RI-Signature` — the signature over the UTF-8 string, lines joined
with `\n`:

    METHOD                      upper-case
    PATH?QUERY                  exactly as sent, e.g. /api/devices/print-jobs?x=1
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

## Errors

| Status | Meaning | Client does |
|---|---|---|
| `401` | unknown, revoked or badly signed (after a fresh signature) | forget the pairing, keep the key, return to pairing |
| `400` + `X-RI-Server-Time` | clock more than 5 minutes off | adopt `server_time - local_time` as an offset, repeat once; **never** treat as revocation |
| `403` | the trust lacks the needed grant (e.g. `print.share`) | stop that activity, surface the message |
| `429` | rate limited | back off |

## Grants

What a peer may do is a set of permission keys on its trust: `print.share`, `feedback.send`,
`feedback.receive`, `offers.send`, `offers.receive`, `inventory.query_exact`, `supplies.request`,
`supplies.query_available`, `operator.full_read`. Print servers need `print.share` for the job endpoints.
`GET /api/federation/v1/grants` returns the caller's current set.
