<?php

/**
 * @file
 * One-off import (phase B1): blog posts. The Droopler "paragraph" sections are
 * flattened into one clean HTML body; the teaser becomes the body summary.
 * Images are copied (scaled to max 1600px wide) and embedded as editor-style
 * <img> tags. Keeps node IDs and the exact old aliases. Idempotent.
 *
 * Run: drush php:script migration/04-import-blog.php
 */

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\pathauto\PathautoState;
use Drupal\taxonomy\Entity\Term;

const MAX_WIDTH = 1600;
$legacy = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$OLD_PUBLIC = '/home/ploi/trianon-d10-upgrade/web/sites/default/files';
$fs = \Drupal::service('file_system');
$urlgen = \Drupal::service('file_url_generator');
$missing = [];

/** Copy a legacy public file (scaled if large) and return a File entity. */
$make_file = function (string $uri) use ($OLD_PUBLIC, $fs, &$missing): ?File {
  $rel = substr($uri, strlen('public://'));
  $src = "$OLD_PUBLIC/$rel";
  if (!is_file($src)) { $missing[] = $uri; return NULL; }
  if ($existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri])) { return reset($existing); }
  $dir = dirname($uri);
  $fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $real = $fs->realpath($dir) . '/' . basename($uri);
  if (!is_file($real)) {
    copy($src, $real);
    $img = \Drupal::service('image.factory')->get($real);
    if ($img->isValid() && $img->getWidth() > MAX_WIDTH) {
      $img->scale(MAX_WIDTH);
      $img->save();
    }
  }
  $file = File::create(['uri' => $uri, 'filename' => basename($uri), 'uid' => 1, 'status' => 1, 'filemime' => mime_content_type($real) ?: 'image/jpeg']);
  $file->save();
  return $file;
};

/** media (d_image) id => [File, alt] */
$media_image = function (int $mid) use ($legacy, $make_file) {
  $r = $legacy->query("select f.uri, i.field_media_image_alt alt from {media__field_media_image} i join {file_managed} f on f.fid=i.field_media_image_target_id where i.entity_id=:m", [':m' => $mid])->fetchObject();
  if (!$r) return NULL;
  $f = $make_file($r->uri);
  return $f ? [$f, (string) $r->alt] : NULL;
};
$img_tag = function (File $f, string $alt) use ($urlgen) {
  $size = @getimagesize(\Drupal::service('file_system')->realpath($f->getFileUri())) ?: [0, 0];
  $wh = $size[0] ? sprintf(' width="%d" height="%d"', $size[0], $size[1]) : '';
  return sprintf('<img src="%s" alt="%s" data-entity-type="file" data-entity-uuid="%s"%s>', $urlgen->generateString($f->getFileUri()), htmlspecialchars($alt, ENT_QUOTES), $f->uuid(), $wh);
};
$link = function (?string $uri) {
  if ($uri === NULL || $uri === '') return '';
  return str_starts_with($uri, 'internal:') ? substr($uri, 9) : (str_starts_with($uri, 'entity:node/') ? '/node/' . substr($uri, 12) : $uri);
};

$val = fn(string $table, string $col, int $pid) => $legacy->query("select $col from {paragraph__$table} where entity_id=:p order by delta limit 1", [':p' => $pid])->fetchField();

/** Flatten one paragraph into HTML. */
$flatten = function (int $pid, string $type) use ($legacy, $val, $media_image, $img_tag, $link) {
  $html = '';
  if ($t = trim((string) $val('field_d_main_title', 'field_d_main_title_value', $pid))) $html .= '<h2>' . $t . "</h2>\n";
  if ($t = trim((string) $val('field_d_subtitle', 'field_d_subtitle_value', $pid))) $html .= '<h3>' . $t . "</h3>\n";
  if ($t = $val('field_d_long_text', 'field_d_long_text_value', $pid)) $html .= $t . "\n";
  if (in_array($type, ['d_p_blog_image', 'd_p_gallery'])) {
    foreach ($legacy->query("select field_d_media_image_target_id m from {paragraph__field_d_media_image} where entity_id=:p order by delta", [':p' => $pid])->fetchCol() as $mid) {
      if ($mi = $media_image((int) $mid)) $html .= '<p>' . $img_tag($mi[0], $mi[1]) . "</p>\n";
    }
  }
  $cta = $legacy->query("select field_d_cta_link_uri u, field_d_cta_link_title t from {paragraph__field_d_cta_link} where entity_id=:p limit 1", [':p' => $pid])->fetchObject();
  if ($cta && $cta->u) $html .= sprintf('<p><a href="%s">%s</a></p>', htmlspecialchars($link($cta->u), ENT_QUOTES), htmlspecialchars($cta->t ?: $cta->u)) . "\n";
  return $html;
};

$aliases = [];
foreach ($legacy->query("select path, alias from {path_alias} where path like '/node/%' and status=1") as $r) $aliases[(int) substr($r->path, 6)] = $r->alias;

$cat_term = function (int $old_tid) use ($legacy) {
  $name = $legacy->query("select name from {taxonomy_term_field_data} where tid=:t", [':t' => $old_tid])->fetchField();
  if (!$name) return NULL;
  $ex = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => $name, 'vid' => 'blog_kategoria']);
  if ($ex) return reset($ex)->id();
  $t = Term::create(['vid' => 'blog_kategoria', 'name' => $name, 'langcode' => 'hu']); $t->save(); return $t->id();
};

$n = 0; $skipped = 0;
foreach ($legacy->query("select * from {node_field_data} where type='blog_post' order by nid")->fetchAll() as $row) {
  if (Node::load($row->nid)) { $skipped++; continue; }
  $html = '';
  $secs = $legacy->query("select p.id, p.type from {node__field_blog_sections} s join {paragraphs_item_field_data} p on p.id=s.field_blog_sections_target_id and p.revision_id=s.field_blog_sections_target_revision_id where s.entity_id=:n order by s.delta", [':n' => $row->nid])->fetchAll();
  foreach ($secs as $s) $html .= $flatten((int) $s->id, $s->type);
  $teaser = $legacy->query("select field_blog_teaser_value from {node__field_blog_teaser} where entity_id=:n", [':n' => $row->nid])->fetchField() ?: '';
  $v = ['nid' => (int) $row->nid, 'type' => 'blog_post', 'title' => $row->title, 'status' => (int) $row->status, 'created' => (int) $row->created,
    'changed' => (int) $row->changed, 'uid' => 1, 'langcode' => 'hu', 'promote' => 0, 'sticky' => (int) $row->sticky,
    'body' => ['value' => $html !== '' ? $html : $teaser, 'summary' => $teaser, 'format' => 'full_html']];
  if (isset($aliases[$row->nid])) $v['path'] = ['alias' => $aliases[$row->nid], 'pathauto' => PathautoState::SKIP];
  if ($mid = $legacy->query("select b.field_blog_media_main_image_target_id from {node__field_blog_media_main_image} b join {media_field_data} m on m.mid=b.field_blog_media_main_image_target_id where b.entity_id=:n and m.bundle='d_image'", [':n' => $row->nid])->fetchField()) {
    if ($mi = $media_image((int) $mid)) $v['field_kep'] = ['target_id' => $mi[0]->id(), 'alt' => $mi[1]];
  }
  if ($tid = $legacy->query("select field_blog_category_target_id from {node__field_blog_category} where entity_id=:n", [':n' => $row->nid])->fetchField()) {
    if ($new = $cat_term((int) $tid)) $v['field_kategoria'] = ['target_id' => $new];
  }
  Node::create($v)->save();
  $n++;
  \Drupal::entityTypeManager()->getStorage('node')->resetCache();
}
echo "blog_post: $n created, $skipped skipped\n";
echo "MISSING FILES (", count($missing), "):\n", implode("\n", array_slice($missing, 0, 20)), "\n";
