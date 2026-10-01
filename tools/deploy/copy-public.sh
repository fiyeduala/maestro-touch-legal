#!/bin/sh
# Copies the web files (public/) into the folder the website is served from, for hosts where the main domain has to
# stay on public_html (docs/DEPLOYMENT.md, layout B). server-update.sh runs it on every update once set up.
# First time, from the app folder:   sh tools/deploy/copy-public.sh ~/public_html
set -eu

cd "$(dirname "$0")/../.."
APP=$(pwd)

if [ $# -ge 1 ]; then
    [ -d "$1" ] || { echo "Folder not found: $1"; exit 1; }
    printf "<?php return '%s';\n" "$(cd "$1" && pwd)" > public-path.php
fi
if [ ! -f public-path.php ]; then
    echo "Not set up yet. Run once with the web folder, e.g.:  sh tools/deploy/copy-public.sh ~/public_html"
    exit 1
fi
TARGET=$(php -r 'echo rtrim(require "public-path.php", "/");')
if [ "$TARGET" = "$APP/public" ]; then
    echo "The web folder is the app's own public/ folder; nothing to copy."
    exit 0
fi

# cPanel may keep the PHP version setting in .htaccess; carry it over to the new one.
HANDLER=""
if [ -f "$TARGET/.htaccess" ]; then
    HANDLER=$(sed -n '/# php -- BEGIN cPanel-generated handler/,/# php -- END cPanel-generated handler/p' "$TARGET/.htaccess")
fi

# Adds and replaces files only. Nothing is deleted, so uploads in media/ and cPanel's own files stay.
cp -R public/. "$TARGET/"
if [ -n "$HANDLER" ]; then
    printf '\n%s\n' "$HANDLER" >> "$TARGET/.htaccess"
fi
printf "<?php return '%s';\n" "$APP" > "$TARGET/app-path.php"

echo "Web files copied to $TARGET."
