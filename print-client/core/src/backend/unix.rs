//! macOS and Linux: CUPS through its command-line tools (`lp`, `lpstat`, `cancel`). These ship with
//! both OSes, so there's nothing to bundle.
//!
//! Known limitation (see `PRINT_CLIENT_SPEC.md` §2): `lpstat` can't tell a completed job from an
//! aborted one — both just leave the not-completed list — so a job that CUPS aborts (e.g. a broken
//! filter) can be reported as printed. We mitigate by also looking at the printer's state when the
//! job disappears. The exact fix is to speak IPP to localhost:631 like the Pi client does
//! (`rstation/product/backend/ipp.py`); `PrintBackend` is the seam for that.

use std::time::Duration;

use async_trait::async_trait;

use super::*;
use crate::types::{clip, PrinterState};

const TIMEOUT: Duration = Duration::from_secs(20);

#[derive(Clone)]
pub struct UnixBackend {
    lp: String,
    lpstat: String,
    cancel: String,
}

impl Default for UnixBackend {
    fn default() -> Self {
        UnixBackend { lp: "lp".into(), lpstat: "lpstat".into(), cancel: "cancel".into() }
    }
}

impl UnixBackend {
    /// Use tools from a specific directory (tests point this at fake scripts).
    pub fn with_tool_dir(dir: &std::path::Path) -> Self {
        let p = |n: &str| dir.join(n).to_string_lossy().into_owned();
        UnixBackend { lp: p("lp"), lpstat: p("lpstat"), cancel: p("cancel") }
    }
}

// printer-state-reasons keywords (suffix stripped) that mean a person must act.
const PROBLEMS: &[(&str, &str)] = &[
    ("media-empty", "Out of paper or labels"),
    ("media-needed", "Needs paper or labels"),
    ("media-jam", "Paper or label jam"),
    ("toner-empty", "Out of toner"),
    ("marker-supply-empty", "Out of toner or ink"),
    ("door-open", "Cover open"),
    ("cover-open", "Cover open"),
    ("interlock-open", "Cover open"),
    ("output-area-full", "Output tray full"),
    ("output-tray-missing", "Output tray missing"),
];
const OFFLINE_REASONS: &[&str] = &["offline", "timed-out", "shutdown"];

// What a CUPS network backend says while a job cannot reach its printer. The queue stays
// "processing" and `lpstat` calls it idle; only this free-text message gives it away, and only
// while a job is being attempted. Matches `cups_client.py`; a message not on the list is treated
// as healthy, so a wrong guess can only miss a problem, never cry wolf.
const UNREACHABLE: &[&str] = &[
    "may not exist or is unavailable",
    "unable to locate printer",
    "printer is not responding",
    "unable to connect",
    "unable to open",
    "waiting for printer to become available",
];

fn strip_suffix(reason: &str) -> &str {
    for s in ["-error", "-warning", "-report"] {
        if let Some(r) = reason.strip_suffix(s) {
            return r;
        }
    }
    reason
}

struct Block {
    name: String,
    header: String,
    messages: Vec<String>,
    alerts: Vec<String>,
}

fn blocks(lpstat_l_p: &str) -> Vec<Block> {
    let mut out: Vec<Block> = Vec::new();
    let mut in_message = true;
    for line in lpstat_l_p.lines() {
        if let Some(rest) = line.strip_prefix("printer ") {
            let name = rest.split_whitespace().next().unwrap_or("").to_string();
            out.push(Block { name, header: rest.to_string(), messages: vec![], alerts: vec![] });
            in_message = true;
        } else if line.starts_with([' ', '\t']) {
            let Some(b) = out.last_mut() else { continue };
            let t = line.trim();
            if t.starts_with("Form mounted:") {
                in_message = false;
            } else if let Some(a) = t.strip_prefix("Alerts:") {
                b.alerts = a.split_whitespace().filter(|w| *w != "none").map(str::to_string).collect();
            } else if in_message && !t.is_empty() && t != "reason unknown" {
                b.messages.push(t.to_string());
            }
        }
    }
    out
}

/// `lpstat -l -p` + `lpstat -a` → the heartbeat's printer list.
pub fn parse_lpstat(long_printers: &str, accepting: &str) -> Vec<PrinterInfo> {
    let rejecting: Vec<&str> = accepting
        .lines()
        .filter(|l| !l.starts_with([' ', '\t']))
        .filter(|l| l.contains(" not accepting requests"))
        .filter_map(|l| l.split_whitespace().next())
        .collect();

    blocks(long_printers)
        .into_iter()
        .map(|b| {
            let message = b.messages.join(" ");
            let (state, msg): (PrinterState, Option<String>) = if rejecting.contains(&b.name.as_str()) {
                (PrinterState::Offline, Some("Printer is not accepting jobs".into()))
            } else if b.header.contains(" disabled since") {
                (PrinterState::Offline, Some("Printer paused".into()))
            } else if let Some((_, text)) = b
                .alerts
                .iter()
                .filter_map(|a| {
                    let base = strip_suffix(a);
                    PROBLEMS.iter().find(|(k, _)| *k == base)
                })
                .map(|(k, t)| (*k, t.to_string()))
                .next()
            {
                (PrinterState::Error, Some(text))
            } else if b.alerts.iter().any(|a| OFFLINE_REASONS.contains(&strip_suffix(a))) {
                (PrinterState::Offline, Some("Printer offline".into()))
            } else if b.header.contains(" now printing") {
                let low = message.to_lowercase();
                if UNREACHABLE.iter().any(|m| low.contains(m)) {
                    (PrinterState::Offline, Some(clip(&format!("Printer not reachable: {message}"), 500)))
                } else {
                    (PrinterState::Printing, None)
                }
            } else {
                (PrinterState::Idle, None)
            };
            PrinterInfo { queue_name: b.name, state, state_message: msg }
        })
        .collect()
}

/// `request id is Zebra-12 (1 file(s))` → `Zebra-12`.
pub fn parse_lp_output(out: &str) -> Option<String> {
    let rest = out.split("request id is ").nth(1)?;
    rest.split_whitespace().next().map(str::to_string)
}

/// Is `job` one of the entries of `lpstat -o` output (`Zebra-12 user 1024 date`)?
pub fn lists_job(lpstat_o: &str, job: &str) -> bool {
    lpstat_o.lines().any(|l| l.split_whitespace().next() == Some(job))
}

#[async_trait]
impl PrintBackend for UnixBackend {
    fn platform(&self) -> &'static str {
        if cfg!(target_os = "macos") {
            "macos"
        } else {
            "linux"
        }
    }

    async fn list_printers(&self) -> Result<Vec<PrinterInfo>, BackendError> {
        let (ok, out, err) = run_tool(&self.lpstat, &["-l", "-p"], TIMEOUT).await?;
        if !ok {
            if (out.clone() + &err).contains("No destinations added") {
                return Ok(vec![]);
            }
            return Err(BackendError::new(format!(
                "Can't read the printer list — is the print service (CUPS) running? ({})",
                clip(err.trim(), 200)
            )));
        }
        // -a only supplements; a failure there must not hide the printers.
        let accepting = run_tool(&self.lpstat, &["-a"], TIMEOUT).await.map(|r| r.1).unwrap_or_default();
        Ok(parse_lpstat(&out, &accepting))
    }

    async fn submit(&self, req: &SubmitRequest) -> Result<OsJob, BackendError> {
        let copies = req.copies.max(1).to_string();
        let title = clip(&req.title.replace('\n', " "), 80);
        let file = req.file.to_string_lossy().into_owned();
        // print-scaling=none: actual size, no fit-to-page (the PDF is already the media's size).
        let args = ["-d", &req.queue_name, "-n", &copies, "-t", &title, "-o", "print-scaling=none", "--", &file];
        let (ok, out, err) = run_tool(&self.lp, &args, TIMEOUT).await?;
        if !ok {
            return Err(BackendError::new(format!(
                "The system refused the print job for '{}': {}",
                req.queue_name,
                clip(err.trim(), 300)
            )));
        }
        parse_lp_output(&out)
            .map(OsJob)
            .ok_or_else(|| BackendError::new("The system accepted the job but didn't say which one it was"))
    }

    async fn job_status(&self, queue_name: &str, job: &OsJob) -> Result<OsJobStatus, BackendError> {
        let (_, out, _) = run_tool(&self.lpstat, &["-W", "not-completed", "-o", queue_name], TIMEOUT).await?;
        if lists_job(&out, &job.0) {
            return Ok(OsJobStatus::Pending);
        }
        // The job left the queue. lpstat can't say whether it finished or aborted; if the printer
        // is now showing a fault, believe the printer.
        if let Ok(printers) = self.list_printers().await {
            if let Some(p) = printers.iter().find(|p| p.queue_name == queue_name) {
                if p.state == PrinterState::Error {
                    return Ok(OsJobStatus::Failed(format!(
                        "'{queue_name}' reports a problem: {}",
                        p.state_message.clone().unwrap_or_default()
                    )));
                }
            }
        }
        Ok(OsJobStatus::Done)
    }

    async fn cancel(&self, _queue_name: &str, job: &OsJob) {
        let _ = run_tool(&self.cancel, &[&job.0], TIMEOUT).await;
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const IDLE: &str = "printer HP-M426 is idle.  enabled since Thu May 14 15:23:14 2026\n\tForm mounted:\n\tContent types: any\n\tAlerts: none\n\tConnection: direct\n";
    const PRINTING_DEAD: &str = "printer Net is now printing Net-3.  enabled since Sat Oct 17 17:15:42 2026\n\tThe printer may not exist or is unavailable at this time.\n\tForm mounted:\n\tAlerts: connecting-to-device\n";
    const PAUSED: &str = "printer Zebra disabled since Sat Oct 17 17:15:42 2026 -\n\treason unknown\n\tForm mounted:\n\tAlerts: paused\n";
    const NOLABELS: &str = "printer Label is idle.  enabled since Sat Oct 17 17:15:42 2026\n\tForm mounted:\n\tAlerts: media-empty-error toner-low-warning\n";

    #[test]
    fn idle_printer() {
        let p = parse_lpstat(IDLE, "HP-M426 accepting requests since Thu May 14 15:23:14 2026\n");
        assert_eq!(p, vec![PrinterInfo { queue_name: "HP-M426".into(), state: PrinterState::Idle, state_message: None }]);
    }

    #[test]
    fn paused_is_offline() {
        let p = parse_lpstat(PAUSED, "");
        assert_eq!(p[0].state, PrinterState::Offline);
        assert_eq!(p[0].state_message.as_deref(), Some("Printer paused"));
    }

    #[test]
    fn rejecting_jobs_is_offline() {
        let p = parse_lpstat(IDLE, "HP-M426 not accepting requests since Thu May 14 15:23:14 2026 -\n\treason\n");
        assert_eq!(p[0].state, PrinterState::Offline);
        assert_eq!(p[0].state_message.as_deref(), Some("Printer is not accepting jobs"));
    }

    #[test]
    fn unreachable_network_printer_only_visible_while_processing() {
        let p = parse_lpstat(PRINTING_DEAD, "");
        assert_eq!(p[0].state, PrinterState::Offline);
        assert!(p[0].state_message.as_deref().unwrap().starts_with("Printer not reachable"));
        // same message on an idle printer is stale and must not alarm anyone
        let stale = PRINTING_DEAD.replace("now printing Net-3.", "is idle.");
        assert_eq!(parse_lpstat(&stale, "")[0].state, PrinterState::Idle);
    }

    #[test]
    fn out_of_labels_is_error_but_toner_low_is_not() {
        let p = parse_lpstat(NOLABELS, "");
        assert_eq!(p[0].state, PrinterState::Error);
        assert_eq!(p[0].state_message.as_deref(), Some("Out of paper or labels"));
        let only_low = NOLABELS.replace("media-empty-error ", "");
        assert_eq!(parse_lpstat(&only_low, "")[0].state, PrinterState::Idle);
    }

    #[test]
    fn several_printers() {
        let both = format!("{IDLE}{NOLABELS}");
        let p = parse_lpstat(&both, "");
        assert_eq!(p.iter().map(|x| x.queue_name.as_str()).collect::<Vec<_>>(), ["HP-M426", "Label"]);
    }

    #[test]
    fn lp_output() {
        assert_eq!(parse_lp_output("request id is Zebra-12 (1 file(s))\n").as_deref(), Some("Zebra-12"));
        assert_eq!(parse_lp_output("nonsense"), None);
    }

    #[test]
    fn job_listing_matches_whole_id() {
        let o = "Zebra-12   daniel   1024   Sat Oct 17 17:15:42 2026\n";
        assert!(lists_job(o, "Zebra-12"));
        assert!(!lists_job(o, "Zebra-1"));
    }
}
