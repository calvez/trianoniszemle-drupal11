#!/usr/bin/env bash
# Deployment steps for Laravel Forge (and for manual deploys).
#
# Forge -> Site -> Deployments -> "Deployment Script" (replace the default with):
#
#     cd $FORGE_SITE_PATH
#     git pull origin $FORGE_SITE_BRANCH
#     export FORGE_PHP FORGE_COMPOSER FORGE_PHP_FPM
#     bash scripts/deploy.sh
#
# By hand on the server:  bash scripts/deploy.sh
set -euo pipefail
cd "$(dirname "$0")/.."

PHP="${FORGE_PHP:-php}"
COMPOSER="${FORGE_COMPOSER:-composer}"
FPM="${FORGE_PHP_FPM:-php8.3-fpm}"
export DRUSH_OPTIONS_URI="${DRUSH_OPTIONS_URI:-https://trianoniszemle.hu}"

echo "==> composer install (no dev packages)"
$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

echo "==> drush deploy: database updates, config import, cache rebuild"
# DEPLOY_MAINTENANCE=1 puts the site in maintenance mode while this runs.
if [ "${DEPLOY_MAINTENANCE:-0}" = "1" ]; then
  $PHP vendor/drush/drush/drush.php state:set system.maintenance_mode 1 --input-format=integer -y
  trap '$PHP vendor/drush/drush/drush.php state:set system.maintenance_mode 0 --input-format=integer -y' EXIT
fi
$PHP vendor/drush/drush/drush.php deploy -y

echo "==> reload PHP-FPM so OPcache serves the new code"
( flock -w 10 9 || exit 1
  sudo -S service "$FPM" reload ) 9>/tmp/fpmlock

echo "==> done"
