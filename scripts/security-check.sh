#!/usr/bin/env bash
# Weekly: e-mail when composer knows a security advisory for a locked package. Silent when clean.
# Needs ALERT_EMAIL (environment or .env) and a working `mail` or `sendmail` on the server.
# Schedule weekly (Forge scheduler, as the site user):  bash /home/forge/<site>/scripts/security-check.sh
set -uo pipefail
cd "$(dirname "$0")/.."
COMPOSER="${FORGE_COMPOSER:-composer}"
EMAIL="${ALERT_EMAIL:-$(grep -E '^ALERT_EMAIL=' .env 2>/dev/null | head -1 | cut -d= -f2-)}"
OUT=$($COMPOSER audit --no-dev --locked --no-interaction 2>&1); CODE=$?
if [ $CODE -eq 0 ]; then echo "no known security advisories ($(date +%F))"; exit 0; fi
echo "$OUT"
SUBJECT="[trianoniszemle] security advisories found ($(date +%F))"
BODY="composer audit reported advisories for packages in composer.lock.

$OUT

Update (see docs/MAINTENANCE.md): composer update drupal/core-recommended drupal/core-composer-scaffold drupal/core-project-message --with-all-dependencies, test, commit composer.lock, deploy."
if [ -n "$EMAIL" ]; then
  if command -v mail >/dev/null 2>&1; then printf '%s\n' "$BODY" | mail -s "$SUBJECT" "$EMAIL"
  elif command -v sendmail >/dev/null 2>&1; then printf 'To: %s\nSubject: %s\n\n%s\n' "$EMAIL" "$SUBJECT" "$BODY" | sendmail -t
  else echo "no mail/sendmail on this server - nothing sent"; fi
fi
exit 1
