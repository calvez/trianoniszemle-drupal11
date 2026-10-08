#!/usr/bin/env bash
# Runs the whole legacy migration on a freshly installed site and then does the
# follow-up work (sitemap, search index). Safe to re-run: existing nodes are skipped.
#
# Needs in .env: LEGACY_DB_* (or LEGACY_DB_PREFIX), LEGACY_FILES_PATH, LEGACY_PRIVATE_PATH.
# See docs/MIGRATION.md.   Usage: bash scripts/migrate-legacy.sh [extra tz:migrate options]
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${FORGE_PHP:-php}"
DRUSH="$PHP vendor/drush/drush/drush.php"
export DRUSH_OPTIONS_URI="${DRUSH_OPTIONS_URI:-https://trianoniszemle.hu}"

echo "==> 1/6 site is installed and configuration is in sync"
[ "$($DRUSH php:eval 'echo "ok";' 2>/dev/null)" = "ok" ] || { echo "Drupal does not bootstrap - install the site first (docs/DEPLOY-FORGE.md)"; exit 1; }
$DRUSH config:status --state=Different,"Only in sync dir" --format=list 2>/dev/null | head -5 | sed 's/^/   differs: /' || true

echo "==> 2/6 migrate (pre-flight checks run first)"
$DRUSH tz:migrate --yes "$@"

echo "==> 3/6 verify"
$DRUSH tz:migrate-verify

echo "==> 4/6 sitemap"
$DRUSH simple-sitemap:rebuild-queue >/dev/null
$DRUSH simple-sitemap:generate

echo "==> 5/6 search index (cron, 500 items per run)"
$DRUSH config:set search.settings index.cron_limit 500 -y >/dev/null
for i in 1 2 3 4 5; do $DRUSH cron >/dev/null 2>&1 || true; done
$DRUSH config:set search.settings index.cron_limit 100 -y >/dev/null
echo "   indexed nodes: $($DRUSH php:eval 'echo \Drupal::database()->query("select count(distinct sid) from {search_dataset} where type = :t", [":t" => "node_search"])->fetchField();')"

echo "==> 6/6 cache rebuild"
$DRUSH cache:rebuild
echo "==> migration finished"
