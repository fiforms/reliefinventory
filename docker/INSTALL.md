# Installing Relief Inventory with Docker

This guide walks you through installing Relief Inventory on a Windows, Mac or Linux computer using Docker. You don't
need to know anything about Docker first, and you don't need to install PHP, a database or anything else. It takes
about 15 minutes, most of it waiting for downloads.

**What is Docker?** Docker runs Relief Inventory and its database in a sealed box on your computer, so nothing
else on the machine gets changed. **Docker Desktop** is the free app that runs Docker with buttons and menus. You
install it once, like any other program.

> This guide gets you a working copy you can open on that computer, or from other computers on the same network.
> If you are putting Relief Inventory on the internet, also read [the Docker README](README.md) first. It
> covers HTTPS, email, backups and security.

## What you need

- A computer running **Windows 10 or 11, macOS, or Linux** that can stay switched on while people use the system.
- An internet connection for the install (the downloads are large).
- Several gigabytes of free disk space, plus room for your own data.
- Permission to install software on that computer.

## Step 1: Install Docker Desktop

Download Docker Desktop from **<https://www.docker.com/products/docker-desktop/>** and run the installer.

- **Windows:** choose the installer for your processor (most computers are "AMD64"; a few newer ones are
  "ARM64"). Leave "Use WSL 2" ticked if you are asked. Restart the computer if the installer asks you to.
- **Mac:** choose "Apple Silicon" for Macs from late 2020 onward (M1, M2, M3, M4) or "Intel chip" for older ones.
  Open the downloaded file and drag Docker into Applications.
- **Linux:** follow the Docker Desktop instructions for your distribution at
  <https://docs.docker.com/desktop/setup/install/linux/>. Server installs without a desktop can use plain Docker
  Engine instead: <https://docs.docker.com/engine/install/>. Install the Docker Compose plugin too.

Then **open Docker Desktop** and wait until it says Docker is running (usually a green indicator at the bottom left).
You can skip creating a Docker account or signing in; you don't need one.

Docker Desktop is free for personal use, education and small businesses. Larger companies need a paid
subscription. Check [Docker's terms](https://www.docker.com/pricing/) if you aren't sure. (The free Docker Engine on
Linux has no such limit.)

## Step 2: Download the Relief Inventory settings file

1. Make a new folder for Relief Inventory, for example `ReliefInventory` in your Documents folder.
2. Download the file **compose.yaml** into it: open
   <https://raw.githubusercontent.com/fiforms/reliefinventory/master/docker/compose.yaml>
   in your browser, right-click the page, choose **Save as**, and save it in your new folder.
   Make sure the file is named exactly `compose.yaml` and your computer hasn't added `.txt` to the end.

This one small file is everything you need. It tells Docker which pieces to download and how to connect them.

## Step 3: Tell it your address (usually optional)

Open `compose.yaml` in any text editor (Notepad, TextEdit or similar) and find this line near the top:

```
APP_URL: http://localhost:8080
```

- **Using it only on this computer?** Leave it as it is.
- **Want other computers or phones on your network to use it?** Replace `localhost` with this computer's address
  on your network, for example `APP_URL: http://192.168.1.50:8080`. Ask whoever manages your network if you don't
  know it. On Windows, allow Docker through the firewall if Windows asks.

Save the file. Everything else already has a working default.

## Step 4: Start it

Open a terminal in your folder:

- **Windows:** open the folder in File Explorer, click the address bar, type `cmd`, and press Enter.
- **Mac:** right-click the folder and choose **New Terminal at Folder**. (If you don't see it, open Terminal and type
  `cd ` followed by dragging the folder into the window, then press Enter.)
- **Linux:** open a terminal in the folder.

Type this and press Enter:

```
docker compose up -d
```

The first time, Docker downloads Relief Inventory and its database. This takes several minutes, and you'll see
progress bars. When it returns to the prompt, give it another minute to set itself up.

## Step 5: Open it and create your administrator account

Open a web browser and go to the address you set as `APP_URL` (by default **<http://localhost:8080>**).

A setup page asks for the administrator's name, email address and password, and whether to load **example data**.
Choose example data if you just want to look around, and don't choose it for a real warehouse, because it can't be
removed later. When you finish you are signed in and ready to go.

If the page doesn't load straight away, wait a minute and refresh. The first start does a lot of setup.

## Everyday use

Run these in the same folder as `compose.yaml`:

| What you want to do | Command |
|---|---|
| Stop it (your data is kept) | `docker compose stop` |
| Start it again | `docker compose start` |
| See what's running | `docker compose ps` |
| See messages if something is wrong | `docker compose logs app` |

It also starts again by itself when the computer restarts, as long as Docker Desktop starts when you sign in.
In Docker Desktop's settings, turn on **Start Docker Desktop when you sign in**.

You can also start and stop it from the Docker Desktop window by clicking the **reliefinventory** entry.

## Your data and backups

Your data is stored by Docker in the background, not in your folder, so deleting the folder doesn't delete it.
**Backups** are written automatically every day into a `backups` folder next to `compose.yaml`.

- Copy those backup files to another computer or a USB drive regularly. A backup on the same machine won't help if
  the machine fails.
- Uploaded photos are not in the daily backups by default. See "Data and backups" in [the Docker README](README.md) to
  include them.

## Updating to a new version

When a new release comes out, run these in your folder:

```
docker compose pull
docker compose up -d
```

Your data is kept, and the database is backed up automatically before any update changes it.
Release notes are on the [Releases page](https://github.com/fiforms/reliefinventory/releases).

## Optional: update from the web page (Linux server)

By default you update with the two commands above. On a Linux server you can instead let an administrator do it from
**Updates & Backups** in the web page. The web app can't restart its own containers, so a small helper service
runs on the server itself: the page leaves it a note, the helper picks the note up, downloads the new release and
restarts Relief Inventory. This is for a **new or existing Linux Docker host**; Docker Desktop on Windows/Mac
doesn't need it.

You need: `sudo` access on the server, `python3` (already on nearly every Linux server), and the `compose.yaml`
you started Relief Inventory from.

1. **Choose a secret.** Make a long random string, for example `openssl rand -hex 32`. It is the password the helper
   uses to talk to the app, so keep it private.
2. **Put it in `compose.yaml`.** In the settings block at the top, add (remove the `#` if the line is already
   there):

   ```
   RI_UPDATE_SECRET: paste-your-secret-here
   ```

   Then apply it: `docker compose up -d`.
3. **Install the helper.** In a copy of the Relief Inventory files (`git clone https://github.com/fiforms/reliefinventory`
   or download the `docker/updater` folder), run, giving the full path to your `compose.yaml`:

   ```
   sudo docker/updater/install.sh /home/you/relief/compose.yaml
   ```

   If people reach the app at an address other than `http://127.0.0.1:8080` *from the server itself* (you changed
   the port), add it as the second argument: `sudo docker/updater/install.sh /home/you/relief/compose.yaml http://127.0.0.1:8090`.
4. **Check that it's running:** `systemctl status ri-updater` should say *active (running)*;
   `journalctl -u ri-updater -f` shows what it is doing.
5. **Try it.** Sign in as an **instance administrator**, open **Updates & Backups** and press **Check for Updates**.
   Within about 30 seconds it should report either a new version or "nothing newer". **Install Update** then restarts
   the system for a few minutes (the page comes back on its own).

If a request sits at "Waiting for the updater…", the helper isn't running or can't read the secret: check step 4.
You can also ask from the command line: `docker compose exec app php artisan instance:update check`
(or `apply`, or `status`).

To remove the helper: `sudo systemctl disable --now ri-updater && sudo rm /etc/systemd/system/ri-updater.service`,
and delete the `RI_UPDATE_SECRET` line.

## Troubleshooting

- **"docker: command not found" or "Cannot connect to the Docker daemon":** Docker Desktop isn't running. Open it and
  wait for the green indicator, then try again.
- **"port is already allocated":** something else on the computer is using port 8080. In `compose.yaml` change both
  `8080` in `APP_URL` and the `"8080:8080"` line under `app` to a free port such as `8090`.
  Save and run `docker compose up -d` again.
- **The browser says it can't connect:** run `docker compose ps` and check that the `app` line says it's running.
  If it keeps restarting, `docker compose logs app` shows why.
- **Phone camera scanning doesn't work:** phones only allow camera access on secure (HTTPS) addresses. Put
  Relief Inventory behind HTTPS (see [the Docker README](README.md)), or use a USB or Bluetooth barcode scanner.
- **Start completely over:** `docker compose down -v` stops Relief Inventory and **permanently deletes all its data**.
  Only do this on a test copy.

## Taking it further

Email, HTTPS, restoring a backup, running unattended and everything else are covered in [the Docker README](README.md). Questions and bug reports are welcome on the project's
[GitHub issues page](https://github.com/fiforms/reliefinventory/issues).
