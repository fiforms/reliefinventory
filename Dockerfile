# syntax=docker/dockerfile:1.7
#
# Relief Inventory — production image (linux/amd64 + linux/arm64).
# See docker/README.md for running it and scripts/DOCKER_PLAN.md for the design.
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
RUN install-php-extensions gd intl zip pdo_mysql pcntl sockets bcmath exif opcache

# ------------------------------------------------------------ composer vendor
FROM php-base AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

# ------------------------------------------------------------ frontend assets
FROM ${NODE_IMAGE} AS assets
# Puppeteer is a runtime dependency (PDF rendering) but its postinstall
# downloads a Chrome that doesn't exist for linux/arm64; we use the distro's
# chromium instead, so never download one.
ENV PUPPETEER_SKIP_DOWNLOAD=1
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-fund --no-audit
COPY vite.config.js tailwind.config.js postcss.config.js jsconfig.json ./
COPY resources ./resources
# resources/js imports the Ziggy route helper from vendor/, and Tailwind scans
# vendor views for class names
COPY --from=vendor /app/vendor ./vendor
RUN npm run build

# ------------------------------------------- PDF renderer's node_modules only
# Browsershot shells out to node + puppeteer. Install just the production
# dependencies (puppeteer-core & friends), none of the Vite/Vue build tooling.
FROM ${NODE_IMAGE} AS pdf-node
ENV PUPPETEER_SKIP_DOWNLOAD=1
WORKDIR /opt/pdf
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-fund --no-audit && npm cache clean --force

# the node binary only (copied into the runtime image)
FROM ${NODE_IMAGE} AS node-bin

# ----------------------------------------------------------- application code
FROM vendor AS app-build
COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
COPY resources/views ./resources/views
COPY public ./public
# artisan refuses to boot without these (storage/ is excluded from the build context)
RUN mkdir -p storage/framework/views storage/framework/cache storage/framework/sessions storage/logs
# Generates the optimized autoloader and bootstrap/cache/{packages,services}.php.
# (Needs no .env/APP_KEY; package discovery only reads installed packages.)
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction
COPY --from=assets /build/public/build ./public/build

# ---------------------------------------------------------------- the runtime
FROM php-base AS runtime

# Everything PDF generation and the container's own housekeeping need, from the
# distro so it is identical on amd64 and arm64:
#   chromium            Browsershot (pallet labels, sitrep, help pages)
#   weasyprint          inventory report, order form, BOL, pick sheets, outstanding orders
#   fonts-*             glyph coverage so reports/labels never render tofu boxes
#   mariadb-client      the scheduler's database dumps
#   curl                healthcheck
RUN apt-get update && apt-get install -y --no-install-recommends \
        chromium \
        weasyprint \
        fontconfig fonts-liberation fonts-dejavu-core fonts-noto-core fonts-noto-color-emoji \
        mariadb-client \
        curl ca-certificates tzdata util-linux libstdc++6 \
    && rm -rf /var/lib/apt/lists/*

# The node binary Browsershot drives Puppeteer with (pinned by NODE_IMAGE).
COPY --from=node-bin /usr/local/bin/node /usr/local/bin/node
COPY --from=pdf-node /opt/pdf/node_modules /opt/pdf/node_modules

COPY docker/chromium-wrapper.sh /usr/local/bin/chromium-wrapper
COPY docker/entrypoint.sh /usr/local/bin/ri-entrypoint
COPY docker/scheduler.sh /usr/local/bin/ri-scheduler
COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-reliefinventory.ini
RUN chmod +x /usr/local/bin/chromium-wrapper /usr/local/bin/ri-entrypoint /usr/local/bin/ri-scheduler

WORKDIR /var/www/html
COPY --from=app-build /app /var/www/html

# storage/ is a volume at runtime (private uploads, logs, caches, the generated
# APP_KEY), so the skeleton below is only what the entrypoint re-creates anyway.
# public/storage is the usual storage:link, created now so public/ can stay
# read-only at runtime.
RUN mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions \
        storage/framework/views storage/logs bootstrap/cache \
    && ln -s ../storage/app/public public/storage \
    && chown -R www-data:www-data storage bootstrap/cache

ARG RI_GIT_COMMIT=
ARG RI_BUILD_DATE=
LABEL org.opencontainers.image.title="Relief Inventory" \
      org.opencontainers.image.description="Warehouse management for disaster-relief donated goods" \
      org.opencontainers.image.source="https://github.com/fiforms/reliefinventory" \
      org.opencontainers.image.licenses="GPL-3.0-or-later" \
      org.opencontainers.image.revision=$RI_GIT_COMMIT \
      org.opencontainers.image.created=$RI_BUILD_DATE

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    RI_GIT_COMMIT=$RI_GIT_COMMIT \
    # chromium/puppeteer need a writable HOME; www-data's real one is read-only
    HOME=/tmp/home \
    XDG_CONFIG_HOME=/tmp/home/.config \
    XDG_DATA_HOME=/tmp/home/.local/share \
    # PDF rendering: Browsershot -> node -> puppeteer -> system chromium
    LARAVEL_PDF_NODE_BINARY=/usr/local/bin/node \
    LARAVEL_PDF_NODE_MODULES_PATH=/opt/pdf/node_modules \
    LARAVEL_PDF_CHROME_PATH=/usr/local/bin/chromium-wrapper \
    LARAVEL_PDF_NO_SANDBOX=true \
    LARAVEL_PDF_WEASYPRINT_BINARY=/usr/bin/weasyprint \
    PUPPETEER_SKIP_DOWNLOAD=1 \
    RI_HTTP_PORT=8080

EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${RI_HTTP_PORT}/up" >/dev/null || exit 1

ENTRYPOINT ["ri-entrypoint"]
CMD ["web"]
