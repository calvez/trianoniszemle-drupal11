# Importing the old site (Drupal 8 / Droopler)

`drush tz:migrate` reads the old site's **database tables** and **files** and creates the content in this site. It
keeps the old node IDs and the exact URL aliases, so all old links keep working. It is the cleaned-up, tested version of
what was done by hand during development; it is **idempotent** (existing nodes are skipped, so it can be re-run).

## What you need

1. **The old database** - tables with a common prefix (the old site used `trn_`). Easiest and safest: a **separate database**
   on the new server, e.g. `trianoniszemle_legacy`:
   ```bash
   # on the old server (only the prefixed tables - old DBs may contain stray unprefixed tables)
   mysqldump --no-tablespaces OLD_DB $(mysql -N -e "SHOW TABLES LIKE 'trn\_%'" OLD_DB) | gzip > legacy.sql.gz
   # on the new server
   zcat legacy.sql.gz | mysql trianoniszemle_legacy
   ```
   (Do **not** import an unfiltered dump into the new site's own database: unprefixed leftovers could overwrite its tables.)
2. **The old files**, copied to the new server:
   ```bash
   rsync -a --exclude=styles/ --exclude=css/ --exclude=js/ --exclude=php/ OLD:/path/web/sites/default/files/ /home/forge/legacy/files/
   rsync -a OLD:/path/private/ /home/forge/legacy/private/
   ```
3. **A freshly installed site** with the configuration imported (`docs/DEPLOY-FORGE.md` step 3) and a working private
   files folder.
4. In `.env`: `LEGACY_DB_NAME=trianoniszemle_legacy`, `LEGACY_DB_PREFIX=trn_` (user/password default to the main
   database's), `LEGACY_FILES_PATH=/home/forge/legacy/files`, `LEGACY_PRIVATE_PATH=/home/forge/legacy/private`.

## Run

```bash
bash scripts/migrate-legacy.sh        # pre-flight checks, import, verify, sitemap, search index (about 3 minutes)
```

or step by step:

```bash
vendor/bin/drush tz:migrate                 # asks for confirmation after the pre-flight check
vendor/bin/drush tz:migrate-verify          # exit code 1 if anything is off
vendor/bin/drush tz:migrate --steps=blog --refresh      # rebuild blog bodies only
```

The pre-flight check stops with a clear message if a content type is missing (configuration not imported), the legacy
database or file folders are unreachable, or the private file system is not usable.

## What each step does

| Step | Result |
|---|---|
| `content` | 509 authors (with photos), 43 issues (with covers), 1,355 articles with their 201 PDFs (copied to the private files) |
| `blog` | 98 blog posts: the old paragraph layout is flattened into clean HTML (text, images, galleries, banners, portraits, links); teaser becomes the summary; main image and category kept |
| `pages` | the 9 real static pages (the Droopler demo pages are *not* imported) and the homepage node (nid 5) |
| `files` | copies every file that body text links to by plain path (documents, inline images) |
| `redirects` | the old redirects whose target exists (about 1,930) |
| `menus` | main and footer menu links |
| `cleanup` | removes empty filler paragraphs (`<p>&nbsp;</p>`), clears empty bodies, fills missing photo alt text |

Images wider than 1,600 px are scaled down while copying. `<drupal-media>` embeds of the old media library are converted
(documents to links, images to `<img>`).

## Expected result and known leftovers

`tz:migrate-verify` should print all `[ OK ]`: 509 / 43 / 1,355 / 98 nodes, 10 pages, all legacy aliases kept, about
1,930 redirects, 201 PDFs, no `<drupal-media>` left, all managed files on disk.

Three files are missing **on the old live site too**, so they cannot be migrated (the importer lists them):
`documents/2021-05/trianon_kutato_intezet_kha_beszamolo_2012.pdf`, `..._2013.pdf` (links on *Beszámolók*) and
`media/image/Trianoni_szemle_Partium_haz_meghivo__0.jpg` (an image in three blog posts). Re-add them in the admin when found.

Not migrated by design: the Droopler demo pages, the contact form on *Rólunk*, user accounts (only `admin`; create editors
in the admin), view counts and old revisions.

## Re-running for new content

If articles/posts were added on the old site since the last run: import a fresh dump into the legacy database, copy the
files again, run `bash scripts/migrate-legacy.sh`. New items are added; **edits to items that were already imported are
not copied** (`--refresh` only rebuilds blog and page bodies).

## After go-live

```bash
vendor/bin/drush pm:uninstall tz_migrate -y           # and remove tz_migrate from config (drush config:export, commit)
mysql -e "DROP DATABASE trianoniszemle_legacy"        # once you are sure
rm -rf /home/forge/legacy
# remove the LEGACY_* lines from .env
```

Keep the old server/backup for a few weeks as a safety net.
