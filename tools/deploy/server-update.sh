#!/bin/sh
# Updates this copy of the site (staging or production) to the latest code on GitHub.
# Run it in cPanel Terminal from the app folder:   sh tools/deploy/server-update.sh
# See docs/DEPLOYMENT.md, section 6.
set -eu

cd "$(dirname "$0")/../.."
BRANCH="${DEPLOY_BRANCH:-main}"

if [ ! -f .env ]; then
    echo "No .env in $(pwd). Set the site up first (docs/DEPLOYMENT.md, section 4)."
    exit 1
fi

# Files edited by hand on the server would be overwritten or block the pull; stop and say so instead.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
    echo "These files were changed on the server, so nothing was updated:"
    git status --short --untracked-files=no
    echo "Make the change on the local computer and push it instead. To throw the server edits away:"
    echo "  git checkout -- <file>"
    exit 1
fi

OLD=$(git rev-parse HEAD)
git fetch --quiet origin "$BRANCH"
NEW=$(git rev-parse "origin/$BRANCH")

if [ "$OLD" = "$NEW" ]; then
    echo "Already up to date ($(git log -1 --format='%h %s' HEAD))."
    exit 0
fi

echo "Updating from $(git log -1 --format='%h' "$OLD") to $(git log -1 --format='%h %s' "$NEW")"

# The PHP libraries (vendor/) are not in Git. They only need refreshing when composer.lock changes.
LIBRARIES_CHANGED=no
if ! git diff --quiet "$OLD" "$NEW" -- composer.lock; then
    LIBRARIES_CHANGED=yes
    if ! command -v composer >/dev/null 2>&1; then
        echo "This update changes the PHP libraries, and Composer is not available here."
        echo "Nothing was changed. Extract the matching mtl-vendor zip into this folder first"
        echo "(its MANIFEST names the commit), then run this script again with:"
        echo "  SKIP_COMPOSER=1 sh tools/deploy/server-update.sh"
        [ "${SKIP_COMPOSER:-0}" = "1" ] || exit 1
    fi
fi

if ! php artisan mtl:backup; then
    echo "WARNING: no backup was taken (is BACKUP_PASSWORD set?). On production, stop here and fix that first."
    printf "Continue without a backup? Type yes: "
    read -r ANSWER
    [ "$ANSWER" = "yes" ] || exit 1
fi

php artisan down --retry=60
trap 'echo; echo "The update stopped part-way. The site is still in maintenance mode."; echo "Fix the error above and run this script again, or see docs/ROLLBACK.md (previous version: $OLD)."' EXIT

git merge --ff-only --quiet "origin/$BRANCH"

if [ "$LIBRARIES_CHANGED" = "yes" ] && [ "${SKIP_COMPOSER:-0}" != "1" ]; then
    composer install --no-dev --optimize-autoloader --no-interaction --no-progress
fi

# Layout B: the website is served from another folder (public_html), so copy the new web files there.
if [ -f public-path.php ]; then
    sh tools/deploy/copy-public.sh
fi

php artisan migrate --force
php artisan filament:assets
php artisan optimize:clear
php artisan optimize

trap - EXIT
php artisan up
echo "Done. Now on $(git log -1 --format='%h %s' HEAD). Previous version: $OLD"
