#!/bin/sh
# Run once, at build time, in the Dockerfile's `system` stage. Deletes what the runtime image
# never uses; it only saves space because the stage is flattened afterwards.
set -eu

# --- PHP build leftovers (the extensions are compiled by now)
rm -f /usr/src/php.tar.xz /usr/local/bin/php-cgi /usr/local/bin/phpdbg /usr/local/bin/phpize \
      /usr/local/bin/php-config /usr/local/bin/install-php-extensions /usr/local/bin/pecl \
      /usr/local/bin/peardev /usr/local/bin/pear /usr/local/bin/docker-php-source \
      /usr/local/bin/docker-php-ext-configure /usr/local/bin/docker-php-ext-install
rm -rf /usr/local/include/php /usr/local/lib/php/build /usr/local/php /usr/src/*

# --- MariaDB client: we only run mariadb-dump (backups) and mariadb (restore); keep those
# (and the mysql* spellings DatabaseTools falls back to) and drop the other ~25 tools.
cd /usr/bin
for f in mariadb* mysql*; do
    case "$f" in
        mariadb|mariadb-dump|mysql|mysqldump) ;;
        *) rm -f "$f" ;;
    esac
done
cd /

# --- Noto: fonts-noto-core carries ~190 files; the app will be translated, so every living
# script keeps its faces (including Nastaliq Urdu, Looped Lao/Thai, Rashi Hebrew). Only historic
# scripts (SignWriting, Tangut, Cuneiform, Hieroglyphs, Grantha) and the Display and Mono families
# (Noto Sans/Serif and DejaVu Mono cover those uses) go. fonts-noto-color-emoji is kept whole:
# the UI uses emoji as icons and user-entered text in PDFs can contain them.
cd /usr/share/fonts/truetype/noto
rm -f NotoSansSignWriting-* NotoSerifTangut-* NotoSansCuneiform-* NotoSansEgyptianHieroglyphs-* \
      NotoSansGrantha-* NotoSerifGrantha-* NotoSansDisplay-* NotoSerifDisplay-* NotoSansMono-*
cd /

# --- docs/man pages (keep each package's copyright file)
rm -rf /usr/share/man /usr/share/info /usr/share/lintian /usr/share/doc-base
find /usr/share/doc -type f ! -name copyright -delete 2>/dev/null || true
find /usr/share/doc -type d -empty -delete 2>/dev/null || true

# --- apt/dpkg caches
rm -rf /var/cache/apt /var/lib/apt/lists/* /var/log/apt /var/log/dpkg.log /var/log/alternatives.log
