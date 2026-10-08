# Trianoni Szemle - website

Drupal 11 site for the journal *Trianoni Szemle* (Trianon Kutatóintézet Közhasznú Alapítvány): an archive of
issues, articles and authors with PDFs, plus a news blog and a few static pages. About 2,000 pieces of content.

Built to be **small and low-maintenance**: plain Drupal core, four well-established contributed modules, a custom
theme with no build step, configuration in git.

## Stack

| | |
|---|---|
| Drupal | 11.4 (`drupal/core-recommended`), PHP 8.3, MySQL 8 |
| Contributed modules | `pathauto`, `redirect`, `metatag` (+ `token`), `simple_sitemap` |
| Custom code | theme `web/themes/custom/tsz`, module `tz_import` (CSV import of new issues), module `tz_migrate` (one-off import of the old site) |
| Core features used | Views, Search, CKEditor 5, taxonomy, menus (no Media, Layout Builder or Contact modules) |
| Hosting | Laravel Forge (see `docs/DEPLOY-FORGE.md`) |

## Repository layout

```
composer.json / composer.lock   dependencies (commit the lock file!)
config/sync/                    all site configuration (content types, views, blocks, menus' settings ...)
web/                            document root
  sites/default/settings.php    environment-driven; NO secrets (reads .env)
  themes/custom/tsz/            the theme (plain CSS/JS, self-hosted Lato font)
  modules/custom/tz_import/     drush tz:import-csv
  modules/custom/tz_migrate/    drush tz:migrate, tz:migrate-verify
scripts/                        deploy.sh, migrate-legacy.sh, backup-offsite.sh, security-check.sh
docs/                           DEPLOY-FORGE.md, MIGRATION.md, MAINTENANCE.md
drush/drush.yml                 default site address for command line runs
assets/robots-append.txt        extra robots.txt lines (sitemap), added by composer scaffold
.env.example                    all settings the site understands
```

Not in git (see `.gitignore`): `.env`, `vendor/`, `web/core/`, contributed modules, uploaded files, private files.

## Content model

| Type | Fields | URL pattern |
|---|---|---|
| **Szerző** (`szerzo`) | name (title), photo, type (Szerző / Kapcsolódó személy), body | `/szerzo/{name}` |
| **Lapszám** (`lapszam`) | title (e.g. "2020 Emlékkönyv"), volume (`field_evfolyam`), issue label, sort number (`field_sorszam`), cover, body | `/{title}/{nid}` |
| **Cikk** (`cikk`) | title, subtitle, issue (→ Lapszám), authors (→ Szerző, several), page number, PDF (private file), body (rarely used) | `/{issue}/{first author}-{title}` |
| **Blogbejegyzés** (`blog_post`) | title, main image, category, body (summary = teaser), attachments | `/blog/{title}` |
| **Oldal** (`page`) | title, body, attachments | `/{title}` |

Listing pages are Views: `/evfolyamok` (issues by year), `/repertorium` (all articles, searchable), `/szerzok` (authors A-Z),
`/blog`. The homepage is node 5 ("Kezdőlap", an intro sentence) plus two view blocks. Search is Drupal core search.

Article PDFs are **private files** (served by Drupal at `/system/files/...`); everything else is public.

## Working with the site

```bash
composer install                     # dependencies
cp .env.example .env                 # then fill in DB_*, HASH_SALT, TRUSTED_HOSTS
vendor/bin/drush deploy -y           # database updates + config import + cache rebuild
vendor/bin/drush config:export -y    # after changing configuration in the UI: commit config/sync
vendor/bin/drush user:login          # one-time admin login link
```

*Adding a new issue.* Either by hand (create the Lapszám, then the Cikk items) or from a CSV:

```bash
# columns: cim, alcim, szerzok (separated by ;), lapszam, oldalszam, pdf   - PDFs go to private/import/
vendor/bin/drush tz:import-csv issue.csv --dry-run     # preview
vendor/bin/drush tz:import-csv issue.csv               # import (re-running is safe)
```

Editors get the `content_editor` role (create/edit/delete all five types, publish, upload documents).

## Documentation

* `docs/DEPLOY-FORGE.md` - set up and deploy on Laravel Forge
* `docs/MIGRATION.md` - import the old (Drupal 8) site: `drush tz:migrate`
* `docs/MAINTENANCE.md` - the monthly routine, weekly security check, backups, upgrades

> **Warning.** `drush site:install` **drops every table in the database it points at.** Only run it on an empty database.
