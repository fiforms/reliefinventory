//! Relief Inventory print server client (core). The Tauri desktop shell and the headless
//! `ri-print` CLI are both thin wrappers over [`engine::Engine`].
//!
//! Protocol: `rstation/PRINT_SERVER.md`, `rstation/PRINT_CLIENT_SPEC.md`,
//! `rstation/print-extension.openapi.yaml`.

pub mod api;
pub mod backend;
pub mod config;
pub mod engine;
pub mod ledger;
pub mod realtime;
pub mod types;

pub use engine::{Engine, Options, Status};
