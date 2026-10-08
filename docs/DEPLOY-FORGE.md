# Deploying on Laravel Forge

> Forge's screens change from time to time - the names below may differ slightly. The repository side (settings from
> `.env`, `scripts/deploy.sh`) does not depend on Forge. **Status:** the migration, deploy script, backup and security
> scripts were tested on the staging server; the very first install on a fresh Forge server (steps 1-5) follows the
> standard Drupal procedure but has not been rehearsed on Forge itself.

## 1. Server and site

* Server: PHP **8.3** (extensions: `gd`, `mbstring`, `xml`, `curl`, `zip`, `mysql`, `opcache`; `intl` is optional), MySQL 8, nginx.
* Create the site: domain `trianoniszemle.hu` (+ `www`), project type *General PHP*, **web directory `/web`**, PHP 8.3.
* Repository: your git remote, branch `main`. Leave "install composer dependencies" **off** - `scripts/deploy.sh` does it.
* Database: create one for the site (e.g. `trianoniszemle`) and, for the migration, a second one (`trianoniszemle_legacy`).

## 2. Environment

Paste `.env.example` into *Site -> Environment* and fill in the values:

* `APP_ENV=production`, `DB_*`, `TRUSTED_HOSTS=trianoniszemle.hu,www.trianoniszemle.hu`
* `HASH_SALT` - generate once: `php -r 'echo bin2hex(random_bytes(32));'` (never change it afterwards)
* `PRIVATE_FILES_PATH` - a folder **outside** `web/`, e.g. `/home/forge/trianoniszemle.hu/private` (create it, writable by `forge`)

## 3. First install (empty database only!)

```bash
ssh forge@server
cd ~/trianoniszemle.hu
git pull origin main
composer install --no-dev --prefer-dist --optimize-autoloader
mkdir -p private
# WARNING: site:install DROPS ALL TABLES in the database in .env
vendor/bin/drush site:install --existing-config --locale=hu -y \
    --account-name=admin --account-mail=you@example.com --site-name="Trianoni Szemle"
vendor/bin/drush locale:check && vendor/bin/drush locale:update     # Hungarian admin interface
vendor/bin/drush deploy -y
```

`--existing-config` installs the site from `config/sync`, so content types, views, blocks and theme settings arrive as
they are in git. The command prints the generated admin password - change it at once (`drush user:password admin '...'`).

## 4. Content: migrate the old site

Follow `docs/MIGRATION.md` (about 3 minutes of work plus copying the old files). Do this before the cutover; the old
site keeps running meanwhile.

## 5. Deployment script

*Site -> Deployments -> Deployment Script* - replace the default with:

```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH
export FORGE_PHP FORGE_COMPOSER FORGE_PHP_FPM
bash scripts/deploy.sh
```

Turn on *Quick Deploy* if every push to `main` should go live. `scripts/deploy.sh` runs `composer install --no-dev`,
`drush deploy` (database updates, config import, cache rebuild) and reloads PHP-FPM so OPcache serves the new code
(**without the reload the site keeps running old code**). Set `DEPLOY_MAINTENANCE=1` in the script for maintenance mode
during the deploy.

## 6. nginx

Forge's default `try_files $uri $uri/ /index.php?$query_string;` is what Drupal needs. In *Site -> Files -> Edit Nginx
Configuration* add inside the `server` block:

```nginx
# Never execute PHP or serve dumps from the upload folders
location ~* ^/sites/.*/files/.*\.php$ { deny all; }
location ~* \.(sql|gz|tar|zip|bak|orig|swp)$ { deny all; }
# Long cache for theme and uploaded files
location ~* ^/(sites/default/files|themes)/.*\.(jpg|jpeg|png|gif|webp|avif|svg|css|js|woff2)$ { expires 30d; access_log off; }
```

(The `deny` for `.zip` etc. also blocks download links to such files - they are not used on this site.) Reload nginx.

## 7. Scheduler, SSL, backups

* **Scheduler** (as user `forge`), every 30 minutes - runs Drupal cron (search indexing, sitemap, cleanup):
  `cd /home/forge/trianoniszemle.hu && DRUSH_OPTIONS_URI=https://trianoniszemle.hu /usr/bin/php8.3 vendor/drush/drush/drush.php cron`
* **SSL:** Let's Encrypt in Forge; redirect `www` to the bare domain (or the reverse) in Forge.
* **Backups:** Forge's database backups cover the database only. Files (uploads and the article PDFs in `private/`) need
  a copy too: set `BACKUP_*` in the environment and schedule `bash /home/forge/trianoniszemle.hu/scripts/backup-offsite.sh`
  nightly (database dump + incremental file copy to any SSH server; used with a Hetzner Storage Box). **Test a restore once.**
* **Weekly security mail:** set `ALERT_EMAIL` and schedule `bash .../scripts/security-check.sh` weekly (needs `mail`/`sendmail`).

## 8. Cutover checklist

1. Staging copy complete, `drush tz:migrate-verify` all OK and `bash scripts/smoke-test.sh https://<staging-address>` passes (add `SAMPLE_PDF=/system/files/...`); then look at it in a browser, on a phone too.
2. Re-import the **latest** old database dump and run `bash scripts/migrate-legacy.sh` again - existing items are skipped, anything new on the old site is added.
3. Lower the DNS TTL a day before; keep the old server running.
4. Point DNS to the Forge server; request the certificate; run `bash scripts/smoke-test.sh https://trianoniszemle.hu` (also confirms `/sitemap.xml` and `/robots.txt`).
5. Submit the sitemap in Google Search Console. Old URLs keep working (same paths + 1,900 redirects).
6. After a week without problems: follow "After go-live" in `docs/MIGRATION.md`.

**Rollback:** point DNS back to the old server (it was never touched).
