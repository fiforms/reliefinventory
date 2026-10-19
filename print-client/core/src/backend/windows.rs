//! Windows: printer list and job tracking via PowerShell (`Get-Printer`, `Get-PrintJob`), printing
//! via SumatraPDF (`-print-settings "noscale,Nx"` = actual size, N copies, no dialog).
//!
//! This module compiles on every OS so its parsers are unit-tested everywhere, but it has only been
//! exercised against captured/sample PowerShell output — **not yet on a real Windows machine**
//! (see `PRINT_CLIENT_SPEC.md` §4). SumatraPDF is GPLv3: ship its license and a source link.

use std::path::PathBuf;
use std::time::Duration;

use async_trait::async_trait;
use serde::Deserialize;

use super::*;
use crate::types::{clip, PrinterState};

const PS_TIMEOUT: Duration = Duration::from_secs(30);
const PRINT_TIMEOUT: Duration = Duration::from_secs(120);

pub struct WindowsBackend {
    sumatra: PathBuf,
}

impl WindowsBackend {
    pub fn new(sumatra: Option<PathBuf>) -> Self {
        WindowsBackend { sumatra: sumatra.unwrap_or_else(|| PathBuf::from("SumatraPDF.exe")) }
    }
}

#[derive(Deserialize)]
#[serde(rename_all = "PascalCase")]
struct PsPrinter {
    name: String,
    #[serde(default)]
    status: Option<String>,
    #[serde(default)]
    work_offline: Option<bool>,
}

#[derive(Deserialize)]
#[serde(rename_all = "PascalCase")]
struct PsJob {
    id: i64,
    #[serde(default)]
    status: Option<String>,
}

/// PowerShell's `ConvertTo-Json` emits a bare object for one item and an array for several.
fn one_or_many<T: serde::de::DeserializeOwned>(json: &str) -> Result<Vec<T>, BackendError> {
    let t = json.trim().trim_start_matches('\u{feff}');
    if t.is_empty() {
        return Ok(vec![]);
    }
    let v: serde_json::Value =
        serde_json::from_str(t).map_err(|e| BackendError::new(format!("Unreadable printer list from Windows: {e}")))?;
    let items = match v {
        serde_json::Value::Array(a) => a,
        other => vec![other],
    };
    items
        .into_iter()
        .map(|i| serde_json::from_value(i).map_err(|e| BackendError::new(format!("Unexpected printer data: {e}"))))
        .collect()
}

/// `Get-Printer` status (the `PrinterStatus` enum name) → our state and a message for staff.
fn classify(status: &str, work_offline: bool) -> (PrinterState, Option<String>) {
    if work_offline {
        return (PrinterState::Offline, Some("Printer offline".into()));
    }
    use PrinterState::*;
    let (s, m): (PrinterState, Option<&str>) = match status {
        "Printing" | "Processing" | "IOActive" | "Busy" => (Printing, None),
        "Paused" => (Offline, Some("Printer paused")),
        "Offline" | "NotAvailable" | "ServerUnknown" | "PendingDeletion" => (Offline, Some("Printer offline")),
        "PaperOut" => (Error, Some("Out of paper or labels")),
        "PaperJam" => (Error, Some("Paper or label jam")),
        "PaperProblem" | "ManualFeed" => (Error, Some("Paper problem — check the paper or labels")),
        "NoToner" => (Error, Some("Out of toner")),
        "DoorOpen" => (Error, Some("Cover open")),
        "OutputBinFull" => (Error, Some("Output tray full")),
        "Error" | "UserIntervention" | "OutOfMemory" | "PagePunt" => (Error, Some("Printer needs attention")),
        // Normal, TonerLow (a warning), WarmingUp, PowerSave, Waiting, Initializing, anything new:
        // only call it a problem when Windows says it is one.
        _ => (Idle, None),
    };
    (s, m.map(str::to_string))
}

pub fn parse_get_printer(json: &str) -> Result<Vec<PrinterInfo>, BackendError> {
    Ok(one_or_many::<PsPrinter>(json)?
        .into_iter()
        .map(|p| {
            let (state, msg) = classify(p.status.as_deref().unwrap_or("Normal"), p.work_offline.unwrap_or(false));
            PrinterInfo { queue_name: p.name, state, state_message: msg }
        })
        .collect())
}

fn parse_jobs(json: &str) -> Result<Vec<PsJob>, BackendError> {
    one_or_many(json)
}

/// Quote for a single-quoted PowerShell string.
fn ps_quote(s: &str) -> String {
    format!("'{}'", s.replace('\'', "''"))
}

async fn powershell(script: &str) -> Result<String, BackendError> {
    let (ok, out, err) =
        run_tool("powershell.exe", &["-NoProfile", "-NonInteractive", "-Command", script], PS_TIMEOUT).await?;
    if ok {
        Ok(out)
    } else {
        Err(BackendError::new(format!("Windows printing query failed: {}", clip(err.trim(), 300))))
    }
}

async fn job_list(queue: &str) -> Result<Vec<PsJob>, BackendError> {
    let script = format!(
        "$ErrorActionPreference='Stop'; Get-PrintJob -PrinterName {} | Select-Object Id,@{{n='Status';e={{[string]$_.JobStatus}}}} | ConvertTo-Json -Compress",
        ps_quote(queue)
    );
    parse_jobs(&powershell(&script).await?)
}

#[async_trait]
impl PrintBackend for WindowsBackend {
    fn platform(&self) -> &'static str {
        "windows"
    }

    async fn list_printers(&self) -> Result<Vec<PrinterInfo>, BackendError> {
        let script = "$ErrorActionPreference='Stop'; Get-Printer | Select-Object Name,@{n='Status';e={[string]$_.PrinterStatus}},WorkOffline | ConvertTo-Json -Compress";
        parse_get_printer(&powershell(script).await?)
    }

    async fn submit(&self, req: &SubmitRequest) -> Result<OsJob, BackendError> {
        let before: Vec<i64> = job_list(&req.queue_name).await.unwrap_or_default().iter().map(|j| j.id).collect();
        let settings = format!("noscale,{}x", req.copies.max(1));
        let file = req.file.to_string_lossy().into_owned();
        let sumatra = self.sumatra.to_string_lossy().into_owned();
        let (ok, _, err) = run_tool(
            &sumatra,
            &["-print-to", &req.queue_name, "-print-settings", &settings, "-silent", &file],
            PRINT_TIMEOUT,
        )
        .await?;
        if !ok {
            return Err(BackendError::new(format!(
                "Could not send the job to '{}': {}",
                req.queue_name,
                clip(err.trim(), 300)
            )));
        }
        // Sumatra returns once the job is spooled. Identify it by the ids that appeared.
        let after = job_list(&req.queue_name).await.unwrap_or_default();
        let new_ids: Vec<String> =
            after.iter().filter(|j| !before.contains(&j.id)).map(|j| j.id.to_string()).collect();
        // No new id: the spooler already finished with it — nothing left to wait for.
        Ok(OsJob(new_ids.join(",")))
    }

    async fn job_status(&self, queue_name: &str, job: &OsJob) -> Result<OsJobStatus, BackendError> {
        let ours: Vec<i64> = job.0.split(',').filter_map(|s| s.parse().ok()).collect();
        if ours.is_empty() {
            return Ok(OsJobStatus::Done);
        }
        let remaining: Vec<PsJob> =
            job_list(queue_name).await?.into_iter().filter(|j| ours.contains(&j.id)).collect();
        if remaining.is_empty() {
            return Ok(OsJobStatus::Done);
        }
        if remaining.iter().any(|j| j.status.as_deref().is_some_and(|s| s.contains("Error"))) {
            return Ok(OsJobStatus::Failed(format!("Windows reports an error printing on '{queue_name}'")));
        }
        Ok(OsJobStatus::Pending)
    }

    async fn cancel(&self, queue_name: &str, job: &OsJob) {
        for id in job.0.split(',').filter(|s| s.parse::<i64>().is_ok()) {
            let script = format!("Remove-PrintJob -PrinterName {} -ID {id}", ps_quote(queue_name));
            let _ = powershell(&script).await;
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn many_printers() {
        let json = r#"[{"Name":"Zebra ZD421","Status":"PaperOut","WorkOffline":false},
                       {"Name":"Microsoft Print to PDF","Status":"Normal","WorkOffline":false},
                       {"Name":"Front Desk","Status":"Normal","WorkOffline":true}]"#;
        let p = parse_get_printer(json).unwrap();
        assert_eq!(p[0].state, PrinterState::Error);
        assert_eq!(p[0].state_message.as_deref(), Some("Out of paper or labels"));
        assert_eq!(p[1].state, PrinterState::Idle);
        assert_eq!(p[2].state, PrinterState::Offline);
    }

    #[test]
    fn single_printer_is_a_bare_object_and_bom_is_tolerated() {
        let p = parse_get_printer("\u{feff}{\"Name\":\"Only One\",\"Status\":\"Printing\",\"WorkOffline\":false}").unwrap();
        assert_eq!(p.len(), 1);
        assert_eq!(p[0].state, PrinterState::Printing);
    }

    #[test]
    fn no_printers_is_empty_output() {
        assert!(parse_get_printer("").unwrap().is_empty());
        assert!(parse_get_printer("  \r\n").unwrap().is_empty());
    }

    #[test]
    fn toner_low_and_unknown_statuses_are_not_errors() {
        for s in ["TonerLow", "WarmingUp", "SomethingNew"] {
            assert_eq!(classify(s, false).0, PrinterState::Idle, "{s}");
        }
    }

    #[test]
    fn jobs_parse() {
        let j = parse_jobs(r#"{"Id":4,"Status":"Printing"}"#).unwrap();
        assert_eq!(j[0].id, 4);
        assert!(parse_jobs("").unwrap().is_empty());
    }

    #[test]
    fn quoting() {
        assert_eq!(ps_quote("Bob's Printer"), "'Bob''s Printer'");
    }
}
