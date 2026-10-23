#!/usr/bin/env python3
"""Does a USB printer still answer control requests? Logs one GET_CONFIGURATION every 3 s, with time since it appeared.

    sudo python3 usb-control-poll.py <seconds> | tee poll.log

Finds the first device with vendor id 0828 (SATO; edit VENDOR for another printer). `len=1` is healthy; `len=0` or
`len=-1` means the printer's control endpoint has stopped answering (what broke CUPS's stock usb backend on the Pi 4, see README.md).
Use it with CUPS stopped (`systemctl stop cups.path cups.socket cups.service`) so nothing else touches the printer, power-cycle the
printer, and watch for the first non-1 length. It sends no data to the printer and does not claim or detach any driver.
"""
VENDOR = '0828'
import os, glob, struct, fcntl, time, ctypes, sys
USBDEVFS_CONTROL = 0xC0185500
def find():
    for d in glob.glob('/sys/bus/usb/devices/*'):
        try:
            if open(d+'/idVendor').read().strip()==VENDOR:
                return d, int(open(d+'/busnum').read()), int(open(d+'/devnum').read())
        except Exception: pass
    return None
def getcfg(bus, dev):
    fd = os.open('/dev/bus/usb/%03d/%03d'%(bus,dev), os.O_RDWR)
    buf = ctypes.create_string_buffer(8)
    req = struct.pack('BBHHHIQ', 0x80, 8, 0, 0, 1, 1000, ctypes.addressof(buf))
    try:
        r = ctypes.create_string_buffer(req, len(req))
        n = ctypes.CDLL(None, use_errno=True).ioctl(fd, USBDEVFS_CONTROL, r)
        if n < 0: return -1, ctypes.get_errno()
        return n, buf.raw[0]
    finally:
        os.close(fd)
t_end = time.time()+float(sys.argv[1]); cur=None; t0=None
while time.time()<t_end:
    f = find()
    if f is None:
        if cur: print('%s GONE'%time.strftime('%T'), flush=True); cur=None
    else:
        if cur != f[2]:
            cur=f[2]; t0=time.time(); print('%s APPEARED dev=%d'%(time.strftime('%T'),cur), flush=True)
        n,v = getcfg(f[1],f[2])
        try: kid = open(f[0]+'/'+os.path.basename(f[0])+':1.0/ieee1284_id').read()[:12]
        except Exception: kid='-'
        print('%s t+%3ds getcfg len=%d val=%s' % (time.strftime('%T'), time.time()-t0, n, v), flush=True)
    time.sleep(3)
