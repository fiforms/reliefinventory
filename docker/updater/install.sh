#!/bin/sh
# Install the host-side updater as a systemd service. Run as root on the Docker host:
#   sudo ./install.sh /path/to/compose.yaml [http://127.0.0.1:8080]
# compose.yaml must set RI_UPDATE_SECRET (see the "Automatic updates" section of docker/README.md).
set -eu
compose=${1:?usage: install.sh /path/to/compose.yaml [app URL]}
url=${2:-http://127.0.0.1:8080}
here=$(cd "$(dirname "$0")" && pwd)
compose=$(readlink -f "$compose")
[ -f "$compose" ] || { echo "No such compose file: $compose" >&2; exit 1; }

install -d /usr/local/lib/ri-updater
install -m 0755 "$here/ri-updater.py" /usr/local/lib/ri-updater/ri-updater.py
sed -e "s#@COMPOSE_FILE@#$compose#" -e "s#@URL@#$url#" "$here/ri-updater.service" > /etc/systemd/system/ri-updater.service
systemctl daemon-reload
systemctl enable --now ri-updater.service
echo "ri-updater installed and started. Logs: journalctl -u ri-updater -f"
