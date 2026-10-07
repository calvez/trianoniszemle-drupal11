<?php

/**
 * @file
 * Content QA fix: rebuild the bodies of imported blog posts and pages from the
 * legacy paragraphs, this time (a) converting <drupal-media> embeds (documents ->
 * links, images -> <img>) and (b) keeping the small "icon" images (portraits).
 * Updates existing nodes in place (body only); original `changed` dates are
 * restored. Idempotent. Run: drush php:script migration/13-fix-bodies.php
 */

use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

const MAX_W = 1600;
$legacy = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$db = \Drupal::database();
$OLD = '/home/ploi/trianon-d10-upgrade/web/sites/default/files';
$fs = \Drupal::service('file_system');
$urlgen = \Drupal::service('file_url_generator');
$stats = ['icons' => 0, 'doc_links' => 0, 'embed_imgs' => 0, 'embed_missing' => 0, 'files_missing' => []];

$copy = function (string $uri, bool $scale) use ($OLD, $fs, &$stats): ?string {
  $rel = substr($uri, strlen('public://'));
  $src = "$OLD/$rel"; $dst = DRUPAL_ROOT . "/sites/default/files/$rel";
  if (!is_file($dst)) {
    if (!is_file($src)) { $stats['files_missing'][] = $uri; return NULL; }
    @mkdir(dirname($dst), 0775, TRUE); copy($src, $dst);
    if ($scale) { $img = \Drupal::service('image.factory')->get($dst); if ($img->isValid() && $img->getWidth() > MAX_W) { $img->scale(MAX_W); $img->save(); } }
  }
  return $uri;
};
$managed = function (string $uri): File {
  $ex = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
  if ($ex) return reset($ex);
  $f = File::create(['uri' => $uri, 'filename' => basename($uri), 'uid' => 1, 'status' => 1, 'filemime' => mime_content_type(DRUPAL_ROOT . '/sites/default/files/' . substr($uri, 9)) ?: 'image/jpeg']);
  $f->save(); return $f;
};
$img_html = function (string $uri, string $alt, string $cls = '') use ($copy, $managed, $urlgen, &$stats): string {
  if (!$copy($uri, TRUE)) return '';
  $f = $managed($uri);
  $real = DRUPAL_ROOT . '/sites/default/files/' . substr($uri, 9);
  $sz = @getimagesize($real) ?: [0, 0];
  return sprintf('<img src="%s" alt="%s" data-entity-type="file" data-entity-uuid="%s"%s%s>', $urlgen->generateString($uri), htmlspecialchars($alt, ENT_QUOTES), $f->uuid(),
    $sz[0] ? sprintf(' width="%d" height="%d"', $sz[0], $sz[1]) : '', $cls ? ' class="' . $cls . '" data-align="' . str_replace('align-', '', $cls) . '"' : '');
};
$media_img = fn(int $mid) => $legacy->query("select f.uri, i.field_media_image_alt alt from {media__field_media_image} i join {file_managed} f on f.fid=i.field_media_image_target_id where i.entity_id=:m", [':m' => $mid])->fetchObject();

/** Replace <drupal-media> embeds with real HTML. */
$convert_embeds = function (string $html) use ($legacy, $copy, $urlgen, $img_html, $media_img, &$stats): string {
  return preg_replace_callback('#<drupal-media\b[^>]*data-entity-uuid="([^"]+)"[^>]*>\s*</drupal-media>#i', function ($m) use ($legacy, $copy, $urlgen, $img_html, $media_img, &$stats) {
    $tag = $m[0];
    $align = preg_match('/data-align="(\w+)"/', $tag, $a) ? 'align-' . $a[1] : '';
    $media = $legacy->query("select m.bundle, m.mid, d.name from {media} m join {media_field_data} d on d.mid=m.mid where m.uuid=:u", [':u' => $m[1]])->fetchObject();
    if (!$media) { $stats['embed_missing']++; return ''; }
    if ($media->bundle === 'd_document') {
      $uri = $legacy->query("select f.uri from {media__field_media_file} x join {file_managed} f on f.fid=x.field_media_file_target_id where x.entity_id=:m", [':m' => $media->mid])->fetchField();
      if ($uri && $copy($uri, FALSE)) { $stats['doc_links']++; return '<p><a href="' . $urlgen->generateString($uri) . '">' . htmlspecialchars($media->name) . '</a></p>'; }
      $stats['embed_missing']++; return '';
    }
    if ($media->bundle === 'd_image' && ($r = $media_img((int) $media->mid))) { $stats['embed_imgs']++; return '<p>' . $img_html($r->uri, (string) $r->alt, $align) . '</p>'; }
    $stats['embed_missing']++; return '';
  }, $html);
};

$val = fn(string $t, string $col, int $pid) => $legacy->query("select $col from {paragraph__$t} where entity_id=:p order by delta limit 1", [':p' => $pid])->fetchField();
$link = fn($u) => str_starts_with((string) $u, 'internal:') ? substr($u, 9) : (string) $u;

$flatten = function (int $pid, string $type) use ($legacy, $val, $convert_embeds, $img_html, $media_img, $link, &$stats) {
  $html = '';
  if (in_array($type, ['d_p_form'])) return '';
  // banner / section background image -> a normal image at the top of the section
  $bg = $legacy->query("select field_d_media_background_target_id m from {paragraph__field_d_media_background} where entity_id=:p limit 1", [':p' => $pid])->fetchField();
  if ($bg && ($r = $media_img((int) $bg))) { $h = $img_html($r->uri, (string) $r->alt); if ($h) { $stats['backgrounds'] = ($stats['backgrounds'] ?? 0) + 1; $html .= '<p>' . $h . "</p>\n"; } }
  // icon / portrait image
  $icon = $legacy->query("select field_d_media_icon_target_id m from {paragraph__field_d_media_icon} where entity_id=:p limit 1", [':p' => $pid])->fetchField();
  if ($icon && ($r = $media_img((int) $icon))) { $h = $img_html($r->uri, (string) $r->alt, 'align-left'); if ($h) { $stats['icons']++; $html .= '<p>' . $h . "</p>\n"; } }
  if ($t = trim((string) $val('field_d_main_title', 'field_d_main_title_value', $pid))) $html .= "<h2>$t</h2>\n";
  if ($t = trim((string) $val('field_d_subtitle', 'field_d_subtitle_value', $pid))) $html .= "<h3>$t</h3>\n";
  if ($t = $val('field_d_long_text', 'field_d_long_text_value', $pid)) $html .= $convert_embeds($t) . "\n";
  if (in_array($type, ['d_p_blog_image', 'd_p_gallery'])) {
    foreach ($legacy->query("select field_d_media_image_target_id m from {paragraph__field_d_media_image} where entity_id=:p order by delta", [':p' => $pid])->fetchCol() as $mid) {
      if ($r = $media_img((int) $mid)) $html .= '<p>' . $img_html($r->uri, (string) $r->alt) . "</p>\n";
    }
  }
  $cta = $legacy->query("select field_d_cta_link_uri u, field_d_cta_link_title t from {paragraph__field_d_cta_link} where entity_id=:p limit 1", [':p' => $pid])->fetchObject();
  if ($cta && $cta->u) $html .= sprintf('<p><a href="%s">%s</a></p>' . "\n", htmlspecialchars($link($cta->u), ENT_QUOTES), htmlspecialchars($cta->t ?: $cta->u));
  return $html;
};

$updated = 0; $same = 0;
$targets = [];
foreach ($legacy->query("select nid, type from {node_field_data} where type in ('blog_post','content_page')") as $r) {
  if ($r->type === 'content_page' && !in_array((int) $r->nid, [489, 494, 495, 498, 499, 500, 501, 502, 2540])) continue;
  $targets[] = [(int) $r->nid, $r->type];
}
foreach ($targets as [$nid, $type]) {
  $node = Node::load($nid); if (!$node) continue;
  $field = $type === 'blog_post' ? 'field_blog_sections' : 'field_page_section';
  $secs = $legacy->query("select p.id, p.type from {node__$field} s join {paragraphs_item_field_data} p on p.id=s.{$field}_target_id and p.revision_id=s.{$field}_target_revision_id where s.entity_id=:n order by s.delta", [':n' => $nid])->fetchAll();
  $html = '';
  foreach ($secs as $s) $html .= $flatten((int) $s->id, $s->type);
  if ($html === '') continue;   // blog posts without sections keep the teaser-only body
  $old_changed = $node->getChangedTime();
  if (trim($node->body->value) === trim($html)) { $same++; continue; }
  $summary = $node->body->summary;
  // Summary (teaser) may also contain embeds.
  $summary = $convert_embeds((string) $summary);
  $node->set('body', ['value' => $html, 'summary' => $summary, 'format' => 'full_html']);
  $node->save();
  foreach (['node_field_data', 'node_field_revision'] as $t) $db->update($t)->fields(['changed' => $old_changed])->condition('nid', $nid)->execute();
  $updated++;
}
\Drupal::entityTypeManager()->getStorage('node')->resetCache();
echo "bodies updated: $updated, unchanged: $same\n";
echo "background images added: ", $stats['backgrounds'] ?? 0, " | icons added: {$stats['icons']} | document links: {$stats['doc_links']} | embedded images: {$stats['embed_imgs']} | embeds unresolved: {$stats['embed_missing']}\n";
echo "files missing (", count($stats['files_missing']), "): ", implode(', ', array_slice(array_unique($stats['files_missing']), 0, 8)), "\n";
