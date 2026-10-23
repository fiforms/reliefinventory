# Testing USB power management as the cause of the WS408 control-endpoint failure

Status: **proposed, not yet run** (written 2026-10-23). Background: `README.md` in this directory ("Why `rstationlp` exists").

## Hypothesis

On a Pi 4 the SATO WS408 stops answering USB control requests ~100 s after it enumerates, while bulk writes keep working. A laptop
never shows this. `README.md` records USB **autosuspend** as ruled out (`power/control=on`, never suspended), but that was set
*after* enumeration and autosuspend is not the only USB power feature. The better remaining suspect is **link power management**
(USB2 L1 / USB3 U1/U2 LPM) between the Pi's VL805 xHCI / VIA Labs hub and the printer, which `usbcore.autosuspend` does not touch.

If a kernel argument fixes it, the stock CUPS `usb` backend works again and `rstationlp` plus the `blacklist` quirk
(`rstation-sato.usb-quirks`) can be dropped.

## Kernel arguments to try

```
usbcore.autosuspend=-1 usbcore.quirks=0828:a003:k
```

- `usbcore.autosuspend=-1` — never autosuspend, applied from boot.
- `usbcore.quirks=VID:PID:k` — I believe `k` is `USB_QUIRK_NO_LPM` (no link power management for that device). **Verify the flag
  letter against the running kernel's `Documentation/admin-guide/kernel-parameters.txt` before trusting a result.** `0828:a003` is
  the WS408 (same IDs as `rstation-sato.usb-quirks`).

Test them separately as well as together, so a pass tells you which one mattered.

## Procedure (no image rebuild)

1. **Baseline.** Note the current state: `cat /proc/cmdline`, `grep . /sys/bus/usb/devices/*/power/control`,
   `dmesg | grep -i lpm`.
2. **Edit the cmdline.** The Pi reads `/boot/firmware/slotA/cmdline.txt` (one line, FAT partition; edit on the Pi or on the SD card
   from a laptop). Append the arguments to the end of that single line. **Save a copy of the original line first.** RAUC owns this
   file, so an OTA update overwrites the edit — fine for a test. Reboot.
3. **Confirm it took.** `cat /proc/cmdline` shows the arguments; `grep . /sys/bus/usb/devices/*/power/control` shows `on` for the
   printer *and* its hub(s).
4. **Run the poller with CUPS stopped.**
   ```
   sudo systemctl stop cups.path cups.socket cups.service
   sudo python3 usb-control-poll.py 600 | tee poll.log
   ```
   Power-cycle the printer when it starts. Healthy is `len=1`; the failure is `len=0`/`len=-1` at ~78–102 s.
   - `len=1` for several minutes (say 5+), repeated over at least three printer power-cycles: the argument fixes it.
   - Fails at the usual time: that argument is ruled out.
5. **Restore** the original cmdline line and reboot.

The poller never touches `rstationlp` or the quirks file, so step 4 answers "does the cmdline help?" without any driver change.

## Optional: stock backend vs `rstationlp` on the same image

Only worth doing if step 4 passes (or to re-confirm the failure with the stock backend).

1. Get `/usr/share/cups/usb/rstation-sato.usb-quirks` out of the way. The rootfs is read-only: remount it rw for the test, or
   `mount --bind` an empty file over that path (restore afterwards). This is on-device and not persistent.
2. Restart CUPS; Settings > Printers should now list the printer under its `usb://SATO/WS408?serial=…` URI as well as
   `rstationlp://…`.
3. Point a test queue at the `usb://` URI and print several separate jobs; compare with a queue on `rstationlp://`.
4. Don't run `/usr/lib/cups/backend/usb` by hand while a job is active (it confounds the result).

## If it works: permanent change

1. Add the arguments where `build.sh` stages the kernel cmdline (the same mechanism as `cfg80211.ieee80211_regdom=` for
   `SLIDE_ANNOUNCER_WIFI_COUNTRY`; `00-run.sh` appends it). The `rstation/platform` submodule owns that, so it may need a platform
   change, as with the server-URL work. Consider making it a boot-yaml option only if there is a reason to switch it per device.
2. Drop the `blacklist` quirk and `rstationlp` (and the matching `PRINTERS` entry, the PPD/package wiring, and the `rstationlp`
   scheme allowance in `slide-announcer-printer-setup`). Bump `VERSION`, delete older `rstation-sato-driver_*.deb` from `debs/`.
3. Update `README.md` and the CLAUDE.md SATO paragraph (it currently says the stock backend is blacklisted).
4. Re-test a real print from the web UI on a Pi (never yet done for this driver).

## If it doesn't work

Record the result in `README.md`'s ruled-out list. Still untried: a different hub or cable between the Pi and the printer, a
different host (a Pi 5, say), and `usbmon` capture of what the hub does at the ~100 s mark. Keep `rstationlp`.

## Results

Fill in as tests are run.

| Date | Kernel args | Printer power-cycles | Poller result |
|------|-------------|----------------------|---------------|
|      |             |                      |               |
