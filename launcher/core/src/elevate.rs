use std::path::Path;

/// Start `exe args…` with administrator rights (the Windows UAC prompt) and return at once; the caller
/// follows progress through `status`. Installing needs it (DISM and the WSL MSI); nothing else does.
#[cfg(windows)]
pub fn relaunch_elevated(exe: &Path, args: &[&str]) -> std::io::Result<()> {
    use std::os::windows::process::CommandExt;
    let quote = |s: &str| format!("'{}'", s.replace('\'', "''"));
    let arglist = args.iter().map(|a| quote(a)).collect::<Vec<_>>().join(",");
    let script = format!(
        "Start-Process -FilePath {} -ArgumentList {} -Verb RunAs",
        quote(&exe.to_string_lossy()),
        if arglist.is_empty() { "@()".to_string() } else { arglist }
    );
    let status = std::process::Command::new("powershell.exe")
        .args(["-NoProfile", "-NonInteractive", "-Command", &script])
        .creation_flags(0x0800_0000)
        .status()?;
    if status.success() {
        Ok(())
    } else {
        // the usual cause: the person pressed "No" on the UAC prompt
        Err(std::io::Error::new(std::io::ErrorKind::PermissionDenied, "Administrator permission was not given"))
    }
}

#[cfg(not(windows))]
pub fn relaunch_elevated(_exe: &Path, _args: &[&str]) -> std::io::Result<()> {
    Err(std::io::Error::new(std::io::ErrorKind::Unsupported, "elevation is only implemented for Windows"))
}
