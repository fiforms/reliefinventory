# Windows bundle resources

`tauri.windows.conf.json` bundles two files from this directory into the Windows installer. They are
**not committed** (third-party binary); fetch them before a Windows build:

1. Download the portable **SumatraPDF** 64-bit `.exe` from https://www.sumatrapdfreader.org/download-free-pdf-viewer
   and save it here as `SumatraPDF.exe`.
2. Save SumatraPDF's license text as `SumatraPDF-LICENSE.txt` (it is **GPLv3**: the installer must carry the
   license and a pointer to the source, https://github.com/sumatrapdfreader/sumatrapdf).

RI Print prints through it with `-print-to "<printer>" -print-settings "noscale,<n>x" -silent` — actual size,
no scaling, no dialog (`PRINT_CLIENT_SPEC.md` §3.4). macOS and Linux need nothing here; they use the
system's `lp`.
