use crate::network::{app_url, Machine};
use crate::platform::INTERNAL_PORT;
use crate::settings::Settings;

/// The stack definition shipped with the app, embedded so the launcher never depends on a file being
/// next to it. Only the lines in `render` are ever edited; everything else is used as written.
pub const TEMPLATE: &str = include_str!("../../../docker/compose.yaml");

#[derive(Debug, thiserror::Error)]
pub enum ComposeError {
    #[error("the bundled compose file no longer has the {0} line this launcher edits")]
    LineMissing(&'static str),
}

/// Produce the compose file for these settings: `APP_URL` becomes the address people type, the app is
/// published on the internal port (the proxy owns the public one), and an optional timezone is switched on.
pub fn render(template: &str, settings: &Settings, machine: &Machine) -> Result<String, ComposeError> {
    let url = app_url(settings, machine);
    let (mut saw_url, mut saw_port) = (false, false);
    let mut out = String::with_capacity(template.len() + 64);
    for line in template.lines() {
        let trimmed = line.trim_start();
        let indent = &line[..line.len() - trimmed.len()];
        if trimmed.starts_with("APP_URL:") {
            out.push_str(&format!("{indent}APP_URL: {url}\n"));
            saw_url = true;
        } else if trimmed == "- \"8080:8080\"" {
            out.push_str(&format!("{indent}- \"{INTERNAL_PORT}:8080\"\n"));
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
    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::settings::Mode;

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
    fn local_mode_url_and_timezone() {
        let s = Settings { mode: Mode::Local, timezone: Some("America/New_York".into()), ..Settings::default() };
        let out = render(TEMPLATE, &s, &machine()).unwrap();
        assert!(out.contains("APP_URL: http://localhost:8080\n"));
        assert!(out.contains("APP_TIMEZONE: America/New_York\n"));
        assert!(!out.contains("# APP_TIMEZONE"));
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
