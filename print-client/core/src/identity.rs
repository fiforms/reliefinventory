//! This client's Ed25519 identity and the federation request-signing format
//! (`App\Services\Federation\RequestSigner` on the server; `tests/Fixtures/federation-signing-vectors.json`
//! is the shared check that both produce byte-identical canonical strings and signatures).
//!
//! The private key is a 32-byte seed kept in `config.json` (see `config.rs`); the public key is what the
//! server pins when the device is paired. Keys and signatures travel as base64url without padding.

use base64::engine::general_purpose::URL_SAFE_NO_PAD;
use base64::Engine as _;
use rand::RngCore;
use ring::digest::{digest, SHA256};
use ring::signature::{Ed25519KeyPair, KeyPair, UnparsedPublicKey, ED25519};

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

/// What a device signs to prove it holds the key it submits when pairing.
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

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::Value;

    /// The same vectors the PHP server's test reproduces (tests/Fixtures/federation-signing-vectors.json).
    #[test]
    fn matches_the_shared_signing_vectors() {
        let fixture: Value =
            serde_json::from_str(include_str!("../../../tests/Fixtures/federation-signing-vectors.json")).unwrap();
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
    fn generated_seeds_make_distinct_working_keys() {
        let (a, b) = (generate_seed(), generate_seed());
        assert_ne!(a, b);
        let id = Identity::from_seed(&a).unwrap();
        assert!(verify("m", &id.sign("m"), &id.public_key()));
        assert!(Identity::from_seed("not-a-seed").is_none());
    }

    #[test]
    fn pairing_message_ignores_code_formatting() {
        assert_eq!(pairing_message("abcde-fghjk", "PK", "u"), "ri-pair-v1\nABCDEFGHJK\nPK\nu");
    }
}
