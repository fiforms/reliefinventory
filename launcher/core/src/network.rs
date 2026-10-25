use crate::settings::{Mode, Settings};
use std::net::IpAddr;

/// What this computer calls itself and how it can be reached, gathered once and passed around so the
/// address logic below stays a pure function.
#[derive(Debug, Clone, PartialEq)]
pub struct Machine {
    pub computer_name: String,
    pub ips: Vec<IpAddr>,
}

impl Machine {
    pub fn detect() -> Machine {
        let computer_name = local_host_name().unwrap_or_else(|| gethostname::gethostname().to_string_lossy().to_string());
        let ips = if_addrs::get_if_addrs()
            .unwrap_or_default()
            .into_iter()
            .filter(|i| !i.is_loopback() && !is_virtual_adapter(&i.name))
            .filter_map(|i| match i.ip() {
                IpAddr::V4(v4) if !v4.is_link_local() => Some(IpAddr::V4(v4)),
                _ => None,
            })
            .collect();
        Machine { computer_name, ips }
    }

    /// `<computer name>.local` — resolves by mDNS on Windows 10+/macOS/iOS/most Android, without any
    /// router setup. Not guaranteed everywhere (Linux needs avahi), hence the IP fallbacks.
    pub fn default_hostname(&self) -> String {
        let name = self.computer_name.to_ascii_lowercase();
        // a Mac's host name is often already "name.local"
        let base: String = name
            .strip_suffix(".local")
            .unwrap_or(&name)
            .chars()
            .map(|c| if c.is_ascii_alphanumeric() { c } else { '-' })
            .collect();
        let base = base.trim_matches('-');
        format!("{}.local", if base.is_empty() { "ri-server" } else { base })
    }
}

/// The name other devices resolve over Bonjour (System Settings > General > Sharing > Local hostname).
/// The plain host name can be one the network handed out, or already end in `.local`.
#[cfg(target_os = "macos")]
fn local_host_name() -> Option<String> {
    let out = std::process::Command::new("/usr/sbin/scutil").args(["--get", "LocalHostName"]).output().ok()?;
    let name = String::from_utf8_lossy(&out.stdout).trim().to_string();
    (out.status.success() && !name.is_empty()).then_some(name)
}

#[cfg(not(target_os = "macos"))]
fn local_host_name() -> Option<String> {
    None
}

/// Hyper-V/WSL/Docker/VM adapters carry addresses no other device can reach.
fn is_virtual_adapter(name: &str) -> bool {
    let n = name.to_ascii_lowercase();
    let windows = ["vethernet", "wsl", "docker", "virtualbox", "vmware", "vmnet", "hyper-v", "loopback", "vboxnet", "br-", "veth"]
        .iter()
        .any(|p| n.contains(p));
    // macOS BSD names: VPN tunnels, bridges (Lima/vz VMs sit on bridge100), AirDrop/low-latency WLAN, the
    // Apple-silicon internal link, Wi-Fi hotspot, tunnels. Matched as prefix + unit number, so a Windows
    // adapter that merely starts with these letters is unaffected.
    let mac = ["utun", "bridge", "awdl", "llw", "anpi", "ap", "gif", "stf", "vmenet"].iter().any(|p| {
        n.strip_prefix(p).is_some_and(|unit| !unit.is_empty() && unit.chars().all(|c| c.is_ascii_digit()))
    });
    windows || mac
}

/// The address a person types, without scheme or port.
pub fn public_host(settings: &Settings, machine: &Machine) -> String {
    match settings.mode {
        Mode::Local => "localhost".into(),
        Mode::Lan => settings.hostname.clone().unwrap_or_else(|| machine.default_hostname()),
    }
}

fn with_port(host: &str, port: u16) -> String {
    if port == 80 {
        format!("http://{host}")
    } else {
        format!("http://{host}:{port}")
    }
}

/// The app's own `APP_URL` and the link "Copy shareable link" gives out.
pub fn app_url(settings: &Settings, machine: &Machine) -> String {
    with_port(&public_host(settings, machine), settings.port)
}

/// Every way another device could reach it: the name first, then each IP (for devices where `.local` fails).
/// Empty in local-only mode — there is nothing to share.
pub fn share_urls(settings: &Settings, machine: &Machine) -> Vec<String> {
    if settings.mode == Mode::Local {
        return vec![];
    }
    let mut urls = vec![app_url(settings, machine)];
    urls.extend(machine.ips.iter().map(|ip| with_port(&ip.to_string(), settings.port)));
    urls.dedup();
    urls
}

#[cfg(test)]
mod tests {
    use super::*;

    fn machine() -> Machine {
        Machine { computer_name: "WIN11_Machine".into(), ips: vec!["10.53.105.130".parse().unwrap()] }
    }

    #[test]
    fn default_name_is_dns_safe() {
        assert_eq!(machine().default_hostname(), "win11-machine.local");
        let m = Machine { computer_name: "***".into(), ips: vec![] };
        assert_eq!(m.default_hostname(), "ri-server.local");
        let m = Machine { computer_name: "Marks-MacBook-Pro.local".into(), ips: vec![] };
        assert_eq!(m.default_hostname(), "marks-macbook-pro.local");
    }

    #[test]
    fn virtual_adapters_are_skipped() {
        for v in ["vEthernet (WSL)", "utun3", "bridge100", "bridge0", "awdl0", "llw0", "anpi1", "ap1", "gif0", "stf0", "vmenet0"] {
            assert!(is_virtual_adapter(v), "{v}");
        }
        for real in ["en0", "en7", "Wi-Fi", "Ethernet", "Apple USB Ethernet", "apollo", "Bridgeport LAN"] {
            assert!(!is_virtual_adapter(real), "{real}");
        }
    }

    #[test]
    fn lan_uses_chosen_or_default_name() {
        let mut s = Settings::default();
        assert_eq!(app_url(&s, &machine()), "http://win11-machine.local:8080");
        s.hostname = Some("office.local".into());
        assert_eq!(app_url(&s, &machine()), "http://office.local:8080");
        assert_eq!(
            share_urls(&s, &machine()),
            vec!["http://office.local:8080", "http://10.53.105.130:8080"]
        );
    }

    #[test]
    fn local_mode_shares_nothing() {
        let s = Settings { mode: Mode::Local, ..Settings::default() };
        assert_eq!(app_url(&s, &machine()), "http://localhost:8080");
        assert!(share_urls(&s, &machine()).is_empty());
    }

    #[test]
    fn port_80_has_no_suffix() {
        let s = Settings { port: 80, ..Settings::default() };
        assert_eq!(app_url(&s, &machine()), "http://win11-machine.local");
    }

    #[test]
    fn ip_as_hostname_is_not_repeated() {
        let s = Settings { hostname: Some("10.53.105.130".into()), ..Settings::default() };
        assert_eq!(share_urls(&s, &machine()), vec!["http://10.53.105.130:8080"]);
    }
}
