import socket, sys
port, out = int(sys.argv[1]), sys.argv[2]
s = socket.socket(); s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(("127.0.0.1", port)); s.listen(5)
n = 0
while True:
    c, _ = s.accept()
    data = b""
    while True:
        b = c.recv(65536)
        if not b: break
        data += b
    n += 1
    open(out, "ab").write(data)
    c.close()
