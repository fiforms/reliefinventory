//! This launcher's Ed25519 identity and the federation request-signing format (`App\Services\Federation\RequestSigner`
//! on the server; `rstation/FEDERATION_AUTH.md` is the spec). `tests/Fixtures/federation-signing-vectors.json` is the
//! shared check that every implementation — PHP, the print client, the Pi, this — signs byte for byte alike. The format
//! is small and fixed, so this is a copy of `print-client/core/src/identity.rs` rather than a shared crate; keep the two
//! in step through that fixture.
//!
//! The private key is a 32-byte seed in `federation-identity.json` (0600 on Unix), made on first use and kept across
//! un-pairing, with a stable uuid. Keys and signatures travel as base64url without padding.

use std::path::{Path, PathBuf};

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine as _;
use rand::RngCore;
use ring::digest::{digest, SHA256};
use ring::signature::{Ed25519KeyPair, KeyPair, UnparsedPublicKey, ED25519};
use serde::{Deserialize, Serialize};

pub fn b64(raw: &[u8]) -> String {
    URL_SAFE_NO_PAD.encode(raw)
}

pub fn unb64(text: &str) -> Option<Vec<u8>> {
    URL_SAFE_NO_PAD.decode(text.trim_end_matches('=')).ok()
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|b| format!("{b:02x}")).collect()
}

/// A fresh random 32-byte seed, base64url.
pub fn generate_seed() -> String {
    let mut seed = [0u8; 32];
    rand::rngs::OsRng.fill_bytes(&mut seed);
    b64(&seed)
}

/// A random version-4 uuid, the stable id the server knows this launcher by.
pub fn random_uuid() -> String {
    let mut b = [0u8; 16];
    rand::rngs::OsRng.fill_bytes(&mut b);
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    let h = hex(&b);
    format!("{}-{}-{}-{}-{}", &h[0..8], &h[8..12], &h[12..16], &h[16..20], &h[20..32])
}

/// The string signed for one request: `METHOD \n PATH?QUERY \n HOST \n TIMESTAMP \n NONCE \n SHA256-hex(body)`.
/// `host` is lower-cased here; it includes a non-default port, as the server sees the Host header.
pub fn canonical(method: &str, path_and_query: &str, host: &str, timestamp: i64, nonce: &str, body: &[u8]) -> String {
    format!(
        "{}\n{}\n{}\n{}\n{}\n{}",
        method.to_ascii_uppercase(),
        path_and_query,
        host.to_ascii_lowercase(),
        timestamp,
        nonce,
        hex(digest(&SHA256, body).as_ref())
    )
}

/// What a client signs to prove it holds the key it submits when pairing.
pub fn pairing_message(code: &str, public_key: &str, uuid: &str) -> String {
    let code: String = code.chars().filter(|c| c.is_ascii_alphanumeric()).collect::<String>().to_ascii_uppercase();
    format!("ri-pair-v1\n{code}\n{public_key}\n{uuid}")
}

/// Short digest of a public key for a person to compare ("ABCD-EFGH-IJKL-MNOP").
pub fn fingerprint(public_key: &str) -> Option<String> {
    let raw = unb64(public_key)?;
    let h = hex(digest(&SHA256, &raw).as_ref()).to_ascii_uppercase();
    Some(h[..16].as_bytes().chunks(4).map(|c| std::str::from_utf8(c).unwrap()).collect::<Vec<_>>().join("-"))
}

pub fn verify(message: &str, signature: &str, public_key: &str) -> bool {
    match (unb64(signature), unb64(public_key)) {
        (Some(sig), Some(pk)) => UnparsedPublicKey::new(&ED25519, pk).verify(message.as_bytes(), &sig).is_ok(),
        _ => false,
    }
}

pub struct Identity {
    pair: Ed25519KeyPair,
}

impl Identity {
    /// `None` if the seed isn't a valid base64url 32-byte value.
    pub fn from_seed(seed_b64: &str) -> Option<Identity> {
        let seed = unb64(seed_b64)?;
        Ed25519KeyPair::from_seed_unchecked(&seed).ok().map(|pair| Identity { pair })
    }

    pub fn public_key(&self) -> String {
        b64(self.pair.public_key().as_ref())
    }

    pub fn sign(&self, message: &str) -> String {
        b64(self.pair.sign(message.as_bytes()).as_ref())
    }
}

/// What `federation-identity.json` holds.
#[derive(Serialize, Deserialize)]
struct Stored {
    uuid: String,
    seed: String,
}

/// This launcher's key and id: loaded from `dir`, or made and saved there the first time.
pub struct Keys {
    pub uuid: String,
    pub identity: Identity,
}

pub fn identity_path(dir: &Path) -> PathBuf {
    dir.join("federation-identity.json")
}

impl Keys {
    pub fn load_or_create(dir: &Path) -> std::io::Result<Keys> {
        if let Some(stored) = std::fs::read(identity_path(dir)).ok().and_then(|b| serde_json::from_slice::<Stored>(&b).ok()) {
            if let Some(identity) = Identity::from_seed(&stored.seed) {
                return Ok(Keys { uuid: stored.uuid, identity });
            }
        }
        // A missing file is a first run. An unreadable one is replaced too: the key is only ever used to pair, so
        // the worst outcome is pairing again, and refusing to start would be worse.
        let stored = Stored { uuid: random_uuid(), seed: generate_seed() };
        write_private(&identity_path(dir), &serde_json::to_vec_pretty(&stored)?)?;
        let identity = Identity::from_seed(&stored.seed).expect("a generated seed is valid");
        Ok(Keys { uuid: stored.uuid, identity })
    }
}

/// Write a file only its owner can read, atomically.
pub(crate) fn write_private(path: &Path, bytes: &[u8]) -> std::io::Result<()> {
    if let Some(parent) = path.parent() {
        std::fs::create_dir_all(parent)?;
    }
    let tmp = path.with_extension("tmp");
    {
        use std::io::Write;
        let mut opts = std::fs::OpenOptions::new();
        opts.write(true).create(true).truncate(true);
        #[cfg(unix)]
        {
            use std::os::unix::fs::OpenOptionsExt;
            opts.mode(0o600);
        }
        let mut f = opts.open(&tmp)?;
        f.write_all(bytes)?;
        f.sync_all()?;
    }
    std::fs::rename(tmp, path)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::Value;

    /// The same vectors the PHP server's test reproduces (tests/Fixtures/federation-signing-vectors.json).
    #[test]
    fn matches_the_shared_signing_vectors() {
        let fixture: Value =
            serde_json::from_str(include_str!("../../../../tests/Fixtures/federation-signing-vectors.json")).unwrap();
        let id = Identity::from_seed(fixture["seed_b64"].as_str().unwrap()).unwrap();
        assert_eq!(id.public_key(), fixture["public_key"].as_str().unwrap());
        assert_eq!(fingerprint(&id.public_key()).unwrap(), fixture["fingerprint"].as_str().unwrap());
        for v in fixture["vectors"].as_array().unwrap() {
            let c = canonical(
                v["method"].as_str().unwrap(),
                v["path_and_query"].as_str().unwrap(),
                v["host"].as_str().unwrap(),
                v["timestamp"].as_i64().unwrap(),
                v["nonce"].as_str().unwrap(),
                v["body"].as_str().unwrap().as_bytes(),
            );
            assert_eq!(c, v["canonical"].as_str().unwrap());
            let sig = id.sign(&c);
            assert_eq!(sig, v["signature"].as_str().unwrap());
            assert!(verify(&c, &sig, &id.public_key()));
            assert!(!verify(&format!("{c}x"), &sig, &id.public_key()));
        }
    }

    #[test]
    fn pairing_message_ignores_code_formatting() {
        assert_eq!(pairing_message("abcde-fghjk", "PK", "u"), "ri-pair-v1\nABCDEFGHJK\nPK\nu");
    }

    #[test]
    fn keys_are_made_once_and_kept() {
        let d = tempfile::tempdir().unwrap();
        let first = Keys::load_or_create(d.path()).unwrap();
        let second = Keys::load_or_create(d.path()).unwrap();
        assert_eq!((first.uuid.as_str(), first.identity.public_key()), (second.uuid.as_str(), second.identity.public_key()));
        assert_eq!(first.uuid.len(), 36);
        assert_eq!(first.uuid.as_bytes()[14], b'4');
        #[cfg(unix)]
        {
            use std::os::unix::fs::PermissionsExt;
            assert_eq!(std::fs::metadata(identity_path(d.path())).unwrap().permissions().mode() & 0o777, 0o600);
        }
    }

    #[test]
    fn a_corrupt_identity_file_is_replaced_not_fatal() {
        let d = tempfile::tempdir().unwrap();
        std::fs::write(identity_path(d.path()), b"{nope").unwrap();
        let k = Keys::load_or_create(d.path()).unwrap();
        assert!(verify("m", &k.identity.sign("m"), &k.identity.public_key()));
    }
}
