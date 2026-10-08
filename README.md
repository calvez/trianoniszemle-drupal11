# Trianoni Szemle - website

The website of the journal **Trianoni Szemle** (publisher: Trianon Kutatóintézet Közhasznú Alapítvány): a searchable
archive of every issue, article and author (with the article PDFs), a news blog and a handful of static pages.

* Drupal **11.4**, PHP **8.3**, MySQL **8**, hosted on Laravel Forge
* ~2,000 pieces of content: 509 authors, 43 issues, 1,355 articles (201 with a PDF), 98 blog posts, 10 pages
* Hungarian site and admin interface; every URL of the previous (Drupal 8) site still works
* Designed to be **small and low-maintenance**: plain Drupal core, four well-established contributed modules,
  a hand-written theme without a build step, all configuration in git

## Contents

1. [Status](#1-status)
2. [Architecture and design decisions](#2-architecture-and-design-decisions)
3. [Requirements](#3-requirements)
4. [Quick start](#4-quick-start)
5. [Configuration reference](#5-configuration-reference)
6. [Repository layout](#6-repository-layout)
7. [Content model](#7-content-model)
8. [Pages, navigation, search, sitemap](#8-pages-navigation-search-sitemap)
9. [Theme](#9-theme)
10. [Custom modules](#10-custom-modules)
11. [Editors: users, roles, daily work](#11-editors-users-roles-daily-work)
12. [Operations](#12-operations)
13. [Migration of the old site](#13-migration-of-the-old-site)
14. [Verification and testing](#14-verification-and-testing)
15. [Troubleshooting and gotchas](#15-troubleshooting-and-gotchas)
16. [Glossary](#16-glossary)

Further guides: [`docs/DEPLOY-FORGE.md`](docs/DEPLOY-FORGE.md) · [`docs/MIGRATION.md`](docs/MIGRATION.md) · [`docs/MAINTENANCE.md`](docs/MAINTENANCE.md)

> **Warning.** `drush site:install` **drops every table in the database it points at.** Run it only on an empty database.

---

## 1. Status

State on 2026-10-08. The new site runs on a staging server (`trianon.clvz.dev`); the old site is still the live one
(`trianoniszemle.hu`) and has not been touched.

**Verified**

* The complete migration was run from scratch on the staging server (about 2.5 minutes) and `drush tz:migrate-verify` passes.
* Every old URL resolves: all 2,013 published aliases return 200, about 1,930 old redirects land on a live page.
  The only non-200 answers are 403s for one unpublished article and one unpublished blog post (also hidden on the old site).
* Text and image coverage compared with the live site, page by page (150 pages): blog 99.4 %, articles 99.8 %, issues 100 %.
* A clean `git clone` installs from `composer.lock` and contains every module, theme and asset the configuration needs.
* Backups (database + files, incremental, off-server) run nightly and a restore was tested.

**Not yet done**

* A first install on a *completely empty* database with `drush site:install --existing-config` (the step Forge will do) has not been
  rehearsed end to end; the configuration is complete according to the clean-clone check.
* The contact form that was on the *Rólunk* page was not carried over (Drupal's Contact module is not enabled).
* Three files are missing on the old live site as well and therefore cannot be migrated (see [§13](#13-migration-of-the-old-site)).
* About half of the photos inside blog posts have no alt text.

## 2. Architecture and design decisions

**Plain Drupal core, not a distribution.** The site is a journal archive; it needs content types, listings, search and a
good editor. It does not need a page builder, a media library or layout tools. Fewer parts means fewer updates and fewer
things that can break between monthly check-ups.

| Layer | What is used |
|---|---|
| Drupal core | `node`, `taxonomy`, `views`, `search` + `search_node`, `ckeditor5`, `file`, `image`, `path`, `block`, `menu_ui`, `language` + `locale` (Hungarian), `update`, `dblog`, `page_cache` + `dynamic_page_cache` + `big_pipe`, `toolbar`, `field_ui`, `views_ui`, the `standard` profile |
| Contributed (4 + 1) | [`pathauto`](https://www.drupal.org/project/pathauto) URL aliases, [`redirect`](https://www.drupal.org/project/redirect) 301s, [`metatag`](https://www.drupal.org/project/metatag) page titles/social tags, [`simple_sitemap`](https://www.drupal.org/project/simple_sitemap) `/sitemap.xml`, `token` (required by the others) |
| Custom | theme `tsz`, module `tz_import` (CSV import of new issues), module `tz_migrate` (one-off import of the old site, removable) |
| Not used on purpose | Media/Media Library (images and PDFs are plain file fields), Layout Builder, Paragraphs/Droopler (the old site's page builder), Feeds/CSV modules, Contact, comments, Google Analytics, cookie banner |

Other decisions worth knowing:

* **Configuration is code.** Content types, fields, views, blocks, text formats, roles and settings live in `config/sync` and are
  applied on every deploy (`drush deploy`). Change them in the UI on a copy, `drush config:export`, commit.
* **Content is data.** Nodes and files live in the database and in `web/sites/default/files` + `private/` - they are backed up, not in git.
* **No secrets in git.** `settings.php` is committed but reads everything server-specific from `.env` / the environment.
* **Article PDFs are private files** (outside the web root, served by Drupal), everything else is public.
* **Old node IDs and URLs are kept**, so external links and search-engine results keep working.
* **No front-end build.** One CSS file, one JS file, self-hosted Lato font; no npm, no external requests (no Google Fonts, no analytics).
* **Drupal has no long-term-support release** (about one year of support per minor version). The answer is a small, regular
  routine ([`docs/MAINTENANCE.md`](docs/MAINTENANCE.md)), not a clever one.

## 3. Requirements

* PHP 8.3 with `gd`, `mbstring`, `xml`, `curl`, `zip`, `pdo_mysql`, `opcache` (`intl` optional)
* MySQL 8 (or MariaDB 10.6+) with a `utf8mb4` database
* nginx or Apache with the document root set to `web/`
* Composer 2, `git`, a shell with `rsync` (for the scripts)
* Disk: the code is about 220 MB; public files ~180 MB; private files (PDFs) ~140 MB

## 4. Quick start

Set up a working copy (development, staging or production server):

```bash
git clone <repository> trianoniszemle && cd trianoniszemle
composer install                                   # production: composer install --no-dev --optimize-autoloader
cp .env.example .env                               # fill in DB_*, HASH_SALT, TRUSTED_HOSTS (see §5)
mkdir -p private                                   # private files, outside web/
```

Then **either** restore an existing copy of the site:

```bash
zcat site.sql.gz | mysql <database>                # a database dump of the site
rsync -a <backup>/files/public/  web/sites/default/files/
rsync -a <backup>/files/private/ private/
vendor/bin/drush deploy -y                         # pending updates + config import + cache rebuild
```

**or** build it from scratch (empty database!) and import the old site:

```bash
vendor/bin/drush site:install --existing-config --locale=hu -y --account-name=admin --account-mail=you@example.com
vendor/bin/drush locale:check && vendor/bin/drush locale:update      # Hungarian interface strings
vendor/bin/drush deploy -y
bash scripts/migrate-legacy.sh                     # needs LEGACY_* settings, see docs/MIGRATION.md
```

Log in as admin: `vendor/bin/drush user:login` prints a one-time link. Production deployment is described in
[`docs/DEPLOY-FORGE.md`](docs/DEPLOY-FORGE.md). Try it locally without a web server: `vendor/bin/drush runserver 127.0.0.1:8080`.

> `vendor/bin/drush` is a small bash launcher. In scripts that must use a specific PHP, call
> `php8.3 vendor/drush/drush/drush.php <command>` instead (the scripts in `scripts/` do).

## 5. Configuration reference

`web/sites/default/settings.php` reads each value from the **process environment first, then from `.env`** in the project root
(one `KEY=value` per line; Forge writes this file). Defaults are in `.env.example`.

| Variable | Required | Meaning |
|---|---|---|
| `APP_ENV` | no | `local` shows PHP errors; anything else (default `production`) hides them |
| `DB_NAME`, `DB_USER`, `DB_PASSWORD` | **yes** | database credentials |
| `DB_HOST` / `DB_PORT` / `DB_PREFIX` | no | default `127.0.0.1` / `3306` / empty (an explicitly empty value is respected) |
| `HASH_SALT` | **yes** | secret; 64 random hex characters (`php -r 'echo bin2hex(random_bytes(32));'`); never change it later |
| `TRUSTED_HOSTS` | recommended | comma separated host names the site answers to |
| `PRIVATE_FILES_PATH` | no | default `<project>/private` - must be outside the web root and writable |
| `PUBLIC_FILES_PATH` | no | path relative to `web/`, default `sites/default/files` |
| `REVERSE_PROXY_ADDRESSES` | no | comma separated IPs of a load balancer/CDN in front of the site |
| `DRUSH_OPTIONS_URI` | no | site address for command-line runs (cron, sitemap, e-mails); default in `drush/drush.yml` |
| `LEGACY_DB_NAME`/`_USER`/`_PASSWORD`/`_HOST`/`_PORT`/`_PREFIX` | migration only | where the old site's tables are (defaults to the main DB credentials; old prefix was `trn_`) |
| `LEGACY_FILES_PATH`, `LEGACY_PRIVATE_PATH`, `LEGACY_FALLBACK_FILES_PATH` | migration only | the old site's file folders |
| `BACKUP_SSH_TARGET`, `BACKUP_SSH_PORT`, `BACKUP_SSH_KEY`, `BACKUP_REMOTE_DIR` | backup script | off-server backup destination |
| `ALERT_EMAIL` | security script | receives the weekly advisory mail |

Fixed in `settings.php`: config sync directory `../config/sync`, `update_free_access` off, entity update batch size 50,
and (when `settings.local.php` exists next to it) a git-ignored per-machine override.

Site-level configuration (in `config/sync`): site language Hungarian, time zone `Europe/Budapest`, front page `/node/5`,
anonymous page cache 6 hours, CSS/JS aggregation on, update notifications to the admin e-mail.

## 6. Repository layout

```
composer.json, composer.lock      dependencies - always commit the lock file
.env.example                      every setting the site understands (copy to .env)
config/sync/                      all configuration (250 files): content types, fields, views, blocks, roles, formats ...
web/                              document root
  sites/default/settings.php      environment driven, NO secrets
  themes/custom/tsz/              the theme (templates, css/tsz.css, js/tsz.js, fonts, images)
  modules/custom/tz_import/       drush tz:import-csv
  modules/custom/tz_migrate/      drush tz:migrate, tz:migrate-verify
  modules/contrib/, core/         installed by composer (git-ignored)
scripts/
  deploy.sh                       Forge deployment script body (composer install, drush deploy, FPM reload)
  migrate-legacy.sh               whole legacy migration + sitemap + search index
  backup-offsite.sh               nightly database + files backup to an SSH server (incremental)
  security-check.sh               weekly composer security-advisory mail
  smoke-test.sh                   post-deploy check of the key pages
docs/                             DEPLOY-FORGE.md, MIGRATION.md, MAINTENANCE.md
drush/drush.yml                   default site address for command line runs
assets/robots-append.txt          extra robots.txt lines (sitemap), applied by composer scaffold
```

Not in git (see `.gitignore`): `.env`, `vendor/`, `web/core/`, contributed modules, uploaded files, `private/`.

## 7. Content model

All five types are in `config/sync`; fields are plain core fields.

**Szerző** (`szerzo`) - an author or a related person

| Field | Machine name | Notes |
|---|---|---|
| Név | `title` | |
| Típus | `field_tipus` | list: *Szerző* / *Kapcsolódó személy* |
| Fénykép | `field_kep` | image, max 1200 px |
| Szöveg | `body` | short biography (most authors have none; the list shows a placeholder text) |

**Lapszám** (`lapszam`) - one issue / yearbook / special issue

| Field | Machine name | Notes |
|---|---|---|
| Cím | `title` | e.g. "2020 Emlékkönyv", "2024 Különszám 3" |
| Évfolyam | `field_evfolyam` | year, used for grouping on `/evfolyamok` |
| Lapszám jelölése | `field_lapszam` | issue label |
| Sorszám (rendezéshez) | `field_sorszam` | sorting number (year x 100 + number for CSV imports) |
| Címlap | `field_kep` | cover image |
| Szöveg | `body` | optional |

**Cikk** (`cikk`) - one article (mostly an index entry: who, where, which page; optionally a PDF)

| Field | Machine name | Notes |
|---|---|---|
| Cím | `title` | |
| Alcím | `field_alcim` | subtitle |
| Lapszám | `field_szam` | **required** reference to a Lapszám |
| Szerző(k) | `field_szerzo` | references to Szerző (several allowed); a new author can be created from the form |
| Oldalszám | `field_oldalszam` | first page |
| PDF | `field_pdf` | **private** file, `.pdf` only |
| Szöveg | `body` | rarely used (23 of 1,355) |

**Blogbejegyzés** (`blog_post`) - news, events, announcements

| Field | Machine name | Notes |
|---|---|---|
| Cím | `title` | |
| Főkép | `field_kep` | main image (shown above the text, `wide` image style) |
| Kategória | `field_kategoria` | vocabulary *Blog kategória* (`blog_kategoria`), optional |
| Szöveg | `body` | HTML; the **summary** is the teaser shown in listings |
| Letölthető dokumentumok | `field_csatolmany` | attachments (pdf, doc(x), xls(x), ppt(x), odt, ods, txt, zip), shown as a download list |

**Oldal** (`page`) - static pages: `title`, `body`, `field_csatolmany`. Node 5 is the homepage text.

**URL patterns** (Pathauto; the old site's aliases are preserved for existing content):

| Type | Pattern | Example |
|---|---|---|
| Cikk | `/{issue title}/{first author}-{title}` | `/2016-evkonyv/buczko-jozsef-szallast-adtunk-huseges-magyar-vereinknek` |
| Lapszám | `/{title}/{nid}` | `/2020-emlekkonyv/68` |
| Szerző | `/szerzo/{name}` | `/szerzo/acs-margit` |
| Blog | `/blog/{title}` | `/blog/kituntetes-szidiropulosz-archimedesznek` |
| Oldal | `/{title}` | `/rolunk` |

**Text formats and editor:** *Full HTML* (default for editors; CKEditor 5 with bold/italic/strike, super/subscript, links, lists,
quotes, images, tables, horizontal rule, headings, code block, source editing), *Basic HTML* (any logged-in user),
*Restricted HTML* (anonymous), *Plain text*. Images uploaded in the editor go to `inline-images/`, are limited to **10 MB**
and are scaled to **1600 px**. Images use `loading="lazy"` automatically.

**Files:** public files in `web/sites/default/files`, image derivatives in `.../styles/` (generated on demand, safe to delete);
private files (article PDFs) in `private/` and served at `/system/files/...`. The effective upload limit is the smaller of the
field limit and PHP's `upload_max_filesize` / `post_max_size`.

## 8. Pages, navigation, search, sitemap

| URL | What | Built with |
|---|---|---|
| `/` | homepage: intro (node 5), latest 4 issues, latest 4 blog posts | node + view blocks `lapszamok:block_latest`, `blog:block_latest` |
| `/evfolyamok` | all issues grouped by year, cover cards | view `lapszamok` |
| `/{issue}/{nid}` | an issue: cover + contents list ("7. oldal  Author: Title  PDF") | node template + view block `cikkek:block_issue` |
| `/{issue}/{author}-{title}` | an article: authors, issue, page, PDF button (or "PDF nem elérhető"), optional body | node template `node--cikk` |
| `/szerzok` | author cards with A-Z filter (`?betu=A`) | view `szerzok` + `tsz_preprocess_views_view__szerzok` |
| `/szerzo/{name}` | an author: photo, bio, list of their articles | node template + view block `cikkek:block_author` |
| `/repertorium` | every article as a table, 50 per page, filters `?szoveg=` (title/subtitle) and `?szerzo=` | view `repertorium` |
| `/blog`, `/blog/{title}` | news: cards with date, image, teaser (10 per page); full post | view `blog` + node templates |
| `/rolunk`, `/kapcsolat`, `/kuratorium`, `/kuratorium-dontesei`, `/szerkesztoseg`, `/szabalyzoink`, `/tamogatoink`, `/beszamolok`, `/adatkezelesi-tajekoztato` | static pages | `page` nodes |
| `/search/node?keys=...` | site search (core search) | `search_node`; indexed by cron, 100 items per run |
| `/sitemap.xml` | sitemap (~2,000 URLs, split in pages) | `simple_sitemap`, regenerated by cron |
| `/robots.txt` | core robots.txt + `Disallow: /search/` + sitemap line | composer scaffold append |
| `/system/files/...` | article PDFs | core private file system |
| `/user/login`, `/admin/...` | editors and admin | Claro admin theme |

Navigation: **main menu** *Évfolyamok · Hírek események · Repertórium · Szerzők · Rólunk (Kapcsolat, Kuratórium, A kuratórium
döntései, Szerkesztőség, Szabályzóink) · Támogatóink*; **footer menu** *Adatkezelési tájékoztató · Támogatóink*. The footer text
(address, tax number, bank account) is an editable custom block ("Lábléc - elérhetőség"). `/kezdolap` redirects to `/`.
Old URLs: all old aliases are unchanged and ~1,930 old redirects are imported (`/admin/config/search/redirect`).

## 9. Theme

`web/themes/custom/tsz` is a self-contained theme (it does not extend another theme) built from Drupal's starter kit.
Plain CSS and JS, no build step; edit the files and clear the cache.

* **Look:** slate header with a red/green double rule and white menu tabs, green hero band with the page title on content pages,
  card grids (issues, authors, blog), Lato font (regular, italic, bold, black; latin + latin-ext, self-hosted in `fonts/`).
* **Design tokens** (top of `css/tsz.css`): `--slate #343a40`, `--red #dc3545`, `--green #436f4d`, `--green-dark #0f520e`,
  `--cyan #65e2ff`, `--text #212529`, `--muted #4d5358`, `--surface #f6f7f6`, container 1140 px. Change colours there.
* **Regions** (`tsz.info.yml`): `header`, `search`, `primary_menu`, `hero` (page title), `highlighted` (messages, tabs), `help`,
  `content`, `footer_text`, `footer_menu`. The logo falls back to `images/header_logo.svg`; the homepage hero is `images/tsz_home_logo.svg`.
* **Templates** (custom ones in `templates/`): `layout/page`, `content/node--cikk`, `--lapszam`, `--szerzo`, `--blog-post`,
  `--blog-post--teaser`, `--page`, `views/views-view-fields--cikkek--block-issue`, `--block-author`, `--szerzok`, `views-view--szerzok`.
  `tsz.theme` embeds the contents lists into issue/author pages and builds the A-Z navigation from the real author names.
* **JavaScript** (`js/tsz.js`, progressive enhancement - works without it): `tszHeader` (mobile menu button, search toggle) and
  `tszGallery` (three or more consecutive photos in a text become a thumbnail grid with a keyboard-accessible lightbox built on `<dialog>`).
* **Responsive:** breakpoints at 1000 px (menu collapses into a button) and 600 px (two-column cards, stacked table rows);
  `prefers-reduced-motion` and print styles are handled.
* **Accessibility:** skip link, visible focus rings, semantic landmarks, alt text on portraits/covers, lightbox is keyboard operable.

After editing templates or `tsz.libraries.yml`: `drush cache:rebuild`. On a server with OPcache validation off, reload PHP-FPM
(the deploy script does).

## 10. Custom modules

### `tz_import` - add a new issue from a CSV

```bash
vendor/bin/drush tz:import-csv issue.csv --dry-run    # preview what would be created
vendor/bin/drush tz:import-csv issue.csv              # import; re-running the same file is safe
```

UTF-8 CSV with a header row; column order does not matter:

| Column | Required | Meaning |
|---|---|---|
| `cim` | yes | article title |
| `lapszam` | yes | issue title, e.g. `2026 / 3` (created if missing; the year and number set the volume and sort order) |
| `alcim` | no | subtitle |
| `szerzok` | no | author names separated by `;` (created if missing) |
| `oldalszam` | no | first page |
| `pdf` | no | file name; the file must be in `private/import/`; it is copied into `private://pdf/<year-month>/` (the original stays there - delete it afterwards) |

An article with the same title in the same issue is skipped, so a half-failed import can be repeated.

### `tz_migrate` - import the old site

`drush tz:migrate` (options `--legacy-files`, `--legacy-private`, `--fallback-files`, `--steps`, `--refresh`) and
`drush tz:migrate-verify`; see [§13](#13-migration-of-the-old-site) and [`docs/MIGRATION.md`](docs/MIGRATION.md). It can be uninstalled
after the cutover.

## 11. Editors: users, roles, daily work

| Role | For | Can |
|---|---|---|
| anonymous | visitors | read, search, download PDFs |
| authenticated | any logged-in user | same, Basic HTML |
| **content_editor** | the editorial team | create, edit and delete all five content types, publish/unpublish, upload files and documents, manage URL aliases, see the content overview |
| administrator | the maintainer | everything |

Create an editor: `vendor/bin/drush user:create <name> --mail=<email>` then `vendor/bin/drush user:role:add content_editor <name>`
and `vendor/bin/drush user:login --name=<name>` for a first-login link (or use `/admin/people/create`).

Daily work (in the Hungarian admin; all forms are under `/node/add/...`):

* **New issue:** `/node/add/lapszam` (title, year, label, sort number, cover), then the articles - one by one at `/node/add/cikk`
  (choose the issue and authors; type a new author's name to create the author on the spot) or in bulk with `tz:import-csv`.
* **Fix an article:** open it, *Szerkesztés*; attach/replace the PDF in the **PDF** box.
* **Blog post:** `/node/add/blog_post`; put the teaser into the *Összegzés* (summary) of the text field; the main image appears above the text.
  Several photos in a row become a gallery automatically.
* **Static page / documents:** `/node/add/page`; add downloadable files under **Letölthető dokumentumok**.
* **Draft:** untick **Közzétéve** (Published). Unpublished items are visible only to editors.
* **Footer address:** `/admin/content/block` → "Lábléc - elérhetőség" (Szerkesztés).

## 12. Operations

| Task | How |
|---|---|
| Deploy | Forge runs `scripts/deploy.sh` after `git pull`: `composer install --no-dev`, `drush deploy` (updates, config import, cache rebuild), PHP-FPM reload. Optional maintenance mode: `DEPLOY_MAINTENANCE=1` |
| Cron | **Must be scheduled** (the automated-cron module is off): every 30 minutes `php8.3 vendor/drush/drush/drush.php cron` (search index, sitemap, cleanup) |
| Backups | Forge database backups **plus** `scripts/backup-offsite.sh` nightly (database dump 90 days; uploaded + private files incrementally; changed/deleted files kept 30 days). Test a restore once |
| Security updates | `scripts/security-check.sh` weekly (e-mails when `composer audit` finds an advisory); apply critical core advisories within days |
| Monthly routine | update packages on a copy, `drush updatedb`, `drush config:export`, `composer audit`, `scripts/smoke-test.sh`, commit, push, deploy - [`docs/MAINTENANCE.md`](docs/MAINTENANCE.md) |
| Logs | `vendor/bin/drush watchdog:show --severity=Error` (database log); web server logs on the server |
| Status | `/admin/reports/status`; `vendor/bin/drush status`; `drush config:status` must say "No differences" after a deploy |
| Performance | anonymous page cache 6 h, dynamic page cache, BigPipe, aggregated CSS/JS, lazy images; PDFs and image styles are served through Drupal/nginx |
| Mail | Drupal's default (`sendmail`). Only update notices and password resets use it; add an SMTP module if the server cannot send mail |

**Upgrades.** Minor versions (11.4 to 11.5) are part of the monthly routine. A major version (Drupal 12, expected around the end of
2026) should be a small job because the site uses only mature modules and the theme has no dependencies - try it on a copy first.
PHP: stay on a supported version and change it per site in Forge.

**Security notes.** Private files are outside the web root; `update_free_access` is off; `trusted_host_patterns` restricts host
names; PHP execution and dump files (`.sql`, `.gz`, ...) in the upload folders are denied by the nginx rules in
[`docs/DEPLOY-FORGE.md`](docs/DEPLOY-FORGE.md). `config/sync` contains the administrator's e-mail address, so keep the repository private.

## 13. Migration of the old site

The old site (Drupal 8 with the Droopler page builder, about 2,060 nodes) was rebuilt as the content types above.
`drush tz:migrate` reads the old tables (database prefix `trn_`) and files and creates the content, keeping node IDs and aliases.

| Step | Result |
|---|---|
| `content` | authors (+photos), issues (+covers), articles (+PDFs into private files) |
| `blog` | blog posts: the old paragraph layout is flattened to clean HTML (text, images, galleries, banners, portraits, links); teaser becomes the summary |
| `pages` | the 9 real static pages + the homepage node (the Droopler demo pages are not imported) |
| `files` | files linked from body text |
| `redirects` | the old redirects whose target exists |
| `menus` | main and footer menu links |
| `cleanup` | removes empty filler paragraphs, clears empty bodies, fills missing alt text of portraits/covers |

```bash
bash scripts/migrate-legacy.sh        # pre-flight checks, import, verify, sitemap, search index (~3 minutes)
vendor/bin/drush tz:migrate-verify    # on its own, exit code 1 on any failure
```

It is idempotent (existing nodes are skipped). Not migrated by design: Droopler demo pages, the *Rólunk* contact form, user accounts,
view counts, old revisions. Missing on the old live site too (so they are reported, not migrated):
`documents/2021-05/trianon_kutato_intezet_kha_beszamolo_2012.pdf` and `..._2013.pdf` (linked from *Beszámolók*) and
`media/image/Trianoni_szemle_Partium_haz_meghivo__0.jpg` (an image in three blog posts). Full guide: [`docs/MIGRATION.md`](docs/MIGRATION.md).

## 14. Verification and testing

There is no automated test suite; the site is configuration plus templates, verified by scripts that compare it with the data:

| Check | How |
|---|---|
| Migration complete | `drush tz:migrate-verify` (node counts, aliases kept, redirects, PDFs, files on disk, no leftover media embeds, menu) |
| Key pages work | `bash scripts/smoke-test.sh https://trianoniszemle.hu` (home, issues, register + filter, authors, blog, search, a static page, sitemap, robots, login form, optional `SAMPLE_PDF=/system/files/...`, `/admin` not public) |
| Config is in git | `drush config:status` -> "No differences between DB and sync directory" |
| Dependencies safe | `composer audit --no-dev` -> no advisories |
| Code syntax | `php -l` over `web/modules/custom` and `web/themes/custom` |
| Old URLs | request every old alias and redirect source and expect a final 200 (a 403 only for unpublished items) |

After every deploy: smoke test, `watchdog:show --severity=Error` empty, look at `/admin/reports/status`.

## 15. Troubleshooting and gotchas

| Symptom | Cause / fix |
|---|---|
| All tables vanished after an install | `drush site:install` drops every table in its database, not just a prefix. Use it on empty databases only; restore from the dump |
| Old code or old templates still served after a deploy | PHP-FPM keeps compiled code (OPcache validation is off on Forge). Reload FPM (`scripts/deploy.sh` does); `drush cache:rebuild` alone is not enough |
| Migration: "private file system is not usable" | `PRIVATE_FILES_PATH` missing/not writable, or caches not rebuilt after setting it. Fix the path, `drush cache:rebuild`, run again. Never run an import while it is unset (PDFs would land in a wrong folder) |
| `Drupal does not bootstrap` / white page | check `.env` (DB values, `HASH_SALT`, `TRUSTED_HOSTS`), then `drush status`; PHP errors show with `APP_ENV=local` |
| "HASH_SALT is not set" exception | add `HASH_SALT` to `.env` |
| Untrusted host warning / 400 | the host name is not in `TRUSTED_HOSTS` |
| Search finds nothing | the index is built by cron (100 nodes per run); run cron several times or `scripts/migrate-legacy.sh` |
| Sitemap lists `http://default/...` | command-line runs do not know the site address: set `DRUSH_OPTIONS_URI` (or `drush/drush.yml`) |
| Theme change not visible | `drush cache:rebuild`; hard-reload the browser; reload PHP-FPM on servers with OPcache validation off |
| Images missing on a page | the files folder was not restored/copied; image derivatives regenerate on demand once the originals exist |
| A PDF returns 404/403 | the file is missing in `private/` (restore from backup) or the article is unpublished |
| Scripts print nothing useful from `drush` | on this Hungarian site Drush messages are Hungarian - do not grep them for English words; use `php:eval` |
| `drush sqlq` fails with `{table}` | placeholders expand to double-quoted names that the mysql client rejects; use `drush php:eval` with the database API |
| New content gets no alias | Pathauto patterns are in `config/sync` (`/admin/config/search/path/patterns`); check the issue (`field_szam`) and first author are set |

## 16. Glossary

| Hungarian | English / meaning |
|---|---|
| Szemle | review, journal (the publication) |
| Lapszám | one issue / number of the journal (also yearbooks and special issues: *Évkönyv, Emlékkönyv, Különszám*) |
| Évfolyam | volume / year |
| Cikk | article |
| Szerző | author |
| Kapcsolódó személy | related person (e.g. someone discussed or quoted) |
| Repertórium | register / index of all articles |
| Hírek, események | news, events (the blog) |
| Rólunk, Kuratórium, Szerkesztőség | About us, board of trustees, editorial board |
| Szabályzóink | our regulations (documents) |
| Támogatóink | our supporters |
| Adatkezelési tájékoztató | privacy notice |
| Kezdőlap | home page |
| Közzétéve | published |
| Összegzés | summary (used as the blog teaser) |

## License and ownership

Proprietary - the code and content belong to the site owner (Trianon Kutatóintézet Közhasznú Alapítvány). Drupal and the
contributed modules are GPL-2.0-or-later; Lato is licensed under the SIL Open Font License (`web/themes/custom/tsz/fonts/LICENSE-Lato-OFL.txt`).
