# Installing Relief Inventory

There are two ways to install Relief Inventory, depending on what you are putting it on:

- **A Windows PC, a Mac, or a Linux desktop:** use **RI Launcher**. It is a normal app with buttons; you don't
  need to know anything about Docker. See [On a Windows, Mac or Linux desktop](#on-a-windows-mac-or-linux-desktop).
- **A Linux server** (no desktop, always on, maybe on the internet): install it with Docker Compose. See
  [On a Linux server](#on-a-linux-server).

> Either way, if you are putting Relief Inventory on the internet, also read [the Docker README](README.md). It
> covers HTTPS, email, backups and security.

## On a Windows, Mac or Linux desktop

**RI Launcher** installs and runs Relief Inventory for you. On Windows and Mac it sets up the small Linux layer
Docker needs by itself, so you don't install Docker Desktop. On Linux it uses the Docker you already have.

1. Download RI Launcher for your computer from the
   [Releases page](https://github.com/fiforms/reliefinventory/releases):
   - **Windows:** the `.exe` installer.
   - **Mac:** the `.dmg` (works on Apple silicon and Intel).
   - **Linux:** the `.deb` (Debian, Ubuntu, Mint) or the `.AppImage` (any distribution).
2. Install it and open it. Press **Install** and wait. The first time it downloads several gigabytes, and on
   Windows it may ask you to restart the computer, then it carries on by itself when you sign back in.
3. Press **Start**, then open the address it shows (by default <http://localhost:8080>).
4. A setup page asks for the administrator's name, email address and password, and whether to load **example
   data**. Choose example data if you just want to look around, but not for a real warehouse, because it can't be
   removed later. When you finish you are signed in and ready to go.

Things the launcher window can do: stop and start the system, **Run as a LAN service** (so other computers and phones
on your network can use it), **Copy shareable link** (to give to those people), update to a new version, and make,
save and restore backups. For others to connect, the launcher has to stay running (it lives in your tray or menu
bar). The launcher's own notes are in [launcher/README.md](../launcher/README.md).

## On a Linux server

These steps take a bare Linux server or VM (Debian or Ubuntu are shown; other distributions are similar) to a running
Relief Inventory that an administrator can update from the web page. You need `sudo` access and an internet
connection. It takes about 15 minutes, most of it waiting for downloads.

### 1. Install Docker and Python

You need Docker Engine **with the Compose plugin** (`docker compose`, two words). Pick one:

**Option A: from your distribution's own repository (simplest on Debian and Ubuntu).**

```
sudo apt-get update
sudo apt-get install -y docker.io docker-compose-v2
```

The Compose package name varies: `docker-compose-v2` on Ubuntu 24.04 and newer, `docker-compose` on Debian 13. If
`apt` can't find one, use Option B. (Don't use the old standalone `docker-compose` 1.x command; it won't work with
this file.)

**Option B: Docker's own packages**, which are newer and include Compose. Follow Docker's instructions for your
distribution at <https://docs.docker.com/engine/install/>. On a typical Debian or Ubuntu server, Docker's
convenience script does all of it:

```
curl -fsSL https://get.docker.com | sudo sh
```

Then, either way, check that Docker works and install the other tools used below if they are missing:

```
sudo docker compose version
sudo apt-get install -y python3 curl openssl
```

`python3` runs the update helper in step 6, and `curl` and `openssl` are used in the next steps. Most servers
already have all three.

### 2. Make a folder and download the settings file

```
sudo mkdir -p /opt/relief && cd /opt/relief
sudo curl -fsSLO https://raw.githubusercontent.com/fiforms/reliefinventory/master/docker/compose.yaml
```

Use any folder you like (`/opt/relief` is only an example). Keep `compose.yaml` there: backups are written in a
`backups` folder next to it, and the rest of this guide assumes you run every command from this folder.

### 3. Edit the settings

```
sudo nano compose.yaml
```

Near the top, in the **YOUR SETTINGS** block, set `APP_URL` to the address people will type into their browser,
including `http://` or `https://` (and the port if it isn't 80 or 443), for example:

```
APP_URL: http://relief.example.org:8080
```

If you change the port, change the `"8080:8080"` line under `app` to match. If the server is reachable from the
internet before you have finished the setup wizard, also uncomment `RI_SETUP_CODE` and choose a code, so a stranger
who opens the page first can't make themselves the administrator. Everything else has a working default.

Then make the secret the update helper uses to talk to the app (step 6) and put it in the file. This finds the
`RI_UPDATE_SECRET` line, uncomments it and gives it a random value:

```
sudo sed -i "s|^\( *\)#* *RI_UPDATE_SECRET:.*|\1RI_UPDATE_SECRET: $(openssl rand -hex 32)|" compose.yaml
```

**Want HTTPS on this server?** Instead of the port-8080 address above, set `APP_URL` to the public name with
`https://` and no port, uncomment `RI_HTTPS: auto`, and switch the two port lines under `app` (`"80:8080"` and
`"443:4430"` instead of `"8080:8080"`). Let's Encrypt needs a public DNS name for the server and ports 80 and 443
open to the internet. For a LAN server with no public name use `RI_HTTPS: internal` (browsers will warn). Details:
[the Docker README](README.md#https). If you do this, give the updater in step 6 the address
`http://127.0.0.1:80` as its second argument.

### 4. Download and start it

```
sudo docker compose pull
sudo docker compose up -d
```

The first time, Docker downloads Relief Inventory and its database. When it returns to the prompt, give it another
minute to set itself up. `sudo docker compose ps` should show the services running. It starts again by itself
whenever the server restarts.

### 5. Create your administrator account

Open the `APP_URL` address in a browser. A setup page asks for the administrator's name, email address and password,
and whether to load **example data** (don't, for a real warehouse: it can't be removed later). If the page doesn't
load straight away, wait a minute and refresh.

### 6. Install the update helper

The web app can't restart its own containers, so a small helper service runs on the server itself. When an
administrator presses **Install Update** in the web page, the page leaves the helper a note, and the helper downloads
the new release and restarts Relief Inventory. Install it from the same folder as `compose.yaml`:

```
cd /opt/relief
mkdir ri-updater && cd ri-updater
for f in install.sh ri-updater.py ri-updater.service; do
  curl -fsSLO https://raw.githubusercontent.com/fiforms/reliefinventory/master/docker/updater/$f
done
chmod +x install.sh
sudo ./install.sh ../compose.yaml
```

If people reach the app at an address other than `http://127.0.0.1:8080` *from the server itself* (you changed the
port), add it as the second argument: `sudo ./install.sh ../compose.yaml http://127.0.0.1:8090`.

Check that it is running: `systemctl status ri-updater` should say *active (running)*, and
`journalctl -u ri-updater -f` shows what it is doing. Then sign in as an **instance administrator**, open
**Instance Admin** and press **Check for Updates**. Within about 30 seconds it should report either a new version or
"nothing newer". **Install Update** restarts the system for a few minutes (the page comes back on its own).

If a request sits at "Waiting for the updater…", the helper isn't running or can't read the secret: check
`systemctl status ri-updater`. You can also ask from the command line:
`sudo docker compose exec app php artisan instance:update check` (or `apply`, or `status`).

To remove the helper: `sudo systemctl disable --now ri-updater && sudo rm /etc/systemd/system/ri-updater.service`,
and delete the `RI_UPDATE_SECRET` line from `compose.yaml`.

## Everyday use on a server

Run these in the folder with `compose.yaml`:

| What you want to do | Command |
|---|---|
| Stop it (your data is kept) | `sudo docker compose stop` |
| Start it again | `sudo docker compose start` |
| See what's running | `sudo docker compose ps` |
| See messages if something is wrong | `sudo docker compose logs app` |
| Update by hand instead of from the web page | `sudo docker compose pull && sudo docker compose up -d` |

Release notes are on the [Releases page](https://github.com/fiforms/reliefinventory/releases). The database is backed
up automatically before any update changes it.

## Your data and backups

Your data is stored by Docker in the background, not in your folder, so deleting the folder doesn't delete it.
**Backups** are written automatically every day into the `backups` folder next to `compose.yaml` (on a desktop
install, use the launcher's backup tools).

- Copy those backup files to another computer or a USB drive regularly. A backup on the same machine won't help if
  the machine fails.
- Uploaded photos are not in the daily backups by default. See "Data and backups" in [the Docker README](README.md) to
  include them.

## Troubleshooting

- **"docker: command not found" or "Cannot connect to the Docker daemon":** Docker isn't installed or isn't running.
  On a server, `sudo systemctl start docker`. In RI Launcher, press **Start**.
- **"port is already allocated":** something else on the computer is using port 8080. In `compose.yaml` change both
  `8080` in `APP_URL` and the `"8080:8080"` line under `app` to a free port such as `8090`.
  Save and run `sudo docker compose up -d` again. (In RI Launcher, change the port in its settings.)
- **The browser says it can't connect:** run `sudo docker compose ps` and check that the `app` line says it's
  running. If it keeps restarting, `sudo docker compose logs app` shows why. Also check the server's firewall allows
  the port.
- **Phone camera scanning doesn't work:** phones only allow camera access on secure (HTTPS) addresses. Put
  Relief Inventory behind HTTPS (see [the Docker README](README.md)), or use a USB or Bluetooth barcode scanner.
- **Start completely over:** `sudo docker compose down -v` stops Relief Inventory and **permanently deletes all its
  data**. Only do this on a test copy.

## Taking it further

Email, HTTPS, restoring a backup, running unattended and everything else are covered in [the Docker README](README.md). Questions and bug reports are welcome on the project's
[GitHub issues page](https://github.com/fiforms/reliefinventory/issues).
