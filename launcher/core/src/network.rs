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
        let computer_name = gethostname::gethostname().to_string_lossy().to_string();
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
        let base: String = self
            .computer_name
            .to_ascii_lowercase()
            .chars()
            .map(|c| if c.is_ascii_alphanumeric() { c } else { '-' })
            .collect();
        let base = base.trim_matches('-');
        format!("{}.local", if base.is_empty() { "ri-server" } else { base })
    }
}

/// Hyper-V/WSL/Docker/VM adapters carry addresses no other device can reach.
fn is_virtual_adapter(name: &str) -> bool {
    let n = name.to_ascii_lowercase();
    ["vethernet", "wsl", "docker", "virtualbox", "vmware", "vmnet", "hyper-v", "loopback", "vboxnet", "br-", "veth"]
        .iter()
        .any(|p| n.contains(p))
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
