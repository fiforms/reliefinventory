#!/bin/sh
# Browsershot/Puppeteer launches this instead of /usr/bin/chromium directly.
# Flags a container needs that the app shouldn't have to know about:
#   --no-sandbox            Chromium's sandbox needs user namespaces / extra
#                           capabilities most container runtimes (and many
#                           kernels' AppArmor policies) don't grant. Safe here:
#                           it only ever renders this app's own templates.
#   --disable-dev-shm-usage Docker's default /dev/shm is 64 MB, which crashes
#                           Chromium on bigger pages; use /tmp instead.
#   --disable-gpu           no GPU in a container.
exec /usr/bin/chromium --no-sandbox --disable-dev-shm-usage --disable-gpu "$@"
