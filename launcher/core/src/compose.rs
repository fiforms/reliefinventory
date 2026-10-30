use crate::network::{app_url, Machine};
use crate::platform::INTERNAL_PORT;
use crate::settings::Settings;

/// The stack definition shipped with the app, embedded so the launcher never depends on a file being
/// next to it. Only the lines in `render` are ever edited; everything else is used as written.
pub const TEMPLATE: &str = include_str!("../../../docker/compose.yaml");

/// The app's `image:` line up to the tag; the tag is the release channel (`latest` or `edge`).
const APP_IMAGE_PREFIX: &str = "image: ghcr.io/fiforms/reliefinventory:";

#[derive(Debug, thiserror::Error)]
pub enum ComposeError {
    #[error("the bundled compose file no longer has the {0} line this launcher edits")]
    LineMissing(&'static str),
}

/// Produce the compose file for these settings: the app image follows the release channel, `APP_URL` becomes the address people type, the app is
/// published on the internal port (the proxy owns the public one), and an optional timezone is switched on.
pub fn render(template: &str, settings: &Settings, machine: &Machine) -> Result<String, ComposeError> {
    render_for(template, settings, machine, &StackOptions::default())
}

/// Differences for a backend that runs Docker on the host itself instead of in a private Linux layer.
#[derive(Debug, Clone, Default, PartialEq)]
pub struct StackOptions {
    /// Publish the internal port on 127.0.0.1 only: a bare port mapping would be reachable from the whole
    /// network and bypass the Local-only setting.
    pub loopback_only: bool,
    /// Compose project name written into the file's `name:` line. The shipped name is the folder-style
    /// default, which another stack on the same Docker (a developer's own checkout) can already own — and then
    /// a plain `docker compose exec` from our folder would act on *their* containers.
    pub project_name: Option<String>,
}

pub fn render_for(template: &str, settings: &Settings, machine: &Machine, opts: &StackOptions) -> Result<String, ComposeError> {
    let bind = if opts.loopback_only { "127.0.0.1:" } else { "" };
    let mut saw_name = false;
    let url = app_url(settings, machine);
    let (mut saw_url, mut saw_port, mut saw_image) = (false, false, false);
    let mut out = String::with_capacity(template.len() + 64);
    for line in template.lines() {
        let trimmed = line.trim_start();
        let indent = &line[..line.len() - trimmed.len()];
        if let (Some(name), true) = (&opts.project_name, line.starts_with("name:")) {
            out.push_str(&format!("name: {name}\n"));
            saw_name = true;
        } else if trimmed.starts_with("APP_URL:") {
            out.push_str(&format!("{indent}APP_URL: {url}\n"));
            saw_url = true;
        } else if trimmed.starts_with(APP_IMAGE_PREFIX) {
            out.push_str(&format!("{indent}{APP_IMAGE_PREFIX}{}\n", settings.channel.tag()));
            saw_image = true;
        } else if trimmed == "- \"8080:8080\"" {
            out.push_str(&format!("{indent}- \"{bind}{INTERNAL_PORT}:8080\"\n"));
            saw_port = true;
        } else if let (Some(tz), true) = (&settings.timezone, trimmed.starts_with("# APP_TIMEZONE:")) {
            out.push_str(&format!("{indent}APP_TIMEZONE: {tz}\n"));
        } else {
            out.push_str(line);
            out.push('\n');
        }
    }
    if !saw_url {
        return Err(ComposeError::LineMissing("APP_URL"));
    }
    if !saw_port {
        return Err(ComposeError::LineMissing("\"8080:8080\" port"));
    }
    if !saw_image {
        return Err(ComposeError::LineMissing("app image"));
    }
    if opts.project_name.is_some() && !saw_name {
        return Err(ComposeError::LineMissing("top-level name:"));
    }
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::settings::{Channel, Mode};

    fn machine() -> Machine {
        Machine { computer_name: "office".into(), ips: vec![] }
    }

    #[test]
    fn bundled_template_still_renders() {
        // Fails if someone edits docker/compose.yaml in a way the launcher can no longer patch.
        let out = render(TEMPLATE, &Settings::default(), &machine()).unwrap();
        assert!(out.contains("APP_URL: http://office.local:8080\n"));
        assert!(out.contains("- \"18080:8080\""));
        assert!(!out.contains("- \"8080:8080\""));
        assert!(out.contains("- \"8081:8080\"")); // Reverb's mapping is left alone
    }

    #[test]
    fn loopback_only_binds_the_internal_port_to_127_0_0_1() {
        let opts = StackOptions { loopback_only: true, project_name: None };
        let out = render_for(TEMPLATE, &Settings::default(), &machine(), &opts).unwrap();
        assert!(out.contains("- \"127.0.0.1:18080:8080\""));
        assert!(out.contains("- \"8081:8080\""));
        assert!(out.contains("\nname: reliefinventory\n")); // untouched unless asked
    }

    #[test]
    fn a_project_name_replaces_the_shipped_one() {
        let opts = StackOptions { loopback_only: false, project_name: Some("ri-launcher".into()) };
        let out = render_for(TEMPLATE, &Settings::default(), &machine(), &opts).unwrap();
        assert!(out.contains("\nname: ri-launcher\n"));
        assert!(!out.contains("name: reliefinventory"));
        let no_name = TEMPLATE.replace("\nname: reliefinventory\n", "\n");
        assert!(render_for(&no_name, &Settings::default(), &machine(), &opts).is_err());
    }

    #[test]
    fn local_mode_url_and_timezone() {
        let s = Settings { mode: Mode::Local, timezone: Some("America/New_York".into()), ..Settings::default() };
        let out = render(TEMPLATE, &s, &machine()).unwrap();
        assert!(out.contains("APP_URL: http://localhost:8080\n"));
        assert!(out.contains("APP_TIMEZONE: America/New_York\n"));
        assert!(!out.contains("# APP_TIMEZONE"));
    }

    #[test]
    fn the_channel_sets_the_app_image_tag_only() {
        let latest = render(TEMPLATE, &Settings::default(), &machine()).unwrap();
        assert!(latest.contains("image: ghcr.io/fiforms/reliefinventory:latest\n"));
        let s = Settings { channel: Channel::Edge, ..Settings::default() };
        let edge = render(TEMPLATE, &s, &machine()).unwrap();
        assert!(edge.contains("  image: ghcr.io/fiforms/reliefinventory:edge\n"));
        assert!(!edge.contains("reliefinventory:latest\n"));
        assert!(edge.contains("image: mariadb:11"));
    }

    #[test]
    fn untouched_lines_are_preserved() {
        let out = render(TEMPLATE, &Settings::default(), &machine()).unwrap();
        let strip = |s: &str| s.lines().filter(|l| !l.contains("APP_URL:") && !l.contains("8080:8080")).count();
        assert_eq!(strip(TEMPLATE), strip(&out));
    }

    #[test]
    fn missing_lines_are_an_error() {
        assert!(render("services: {}\n", &Settings::default(), &machine()).is_err());
    }
}
