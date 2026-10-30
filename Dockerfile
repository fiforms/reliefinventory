# syntax=docker/dockerfile:1.7
#
# Relief Inventory — production image (linux/amd64 + linux/arm64).
# See docker/README.md for running it and dev/DOCKER_PLAN.md for the design.
#
#   docker build -t reliefinventory .
#   docker buildx build --platform linux/amd64,linux/arm64 -t reliefinventory .
#
# One image, several roles chosen by the entrypoint's first argument:
#   web (default) | queue | scheduler | reverb | artisan <args...>

ARG PHP_IMAGE=dunglas/frankenphp:1-php8.4-trixie
ARG NODE_IMAGE=node:22-trixie-slim

# ------------------------------------------------------------ PHP + extensions
FROM ${PHP_IMAGE} AS php-base
# sodium: Ed25519 for federation (App\Services\Federation). Usually already built in; naming it makes sure.
RUN install-php-extensions gd intl zip pdo_mysql pcntl sockets bcmath exif opcache sodium

# ------------------------------------------------------------ composer vendor
FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# ------------------------------------------------------------ frontend assets
FROM ${NODE_IMAGE} AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-fund --no-audit
COPY vite.config.js tailwind.config.js postcss.config.js jsconfig.json ./
COPY resources ./resources
# resources/js imports the Ziggy route helper from vendor/, and Tailwind scans
# vendor views for class names
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ----------------------------------------------------------- application code
FROM vendor AS app-build
COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
COPY resources/views ./resources/views
# Help guide sources (App\Support\HelpGuide reads them at request time).
COPY resources/help ./resources/help
COPY public ./public
# artisan refuses to boot without these (storage/ is excluded from the build context)
RUN mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions storage/logs
# Generates the optimized autoloader and bootstrap/cache/{packages,services}.php.
# (Needs no .env/APP_KEY; package discovery only reads installed packages.)
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction
COPY --from=assets /build/public/build ./public/build

# ------------------------------------------------- system layer (built, then trimmed)
# Everything the container needs below the app, installed and trimmed in ONE stage so the
# final image can be flattened (see "the runtime"): a file deleted in a later layer still
# ships in the earlier one, so slimming only works if it happens before the flatten.
FROM php-base AS system

# Everything PDF generation and the container's own housekeeping need, from the
# distro so it is identical on amd64 and arm64:
#   weasyprint          every PDF: pallet labels, sitrep, help guide, inventory report,
#                       order form, BOL, pick sheets, outstanding orders
#   fonts-*             glyph coverage so reports/labels never render tofu boxes
#   mariadb-client      the scheduler's and pre-migration database dumps
#   curl                healthcheck
#
# weasyprint, python3-fonttools and python3-pyphen are installed with dpkg --force-depends AFTER the
# apt step: Debian's fonttools package depends on every optional extra (scipy, numpy,
# sympy, mpmath, unicode-data, ufolib2, ...), ~190 MB that WeasyPrint never loads.
# Its real needs (the python deps of weasyprint itself, plus brotli for WOFF2) are
# installed normally. The toolchain the PHP base image keeps for PECL builds
# (gcc, g++, perl, binutils, autoconf, headers, ...) is purged once the extensions are built.
RUN set -eux; \
    apt-get update; \
    mkdir /tmp/debs; \
    (cd /tmp/debs && apt-get download weasyprint python3-fonttools python3-pyphen hyphen-en-us mariadb-client); \
    # 1. the PHP image's PECL build toolchain (PHPIZE_DEPS) -- nothing here compiles at runtime.
    #    (The base image marks the compilers manual, so autoremove alone would keep them.)
    #    Purging first matters: packages apt installs *before* a purge that only perl-ish things
    #    depended on get swept up with it (this once removed pyphen and the MariaDB client).
    #    Some of these only exist on one architecture (libquadmath0 has no arm64 build, the compilers carry
    #    the architecture in their names: …-x86-64-linux-gnu / …-aarch64-linux-gnu), so only what is
    #    actually installed is passed to apt, and the compiler packages are matched by pattern.
    purge=""; \
    for p in autoconf dpkg-dev file g++ gcc libc6-dev make pkgconf re2c libc-dev-bin libcrypt-dev linux-libc-dev \
             libdpkg-perl perl perl-modules-5.40 libperl5.40 m4 patch rpcsvc-proto libpkgconf3 pkgconf-bin \
             libtext-iconv-perl libconfig-inifiles-perl libgcc-14-dev libasan8 libtsan2 libhwasan0 liblsan0 \
             libubsan1 libitm1 libquadmath0 mailcap; do \
        dpkg -s "$p" >/dev/null 2>&1 && purge="$purge $p"; \
    done; \
    purge="$purge $(dpkg-query -W -f='${Package}\n' | grep -E '^(binutils|cpp|gcc|g\+\+)(-|$)' | grep -vE '^gcc-14-base' || true)"; \
    apt-get purge -y --auto-remove $purge; \
    # 2. what the runtime needs, installed normally
    apt-get install -y --no-install-recommends \
        python3 python3-cffi python3-cssselect2 python3-html5lib python3-pil python3-pydyf \
        python3-tinycss2 python3-brotli libpango-1.0-0 libpangoft2-1.0-0 shared-mime-info \
        fontconfig fonts-liberation fonts-dejavu-core fonts-noto-core fonts-noto-color-emoji \
        mariadb-client-core libpcre2-posix3 \
        curl ca-certificates tzdata util-linux libstdc++6; \
    # 3. packages whose declared dependencies are the problem, last: apt refuses to run once
    #    deliberately-unmet dependencies exist. weasyprint -> python3-fonttools (-> scipy, sympy, ...),
    #    python3-pyphen -> hyphen-en-us (-> dictionaries-common -> perl; only the .dic files are used),
    #    mariadb-client (-> perl, for helper scripts we delete; only mariadb-dump is used).
    dpkg -i --force-depends /tmp/debs/*.deb; \
    rm -rf /tmp/debs /var/lib/apt/lists/*

COPY docker/trim-image.sh /tmp/trim-image.sh
RUN sh /tmp/trim-image.sh && rm /tmp/trim-image.sh

# ---------------------------------------------------------------- the runtime
# Flattened onto scratch: one layer holding only what survived the trim above. That also
# drops the base image's metadata, so what the base set is restated here.
FROM scratch AS runtime
COPY --from=system / /

ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    PHP_INI_DIR=/usr/local/etc/php \
    PHP_VERSION=8.4 \
    GODEBUG=cgocheck=0
EXPOSE 8080

COPY docker/entrypoint.sh /usr/local/bin/ri-entrypoint
COPY docker/scheduler.sh /usr/local/bin/ri-scheduler
COPY docker/db-backup.sh /usr/local/bin/ri-db-backup
COPY docker/Caddyfile docker/Caddyfile.app docker/Caddyfile.https docker/tls-auto.caddy docker/tls-internal.caddy /etc/caddy/
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-reliefinventory.ini
RUN chmod +x /usr/local/bin/ri-entrypoint /usr/local/bin/ri-scheduler /usr/local/bin/ri-db-backup

WORKDIR /var/www/html
COPY --from=app-build /app /var/www/html

# storage/ is a volume at runtime (each warehouse's uploads, logs, caches, the generated
# APP_KEY), so the skeleton below is only what the entrypoint re-creates anyway. There is
# no public/storage link: nothing is served from storage directly, since one web path can't
# serve several warehouses' files (MULTI_WAREHOUSE.md §11). public/fonts/NotoColorEmoji.ttf
# links the image's own emoji font so every browser draws the same emoji (see @font-face in
# resources/css/app.css).
RUN mkdir -p storage/app/private storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && mkdir public/fonts \
    && ln -s /usr/share/fonts/truetype/noto/NotoColorEmoji.ttf public/fonts/NotoColorEmoji.ttf \
    && chown -R www-data:www-data storage bootstrap/cache

ARG RI_GIT_COMMIT=
ARG RI_VERSION=
ARG RI_BUILD_DATE=
LABEL org.opencontainers.image.title="Relief Inventory" \
      org.opencontainers.image.description="Warehouse management for disaster-relief donated goods" \
      org.opencontainers.image.source="https://github.com/fiforms/reliefinventory" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.revision=$RI_GIT_COMMIT \
      org.opencontainers.image.version=$RI_VERSION \
      org.opencontainers.image.created=$RI_BUILD_DATE

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    RI_GIT_COMMIT=$RI_GIT_COMMIT \
    RI_VERSION=$RI_VERSION \
    # fontconfig (used by weasyprint) wants a writable HOME for its cache; www-data's real one is read-only
    HOME=/tmp/home \
    XDG_CONFIG_HOME=/tmp/home/.config \
    XDG_DATA_HOME=/tmp/home/.local/share \
    # PDF rendering: the distro's weasyprint (laravel-pdf's driver is weasyprint by default)
    LARAVEL_PDF_WEASYPRINT_BINARY=/usr/bin/weasyprint \
    RI_HTTP_PORT=8080 \
    # the admin page hides systemd/git-only options and shows the compose-managed backup settings instead
    RI_RUNTIME=docker

EXPOSE 8080 4430
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${RI_HTTP_PORT}/up" >/dev/null || exit 1

ENTRYPOINT ["ri-entrypoint"]
CMD ["web"]
