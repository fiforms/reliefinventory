// The version shown in the window (RI_BUILD_VERSION): the release tag, or the commit hash when the build isn't of a tag.
// CI passes it in RI_BUILD_VERSION (the tag name, or the commit sha); a local build asks git; failing that it is the
// Cargo.toml version. The package version itself stays a plain number (installers need one, and it isn't a release tag).
use std::process::Command;

fn main() {
    println!("cargo:rerun-if-env-changed=RI_BUILD_VERSION");
    println!("cargo:rerun-if-changed=../../.git/HEAD");
    println!("cargo:rerun-if-changed=../../.git/refs/tags");
    let version = std::env::var("RI_BUILD_VERSION")
        .ok()
        .filter(|v| !v.trim().is_empty())
        .or_else(git_describe)
        .unwrap_or_else(|| std::env::var("CARGO_PKG_VERSION").unwrap_or_default());
    println!("cargo:rustc-env=RI_BUILD_VERSION={}", shorten(version.trim()));
    tauri_build::build()
}

/// `v1.2.0` on a tagged commit, otherwise the short hash; `-dirty` when the tree has uncommitted changes.
fn git_describe() -> Option<String> {
    let out = Command::new("git").args(["describe", "--tags", "--exact-match", "--dirty"]).output().ok().filter(|o| o.status.success()).or_else(|| {
        Command::new("git").args(["describe", "--always", "--dirty", "--abbrev=7"]).output().ok().filter(|o| o.status.success())
    })?;
    Some(String::from_utf8_lossy(&out.stdout).trim().to_string())
}

/// A full 40-character commit sha is shortened to 7; anything else (a tag, `abc1234-dirty`) is shown as is.
fn shorten(v: &str) -> String {
    if v.len() == 40 && v.chars().all(|c| c.is_ascii_hexdigit()) {
        v[..7].to_string()
    } else {
        v.to_string()
    }
}
