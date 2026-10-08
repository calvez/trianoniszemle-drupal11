#!/usr/bin/env bash
# Nightly off-server backup: database dump + public/private files, sent incrementally
# (rsync over SSH) to a remote folder. Tested with a Hetzner Storage Box (SSH port 23).
#   - database dumps:           kept 90 days
#   - changed/deleted files:    kept 30 days in <remote>/deleted/
# Settings (environment or .env): BACKUP_SSH_TARGET (user@host), BACKUP_SSH_PORT (22),
# BACKUP_SSH_KEY, BACKUP_REMOTE_DIR (default: trianoniszemle).
# Schedule (Forge scheduler, as the site user):  bash /home/forge/<site>/scripts/backup-offsite.sh
set -euo pipefail
cd "$(dirname "$0")/.."
PHP="${FORGE_PHP:-php}"
getv() { if [ -n "${!1:-}" ]; then echo "${!1}"; else grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; fi; }
TARGET=$(getv BACKUP_SSH_TARGET); PORT=$(getv BACKUP_SSH_PORT); KEY=$(getv BACKUP_SSH_KEY); REMOTE=$(getv BACKUP_REMOTE_DIR)
: "${TARGET:?BACKUP_SSH_TARGET is not set}"; PORT=${PORT:-22}; REMOTE=${REMOTE:-trianoniszemle}
SSH="ssh -p $PORT ${KEY:+-i $KEY -o IdentitiesOnly=yes} -o BatchMode=yes -o ConnectTimeout=30"
STAMP=$(date +%F)
TMP=$(mktemp -d); trap 'rm -rf "$TMP"' EXIT

echo "==> database dump"
$PHP vendor/drush/drush/drush.php sql:dump --gzip --extra-dump=--no-tablespaces --result-file="$TMP/$STAMP.sql"
gzip -t "$TMP/$STAMP.sql.gz"

echo "==> upload"
$SSH "$TARGET" "mkdir -p $REMOTE/db $REMOTE/files/public $REMOTE/files/private $REMOTE/deleted"
RS="rsync -a -e"
$RS "$SSH" "$TMP/$STAMP.sql.gz" "$TARGET:$REMOTE/db/"
PUB=$($PHP vendor/drush/drush/drush.php php:eval 'echo \Drupal::service("file_system")->realpath("public://");')
PRIV=$($PHP vendor/drush/drush/drush.php php:eval 'echo \Drupal::service("file_system")->realpath("private://");')
$RS "$SSH" --delete --backup --backup-dir="../../deleted/files-public-$STAMP" --exclude=styles/ --exclude=css/ --exclude=js/ --exclude=php/ "$PUB/" "$TARGET:$REMOTE/files/public/"
$RS "$SSH" --delete --backup --backup-dir="../../deleted/files-private-$STAMP" "$PRIV/" "$TARGET:$REMOTE/files/private/"

echo "==> prune (db > 90 days, deleted/changed files > 30 days)"
CUT90=$(date -d '-90 days' +%F); CUT30=$(date -d '-30 days' +%F)
for f in $($SSH "$TARGET" "ls $REMOTE/db" | grep -E '^[0-9]{4}-[0-9]{2}-[0-9]{2}\.sql\.gz$'); do
  [[ "${f:0:10}" < "$CUT90" ]] && $SSH "$TARGET" "rm $REMOTE/db/$f" && echo "pruned db/$f" || true
done
for d in $($SSH "$TARGET" "ls $REMOTE/deleted" | grep -E '^files-(public|private)-[0-9]{4}-[0-9]{2}-[0-9]{2}$'); do
  [[ "${d: -10}" < "$CUT30" ]] && $SSH "$TARGET" "rm -r $REMOTE/deleted/$d" && echo "pruned deleted/$d" || true
done
echo "==> backup ok $STAMP"
