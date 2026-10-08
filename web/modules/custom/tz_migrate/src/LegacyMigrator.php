<?php

namespace Drupal\tz_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\pathauto\PathautoState;
use Drupal\redirect\Entity\Redirect;
use Drupal\taxonomy\Entity\Term;

/**
 * Imports the old Trianoni Szemle (Drupal 8 / Droopler) site into this site.
 *
 * Reads the legacy tables through the "legacy" database connection and the old
 * site's files from directories on disk. Keeps node IDs and the exact old URL
 * aliases. Every step is idempotent: existing nodes are skipped.
 */
final class LegacyMigrator {

  public const MAX_WIDTH = 1600;
  public const PAGES = [489, 494, 495, 498, 499, 500, 501, 502, 2540];
  public const HOME_NID = 5;
  public const STEPS = ['content', 'blog', 'pages', 'files', 'redirects', 'menus', 'cleanup'];

  private Connection $legacy;
  private \Closure $log;
  private array $aliases = [];
  private array $stats = [];
  private array $missing = [];

  public function __construct(
    private string $legacyPublic,
    private ?string $legacyPrivate,
    private ?string $fallbackPublic,
    callable $log,
    private bool $refresh = FALSE,
  ) {
    $this->legacy = Database::getConnection('default', 'legacy');
    $this->log = \Closure::fromCallable($log);
  }

  private function log(string $msg): void {
    ($this->log)($msg);
  }

  /**
   * Checks everything the migration depends on. Returns a list of problems.
   */
  public function preflight(): array {
    $problems = [];
    try {
      $n = (int) $this->legacy->query("select count(*) from {node_field_data}")->fetchField();
      if ($n < 100) {
        $problems[] = "Legacy database has only $n nodes - wrong connection or prefix?";
      }
    }
    catch (\Throwable $e) {
      $problems[] = 'Cannot read the legacy database (check LEGACY_DB_* in .env): ' . $e->getMessage();
    }
    foreach (['cikk', 'lapszam', 'szerzo', 'blog_post', 'page'] as $bundle) {
      if (!\Drupal::entityTypeManager()->getStorage('node_type')->load($bundle)) {
        $problems[] = "Content type '$bundle' is missing - run 'drush config:import' first.";
      }
    }
    if (!\Drupal::entityTypeManager()->getStorage('taxonomy_vocabulary')->load('blog_kategoria')) {
      $problems[] = "Vocabulary 'blog_kategoria' is missing - run 'drush config:import' first.";
    }
    if (!is_dir($this->legacyPublic)) {
      $problems[] = "Legacy public files directory not found: {$this->legacyPublic}";
    }
    if ($this->legacyPrivate !== NULL && !is_dir($this->legacyPrivate)) {
      $problems[] = "Legacy private files directory not found: {$this->legacyPrivate}";
    }
    if ($this->legacyPrivate === NULL) {
      $problems[] = 'No legacy private files directory given (article PDFs live there).';
    }
    $fs = \Drupal::service('file_system');
    $private = $fs->realpath('private://');
    if (!$private || !is_writable($private)) {
      $problems[] = "The private file system is not usable (file_private_path / PRIVATE_FILES_PATH): " . var_export($private, TRUE) . ". Fix it and run 'drush cache:rebuild' first.";
    }
    $public = $fs->realpath('public://');
    if (!$public || !is_writable($public)) {
      $problems[] = 'The public files directory is not writable.';
    }
    return $problems;
  }

  /**
   * Runs the given steps (see STEPS) and returns statistics.
   */
  public function run(array $steps): array {
    foreach (self::STEPS as $step) {
      if (in_array($step, $steps, TRUE)) {
        $this->log("== step: $step");
        $this->$step();
      }
    }
    $this->stats['files_missing'] = array_values(array_unique($this->missing));
    return $this->stats;
  }

  // ---------------------------------------------------------------- files ---

  private function realBase(string $scheme): string {
    return \Drupal::service('file_system')->realpath("$scheme://");
  }

  /**
   * Copies a legacy file into this site (same relative path). Images wider
   * than MAX_WIDTH are scaled down. Returns FALSE when the source is missing.
   */
  private function copyIn(string $uri, bool $scale): bool {
    [$scheme, $rel] = explode('://', $uri, 2);
    $dst = $this->realBase($scheme) . '/' . $rel;
    if (is_file($dst)) {
      return TRUE;
    }
    $candidates = $scheme === 'private'
      ? [$this->legacyPrivate . '/' . $rel]
      : array_filter([$this->legacyPublic . '/' . $rel, $this->fallbackPublic ? $this->fallbackPublic . '/' . $rel : NULL]);
    foreach ($candidates as $src) {
      if (is_file($src)) {
        @mkdir(dirname($dst), 0775, TRUE);
        copy($src, $dst);
        if ($scale) {
          $img = \Drupal::service('image.factory')->get($dst);
          if ($img->isValid() && $img->getWidth() > self::MAX_WIDTH) {
            $img->scale(self::MAX_WIDTH);
            $img->save();
          }
        }
        return TRUE;
      }
    }
    $this->missing[] = $uri;
    return FALSE;
  }

  private function managedFile(string $uri): File {
    $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
    if ($existing) {
      return reset($existing);
    }
    [$scheme, $rel] = explode('://', $uri, 2);
    $real = $this->realBase($scheme) . '/' . $rel;
    $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'uid' => 1, 'status' => 1,
      'filemime' => (is_file($real) ? mime_content_type($real) : NULL) ?: 'application/octet-stream']);
    $file->save();
    return $file;
  }

  private function mediaImage(int $mid): ?object {
    return $this->legacy->query("select f.uri, i.field_media_image_alt alt from {media__field_media_image} i join {file_managed} f on f.fid=i.field_media_image_target_id where i.entity_id=:m", [':m' => $mid])->fetchObject() ?: NULL;
  }

  /** A legacy media image as [File, alt] (file copied), or NULL. */
  private function imageFile(int $mid): ?array {
    $r = $this->mediaImage($mid);
    if (!$r || !$this->copyIn($r->uri, TRUE)) {
      return NULL;
    }
    return [$this->managedFile($r->uri), (string) $r->alt];
  }

  private function imgTag(string $uri, string $alt, string $class = ''): string {
    if (!$this->copyIn($uri, TRUE)) {
      return '';
    }
    $file = $this->managedFile($uri);
    $real = $this->realBase('public') . '/' . substr($uri, 9);
    $sz = @getimagesize($real) ?: [0, 0];
    return sprintf('<img src="%s" alt="%s" data-entity-type="file" data-entity-uuid="%s"%s%s>',
      \Drupal::service('file_url_generator')->generateString($uri), htmlspecialchars($alt, ENT_QUOTES), $file->uuid(),
      $sz[0] ? sprintf(' width="%d" height="%d"', $sz[0], $sz[1]) : '',
      $class ? ' class="' . $class . '" data-align="' . str_replace('align-', '', $class) . '"' : '');
  }

  // -------------------------------------------------------- body building ---

  /** Replaces <drupal-media> embeds (documents -> links, images -> <img>). */
  private function convertEmbeds(string $html): string {
    if (!str_contains($html, '<drupal-media')) {
      return $html;
    }
    return preg_replace_callback('#<drupal-media\b[^>]*data-entity-uuid="([^"]+)"[^>]*>\s*</drupal-media>#i', function ($m) {
      $align = preg_match('/data-align="(\w+)"/', $m[0], $a) ? 'align-' . $a[1] : '';
      $media = $this->legacy->query("select m.bundle, m.mid, d.name from {media} m join {media_field_data} d on d.mid=m.mid where m.uuid=:u", [':u' => $m[1]])->fetchObject();
      if (!$media) {
        $this->stats['embeds_unresolved'] = ($this->stats['embeds_unresolved'] ?? 0) + 1;
        return '';
      }
      if ($media->bundle === 'd_document') {
        $uri = $this->legacy->query("select f.uri from {media__field_media_file} x join {file_managed} f on f.fid=x.field_media_file_target_id where x.entity_id=:m", [':m' => $media->mid])->fetchField();
        if ($uri && $this->copyIn($uri, FALSE)) {
          $this->stats['document_links'] = ($this->stats['document_links'] ?? 0) + 1;
          return '<p><a href="' . \Drupal::service('file_url_generator')->generateString($uri) . '">' . htmlspecialchars($media->name) . '</a></p>';
        }
      }
      elseif ($media->bundle === 'd_image' && ($r = $this->mediaImage((int) $media->mid))) {
        $this->stats['embedded_images'] = ($this->stats['embedded_images'] ?? 0) + 1;
        return '<p>' . $this->imgTag($r->uri, (string) $r->alt, $align) . '</p>';
      }
      $this->stats['embeds_unresolved'] = ($this->stats['embeds_unresolved'] ?? 0) + 1;
      return '';
    }, $html);
  }

  private function paragraphValue(string $table, string $col, int $pid): mixed {
    return $this->legacy->query("select $col from {paragraph__$table} where entity_id=:p order by delta limit 1", [':p' => $pid])->fetchField();
  }

  private function link(?string $uri): string {
    $uri = (string) $uri;
    return str_starts_with($uri, 'internal:') ? substr($uri, 9) : $uri;
  }

  /** One Droopler paragraph -> clean HTML. */
  private function flatten(int $pid, string $type): string {
    if ($type === 'd_p_form') {
      return '';
    }
    $html = '';
    $bg = $this->paragraphValue('field_d_media_background', 'field_d_media_background_target_id', $pid);
    if ($bg && ($r = $this->mediaImage((int) $bg)) && ($h = $this->imgTag($r->uri, (string) $r->alt))) {
      $html .= "<p>$h</p>\n";
      $this->stats['background_images'] = ($this->stats['background_images'] ?? 0) + 1;
    }
    $icon = $this->paragraphValue('field_d_media_icon', 'field_d_media_icon_target_id', $pid);
    if ($icon && ($r = $this->mediaImage((int) $icon)) && ($h = $this->imgTag($r->uri, (string) $r->alt, 'align-left'))) {
      $html .= "<p>$h</p>\n";
      $this->stats['icon_images'] = ($this->stats['icon_images'] ?? 0) + 1;
    }
    if ($t = trim((string) $this->paragraphValue('field_d_main_title', 'field_d_main_title_value', $pid))) {
      $html .= "<h2>$t</h2>\n";
    }
    if ($t = trim((string) $this->paragraphValue('field_d_subtitle', 'field_d_subtitle_value', $pid))) {
      $html .= "<h3>$t</h3>\n";
    }
    if ($t = $this->paragraphValue('field_d_long_text', 'field_d_long_text_value', $pid)) {
      $html .= $this->convertEmbeds((string) $t) . "\n";
    }
    if (in_array($type, ['d_p_blog_image', 'd_p_gallery'], TRUE)) {
      foreach ($this->legacy->query("select field_d_media_image_target_id m from {paragraph__field_d_media_image} where entity_id=:p order by delta", [':p' => $pid])->fetchCol() as $mid) {
        if (($r = $this->mediaImage((int) $mid)) && ($h = $this->imgTag($r->uri, (string) $r->alt))) {
          $html .= "<p>$h</p>\n";
        }
      }
    }
    $cta = $this->legacy->query("select field_d_cta_link_uri u, field_d_cta_link_title t from {paragraph__field_d_cta_link} where entity_id=:p limit 1", [':p' => $pid])->fetchObject();
    if ($cta && $cta->u) {
      $html .= sprintf('<p><a href="%s">%s</a></p>' . "\n", htmlspecialchars($this->link($cta->u), ENT_QUOTES), htmlspecialchars($cta->t ?: $cta->u));
    }
    return $html;
  }

  private function sectionsHtml(int $nid, string $field): string {
    $html = '';
    $secs = $this->legacy->query("select p.id, p.type from {node__$field} s join {paragraphs_item_field_data} p on p.id=s.{$field}_target_id and p.revision_id=s.{$field}_target_revision_id where s.entity_id=:n order by s.delta", [':n' => $nid])->fetchAll();
    foreach ($secs as $s) {
      $html .= $this->flatten((int) $s->id, $s->type);
    }
    return $html;
  }

  /** The node's own body field as a field value array, or NULL when empty. */
  private function body(int $nid, string $oldBundle): ?array {
    $b = $this->legacy->query("select body_value v, body_summary s, body_format f from {node__body} where entity_id=:n and bundle=:b", [':n' => $nid, ':b' => $oldBundle])->fetchObject();
    if (!$b) {
      return NULL;
    }
    $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags((string) $b->v, '<img><iframe>'), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return $text === '' ? NULL : ['value' => $this->convertEmbeds((string) $b->v), 'summary' => $this->convertEmbeds((string) $b->s), 'format' => $b->f ?: 'full_html'];
  }

  // ----------------------------------------------------------------- nodes ---

  private function alias(int $nid): ?string {
    if (!$this->aliases) {
      foreach ($this->legacy->query("select path, alias from {path_alias} where path like '/node/%' and status=1") as $r) {
        $this->aliases[(int) substr($r->path, 6)] = $r->alias;
      }
    }
    return $this->aliases[$nid] ?? NULL;
  }

  private function baseValues(object $row, string $bundle): array {
    $v = ['nid' => (int) $row->nid, 'type' => $bundle, 'title' => $row->title, 'status' => (int) $row->status, 'created' => (int) $row->created,
      'changed' => (int) $row->changed, 'uid' => 1, 'langcode' => 'hu', 'promote' => 0, 'sticky' => (int) $row->sticky];
    if ($alias = $this->alias((int) $row->nid)) {
      $v['path'] = ['alias' => $alias, 'pathauto' => PathautoState::SKIP];
    }
    return $v;
  }

  /** Creates the node unless it exists; returns TRUE when created. */
  private function create(array $values, string $stat): bool {
    if (Node::load($values['nid'])) {
      $this->stats[$stat . '_skipped'] = ($this->stats[$stat . '_skipped'] ?? 0) + 1;
      return FALSE;
    }
    Node::create($values)->save();
    $this->stats[$stat] = ($this->stats[$stat] ?? 0) + 1;
    if (($this->stats[$stat] % 100) === 0) {
      \Drupal::entityTypeManager()->getStorage('node')->resetCache();
      $this->log("   $stat: {$this->stats[$stat]}");
    }
    return TRUE;
  }

  private function legacyRows(string $oldType): array {
    return $this->legacy->query("select * from {node_field_data} where type=:t order by nid", [':t' => $oldType])->fetchAll();
  }

  private function imageField(int $nid, string $oldBundle, string $title): ?array {
    $r = $this->legacy->query("select t.field_teaser_media_image_target_id m from {node__field_teaser_media_image} t where t.entity_id=:n and t.bundle=:b", [':n' => $nid, ':b' => $oldBundle])->fetchField();
    if (!$r || !($img = $this->imageFile((int) $r))) {
      return NULL;
    }
    return ['target_id' => $img[0]->id(), 'alt' => $img[1] !== '' ? $img[1] : $title];
  }

  private function content(): void {
    foreach ($this->legacyRows('tsz_szerzo') as $row) {
      $v = $this->baseValues($row, 'szerzo');
      if ($b = $this->body($row->nid, 'tsz_szerzo')) { $v['body'] = $b; }
      if ($t = $this->legacy->query("select field_tipus_value from {node__field_tipus} where entity_id=:n", [':n' => $row->nid])->fetchField()) { $v['field_tipus'] = $t; }
      if ($i = $this->imageField($row->nid, 'tsz_szerzo', $row->title)) { $v['field_kep'] = $i; }
      $this->create($v, 'authors');
    }
    foreach ($this->legacyRows('tsz_lapszam') as $row) {
      $v = $this->baseValues($row, 'lapszam');
      if ($b = $this->body($row->nid, 'tsz_lapszam')) { $v['body'] = $b; }
      foreach (['field_evfolyam', 'field_lapszam', 'field_sorszam'] as $f) {
        $val = $this->legacy->query("select {$f}_value from {node__$f} where entity_id=:n", [':n' => $row->nid])->fetchField();
        if ($val !== FALSE && $val !== NULL) { $v[$f] = $val; }
      }
      if ($i = $this->imageField($row->nid, 'tsz_lapszam', $row->title)) { $v['field_kep'] = $i; }
      $this->create($v, 'issues');
    }
    foreach ($this->legacyRows('trianoni_szemle') as $row) {
      $v = $this->baseValues($row, 'cikk');
      if ($b = $this->body($row->nid, 'trianoni_szemle')) { $v['body'] = $b; }
      foreach (['field_alcim', 'field_oldalszam'] as $f) {
        $val = $this->legacy->query("select {$f}_value from {node__$f} where entity_id=:n", [':n' => $row->nid])->fetchField();
        if ($val !== FALSE && $val !== NULL && $val !== '') { $v[$f] = $val; }
      }
      if ($s = $this->legacy->query("select field_szam_target_id from {node__field_szam} where entity_id=:n", [':n' => $row->nid])->fetchField()) { $v['field_szam'] = ['target_id' => $s]; }
      $authors = $this->legacy->query("select field_szerzo_target_id from {node__field_szerzo} where entity_id=:n order by delta", [':n' => $row->nid])->fetchCol();
      if ($authors) { $v['field_szerzo'] = array_map(fn($x) => ['target_id' => $x], $authors); }
      if ($uri = $this->legacy->query("select f.uri from {node__field_pdf} p join {file_managed} f on f.fid=p.field_pdf_target_id where p.entity_id=:n", [':n' => $row->nid])->fetchField()) {
        if ($this->copyIn($uri, FALSE)) { $v['field_pdf'] = ['target_id' => $this->managedFile($uri)->id(), 'display' => 1, 'description' => '']; }
      }
      $this->create($v, 'articles');
    }
  }

  private function categoryTerm(int $oldTid): ?int {
    $name = $this->legacy->query("select name from {taxonomy_term_field_data} where tid=:t", [':t' => $oldTid])->fetchField();
    if (!$name) {
      return NULL;
    }
    $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => $name, 'vid' => 'blog_kategoria']);
    if ($existing) {
      return (int) reset($existing)->id();
    }
    $term = Term::create(['vid' => 'blog_kategoria', 'name' => $name, 'langcode' => 'hu']);
    $term->save();
    return (int) $term->id();
  }

  private function setBody(Node $node, string $html, string $summary): void {
    $old = $node->getChangedTime();
    $node->set('body', ['value' => $html, 'summary' => $summary, 'format' => 'full_html']);
    $node->save();
    $this->restoreChanged((int) $node->id(), $old);
  }

  private function restoreChanged(int $nid, int $changed): void {
    $db = \Drupal::database();
    foreach (['node_field_data', 'node_field_revision'] as $t) {
      $db->update($t)->fields(['changed' => $changed])->condition('nid', $nid)->execute();
    }
  }

  private function blog(): void {
    foreach ($this->legacyRows('blog_post') as $row) {
      $nid = (int) $row->nid;
      $existing = Node::load($nid);
      if ($existing && !$this->refresh) {
        $this->stats['blog_posts_skipped'] = ($this->stats['blog_posts_skipped'] ?? 0) + 1;
        continue;
      }
      $teaser = (string) ($this->legacy->query("select field_blog_teaser_value from {node__field_blog_teaser} where entity_id=:n", [':n' => $nid])->fetchField() ?: '');
      $teaser = $this->convertEmbeds($teaser);
      $html = $this->sectionsHtml($nid, 'field_blog_sections');
      if ($existing) {
        if ($html !== '' && trim((string) $existing->body->value) !== trim($html)) {
          $this->setBody($existing, $html, $teaser);
          $this->stats['blog_refreshed'] = ($this->stats['blog_refreshed'] ?? 0) + 1;
        }
        continue;
      }
      $v = $this->baseValues($row, 'blog_post');
      $v['body'] = ['value' => $html !== '' ? $html : $teaser, 'summary' => $teaser, 'format' => 'full_html'];
      $mid = $this->legacy->query("select b.field_blog_media_main_image_target_id from {node__field_blog_media_main_image} b join {media_field_data} m on m.mid=b.field_blog_media_main_image_target_id where b.entity_id=:n and m.bundle='d_image'", [':n' => $nid])->fetchField();
      if ($mid && ($img = $this->imageFile((int) $mid))) {
        $v['field_kep'] = ['target_id' => $img[0]->id(), 'alt' => $img[1] !== '' ? $img[1] : $row->title];
      }
      if ($tid = $this->legacy->query("select field_blog_category_target_id from {node__field_blog_category} where entity_id=:n", [':n' => $nid])->fetchField()) {
        if ($term = $this->categoryTerm((int) $tid)) { $v['field_kategoria'] = ['target_id' => $term]; }
      }
      $this->create($v, 'blog_posts');
    }
  }

  private function pages(): void {
    foreach (self::PAGES as $nid) {
      $row = $this->legacy->query("select * from {node_field_data} where nid=:n", [':n' => $nid])->fetchObject();
      if (!$row) {
        $this->log("   page $nid not found in legacy - skipped");
        continue;
      }
      $existing = Node::load($nid);
      if ($existing && !$this->refresh) {
        continue;
      }
      $html = $this->sectionsHtml($nid, 'field_page_section');
      if ($existing) {
        if ($html !== '' && trim((string) $existing->body->value) !== trim($html)) {
          $this->setBody($existing, $html, '');
          $this->stats['pages_refreshed'] = ($this->stats['pages_refreshed'] ?? 0) + 1;
        }
        continue;
      }
      $v = $this->baseValues($row, 'page');
      $v['sticky'] = 0;
      $v['body'] = ['value' => $html, 'format' => 'full_html'];
      $this->create($v, 'pages');
    }
    // Homepage: the old one was a layout of paragraphs; the new one is built from views + this intro.
    if (!Node::load(self::HOME_NID)) {
      Node::create(['nid' => self::HOME_NID, 'type' => 'page', 'title' => 'Kezdőlap', 'status' => 1, 'uid' => 1, 'langcode' => 'hu', 'created' => 1619273000,
        'path' => ['alias' => '/kezdolap', 'pathauto' => PathautoState::SKIP],
        'body' => ['value' => '<p>A Trianoni Szemle a Trianon Kutatóintézet Közhasznú Alapítvány Magyar Örökség-díjas folyóirata.</p>', 'format' => 'full_html']])->save();
      $this->stats['home'] = 1;
    }
  }

  // ------------------------------------------------- referenced files sweep ---

  /** Copies files that body/summary text links to by plain /sites/default/files/ path. */
  private function files(): void {
    $refs = [];
    foreach (\Drupal::database()->query("select body_value, body_summary from {node__body}") as $r) {
      foreach ([$r->body_value, $r->body_summary] as $h) {
        if (preg_match_all('#/sites/default/files/([^"\'\s)<>?\#]+)#', (string) $h, $m)) {
          foreach ($m[1] as $p) {
            $refs[urldecode($p)] = TRUE;
          }
        }
      }
    }
    $copied = 0;
    foreach (array_keys($refs) as $rel) {
      if (str_starts_with($rel, 'styles/')) {
        continue;
      }
      $dst = $this->realBase('public') . '/' . $rel;
      if (!is_file($dst) && $this->copyIn("public://$rel", TRUE)) {
        $copied++;
      }
    }
    $this->stats['body_files_copied'] = $copied;
    $this->stats['body_file_refs'] = count($refs);
  }

  // -------------------------------------------------------------- redirects ---

  private function redirects(): void {
    $db = \Drupal::database();
    $aliases = [];
    foreach ($db->query("select alias from {path_alias}") as $r) {
      $aliases[ltrim($r->alias, '/')] = TRUE;
    }
    $made = $skipped = 0;
    foreach ($this->legacy->query("select * from {redirect}")->fetchAll() as $r) {
      $uri = $r->redirect_redirect__uri;
      if (preg_match('#^internal:/node/(\d+)$#', $uri, $m)) {
        if (!Node::load($m[1])) { $skipped++; continue; }
      }
      elseif (str_starts_with($uri, 'internal:/') || str_starts_with($uri, 'entity:')) {
        $skipped++; continue;
      }
      $source = ltrim($r->redirect_source__path, '/');
      if (isset($aliases[$source]) || $db->query("select count(*) from {redirect} where redirect_source__path=:p", [':p' => $source])->fetchField()) {
        $skipped++; continue;
      }
      Redirect::create(['redirect_source' => ['path' => $source, 'query' => @unserialize($r->redirect_source__query) ?: []], 'redirect_redirect' => ['uri' => $uri],
        'language' => 'hu', 'status_code' => (int) $r->status_code ?: 301])->save();
      $made++;
    }
    $this->stats['redirects'] = $made;
    $this->stats['redirects_skipped'] = $skipped;
  }

  // ------------------------------------------------------------------ menus ---

  private function menus(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
    $add = function (string $menu, string $title, string $uri, int $weight, ?string $parent = NULL) use ($storage): string {
      $found = $storage->loadByProperties(['menu_name' => $menu, 'title' => $title]);
      if ($found) {
        return 'menu_link_content:' . reset($found)->uuid();
      }
      $link = MenuLinkContent::create(['menu_name' => $menu, 'title' => $title, 'link' => ['uri' => $uri], 'weight' => $weight, 'expanded' => TRUE, 'langcode' => 'hu'] + ($parent ? ['parent' => $parent] : []));
      $link->save();
      $this->stats['menu_links'] = ($this->stats['menu_links'] ?? 0) + 1;
      return 'menu_link_content:' . $link->uuid();
    };
    $add('main', 'Évfolyamok', 'internal:/evfolyamok', 0);
    $add('main', 'Hírek események', 'internal:/blog', 1);
    $about = $add('main', 'Rólunk', 'entity:node/495', 4);
    foreach ([[494, 'Kapcsolat', 0], [500, 'Kuratórium', 1], [501, 'A kuratórium döntései', 2], [502, 'Szerkesztőség', 3], [2540, 'Szabályzóink', 4]] as [$n, $t, $w]) {
      if (Node::load($n)) {
        $add('main', $t, "entity:node/$n", $w, $about);
      }
    }
    $add('main', 'Támogatóink', 'entity:node/499', 5);
    $add('footer', 'Adatkezelési tájékoztató', 'entity:node/489', 0);
    $add('footer', 'Támogatóink', 'entity:node/499', 1);
  }

  // ---------------------------------------------------------------- cleanup ---

  /** Strips empty filler paragraphs/bodies and fills missing photo alt text. */
  private function cleanup(): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $clean = function (string $html): string {
      return preg_replace('#<p\b[^>]*>(?:\s|&nbsp;|&\#160;|\x{00A0}|<br\s*/?>)*</p>\s*#iu', '', $html);
    };
    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->exists('body')->execute();
    $changed = 0;
    foreach (array_chunk($ids, 150) as $chunk) {
      foreach ($storage->loadMultiple($chunk) as $node) {
        $v = (string) $node->body->value;
        $s = (string) $node->body->summary;
        $nv = $clean($v);
        $ns = $clean($s);
        $empty = !preg_match('/<(img|iframe|video|audio|object|embed)\b/i', $nv)
          && trim(preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($nv), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '';
        if ($nv !== $v || $ns !== $s || ($empty && $v !== '')) {
          $old = $node->getChangedTime();
          $node->set('body', $empty ? NULL : ['value' => $nv, 'summary' => $ns, 'format' => $node->body->format]);
          $node->save();
          $this->restoreChanged((int) $node->id(), $old);
          $changed++;
        }
      }
      $storage->resetCache();
    }
    $this->stats['bodies_cleaned'] = $changed;
    $alt = 0;
    foreach (['szerzo', 'lapszam', 'blog_post'] as $bundle) {
      foreach ($storage->loadByProperties(['type' => $bundle]) as $node) {
        if ($node->hasField('field_kep') && !$node->get('field_kep')->isEmpty() && trim((string) $node->get('field_kep')->alt) === '') {
          $old = $node->getChangedTime();
          $node->get('field_kep')->alt = $node->label();
          $node->save();
          $this->restoreChanged((int) $node->id(), $old);
          $alt++;
        }
      }
      $storage->resetCache();
    }
    $this->stats['photo_alt_filled'] = $alt;
  }

  // ----------------------------------------------------------------- verify ---

  /**
   * Compares the new site with the legacy data. Returns [label => [ok, detail]].
   */
  public function verify(): array {
    $new = \Drupal::database();
    $count = fn(string $bundle, bool $published = FALSE) => (int) \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', $bundle)->condition($published ? 'status' : 'nid', $published ? 1 : 0, $published ? '=' : '>')->count()->execute();
    $leg = fn(string $type) => (int) $this->legacy->query("select count(*) from {node_field_data} where type=:t", [':t' => $type])->fetchField();
    $results = [];
    foreach (['szerzo' => 'tsz_szerzo', 'lapszam' => 'tsz_lapszam', 'cikk' => 'trianoni_szemle', 'blog_post' => 'blog_post'] as $bundle => $old) {
      $a = $count($bundle); $b = $leg($old);
      $results["$bundle count"] = [$a === $b, "new $a / legacy $b"];
    }
    $pages = $count('page');
    $results['page count'] = [$pages === count(self::PAGES) + 1, "new $pages (expected " . (count(self::PAGES) + 1) . ' incl. home)'];
    // aliases of imported nodes equal the legacy ones
    $wrong = 0; $total = 0;
    foreach ($this->legacy->query("select path, alias from {path_alias} where path like '/node/%' and status=1") as $r) {
      $nid = (int) substr($r->path, 6);
      if (!Node::load($nid)) { continue; }
      $total++;
      $cur = $new->query("select alias from {path_alias} where path=:p and status=1", [':p' => $r->path])->fetchField();
      if ($cur !== $r->alias) { $wrong++; }
    }
    $results['legacy aliases kept'] = [$wrong === 0, "$total checked, $wrong different"];
    $red = (int) $new->query("select count(*) from {redirect}")->fetchField();
    $results['redirects'] = [$red > 1500, "$red imported"];
    // files on disk
    $missingFiles = 0;
    foreach ($new->query("select uri from {file_managed}") as $f) {
      [$scheme, $rel] = explode('://', $f->uri, 2);
      if (!is_file($this->realBase($scheme) . '/' . $rel)) { $missingFiles++; }
    }
    $results['managed files exist on disk'] = [$missingFiles === 0, "$missingFiles missing"];
    $pdfs = (int) $new->query("select count(*) from {node__field_pdf}")->fetchField();
    $legPdfs = (int) $this->legacy->query("select count(*) from {node__field_pdf}")->fetchField();
    $results['article PDFs'] = [$pdfs >= $legPdfs - 8, "new $pdfs / legacy $legPdfs"];
    $emb = (int) $new->query("select count(*) from {node__body} where body_value like '%<drupal-media%'")->fetchField();
    $results['no leftover <drupal-media> embeds'] = [$emb === 0, "$emb nodes"];
    $main = (int) $new->query("select count(*) from {menu_link_content_data} where menu_name='main'")->fetchField();
    $results['main menu links'] = [$main >= 7, "$main"];
    return $results;
  }

}
