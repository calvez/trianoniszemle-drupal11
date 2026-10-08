# Maintenance

Drupal has **no long-term-support release**: each minor version (11.4, 11.5, ...) is supported for roughly a year and only
two minors are supported at a time; security releases come out on Wednesdays, sometimes urgently. So "set and forget" means
a *small routine*, not no routine. The site is kept small (core + 4 modules) so each step is quick.

## Weekly (automatic)

`scripts/security-check.sh` e-mails when `composer audit` knows a security advisory for a locked package (see
`docs/DEPLOY-FORGE.md` step 7). A "highly critical" Drupal core advisory should be applied **within days**, not at the next
monthly slot.

## Monthly (about 20 minutes)

Do this on a development copy (never edit `vendor/` on the server), then deploy:

```bash
git pull
composer install
composer update drupal/core-recommended drupal/core-composer-scaffold drupal/core-project-message --with-all-dependencies
composer update "drupal/*" drush/drush --with-all-dependencies      # contributed modules + drush
vendor/bin/drush updatedb -y && vendor/bin/drush config:export -y
composer audit --no-dev                                              # must report nothing
bash scripts/smoke-test.sh https://trianoniszemle.hu       # key pages, search, sitemap, robots, login (set SAMPLE_PDF=/system/files/... to test a PDF too)
# then log in as an editor and open /node/add/cikk once
git add composer.lock config && git commit -m "Monthly update" && git push      # Forge deploys (Quick Deploy) or click Deploy
```

After the deploy: `vendor/bin/drush watchdog:show --severity=Error` should be empty, and the status report
(`/admin/reports/status`) should show no errors.

**Rollback:** `git revert` the update commit, push, deploy. If a database update misbehaved, restore last night's dump
(see Backups) - that is why the deploy should happen *after* the nightly backup, not before it.

## Quarterly

* Restore test: load the latest database dump from the backup into a scratch database and open the site against it; check
  that a PDF from `files/private/` opens.
* Check that the backup job really ran (`<remote>/db/` has yesterday's date) and that disk space is fine.
* Review users: remove editors who left (`/admin/people`).

## Backups

* **Forge:** database backups (set a destination and a schedule) - covers the database only.
* **`scripts/backup-offsite.sh`:** database dump + incremental copy of public and private files to an SSH server. Database
  dumps are kept 90 days; changed or deleted files 30 days. This is what protects the article PDFs.
* The key used for the backup server has full access to that account; if the web server is compromised, the backups can be
  deleted. Enable snapshots on the backup server (Hetzner Storage Box: Snapshots) or use a restricted sub-account.

## Upgrades

* **Minor versions** (11.4 -> 11.5): the monthly routine above handles them. Do not stay on an unsupported minor for more
  than a couple of months.
* **Major version** (Drupal 12, expected around the end of 2026): check `composer why-not drupal/core-recommended ^12`; the
  modules in use are mature and the theme has no dependencies, so it should be a small job. Do it on a copy first; run
  `drush pm:list --status=enabled` to see what must be compatible.
* **PHP:** stay on a supported version (`php.net/supported-versions.php`); change it per site in Forge and re-test.

## Where things are

* Settings and secrets: `.env` on the server (not in git). Configuration: `config/sync` (in git).
* Content is **not** in git - it lives in the database (backed up nightly) and `web/sites/default/files` + `private/`.
* Editors: role `content_editor`; admin account `admin`. New issue: by hand or `drush tz:import-csv` (see README).
