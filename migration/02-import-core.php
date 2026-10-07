<?php

/**
 * @file
 * One-off import (phase A): authors, issues, articles + their files, from the
 * legacy tables (database "legacy", prefix trn_). Keeps node IDs and the exact
 * old URL aliases. Idempotent: existing nids are skipped.
 *
 * Run: drush php:script migration/02-import-core.php
 */

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\pathauto\PathautoState;

$legacy = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$OLD_PUBLIC = '/home/ploi/trianon-d10-upgrade/web/sites/default/files';
$OLD_PRIVATE = '/home/ploi/trianon-d10-upgrade/private';
$fs = \Drupal::service('file_system');
$missing = [];
$stats = [];

/** Copy a legacy file into place and return a File entity (reused by uri). */
$make_file = function (string $old_uri) use ($OLD_PUBLIC, $OLD_PRIVATE, $fs, &$missing): ?File {
  [$scheme, $rel] = explode('://', $old_uri, 2);
  $src = ($scheme === 'private' ? $OLD_PRIVATE : $OLD_PUBLIC) . '/' . $rel;
  if (!is_file($src)) { $missing[] = $old_uri; return NULL; }
  $existing = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $old_uri]);
  if ($existing) { return reset($existing); }
  $dest = $old_uri;
  $dir = dirname($dest);
  $fs->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
  $real = $fs->realpath($dest) ?: ($fs->realpath($dir) . '/' . basename($dest));
  if (!is_file($real)) { copy($src, $real); }
  $file = File::create(['uri' => $dest, 'filename' => basename($dest), 'uid' => 1, 'status' => 1, 'filemime' => mime_content_type($real) ?: 'application/octet-stream']);
  $file->save();
  return $file;
};

$aliases = [];
foreach ($legacy->query("select path, alias from {path_alias} where path like '/node/%' and status=1") as $r) {
  $aliases[(int) substr($r->path, 6)] = $r->alias;
}

/** Common node values. */
$base = function ($row, string $bundle) use ($aliases) {
  $v = ['nid' => (int) $row->nid, 'type' => $bundle, 'title' => $row->title, 'status' => (int) $row->status,
    'created' => (int) $row->created, 'changed' => (int) $row->changed, 'uid' => 1, 'langcode' => 'hu', 'promote' => 0, 'sticky' => (int) $row->sticky];
  if (isset($aliases[$row->nid])) {
    $v['path'] = ['alias' => $aliases[$row->nid], 'pathauto' => PathautoState::SKIP];
  }
  return $v;
};
$body = function (int $nid, string $old_bundle) use ($legacy) {
  $b = $legacy->query("select body_value v, body_summary s, body_format f from {node__body} where entity_id=:n and bundle=:b", [':n' => $nid, ':b' => $old_bundle])->fetchObject();
  return ($b && trim(strip_tags((string) $b->v, '<img><iframe>')) !== '') ? ['value' => $b->v, 'summary' => $b->s, 'format' => $b->f ?: 'full_html'] : NULL;
};
$image_for = function (int $nid, string $old_bundle) use ($legacy, $make_file) {
  $r = $legacy->query("select f.uri, i.field_media_image_alt alt from {node__field_teaser_media_image} t join {media__field_media_image} i on i.entity_id=t.field_teaser_media_image_target_id join {file_managed} f on f.fid=i.field_media_image_target_id where t.entity_id=:n and t.bundle=:b", [':n' => $nid, ':b' => $old_bundle])->fetchObject();
  if (!$r) return NULL;
  $f = $make_file($r->uri);
  return $f ? ['target_id' => $f->id(), 'alt' => $r->alt ?? ''] : NULL;
};

$run = function (string $old_bundle, string $bundle, callable $extra) use ($legacy, $base, &$stats) {
  $n = 0; $skipped = 0;
  $rows = $legacy->query("select * from {node_field_data} where type=:t order by nid", [':t' => $old_bundle])->fetchAll();
  foreach ($rows as $row) {
    if (Node::load($row->nid)) { $skipped++; continue; }
    $values = $base($row, $bundle);
    $values += $extra($row);
    Node::create($values)->save();
    if (++$n % 100 === 0) { \Drupal::entityTypeManager()->getStorage('node')->resetCache(); echo "  $bundle: $n\n"; }
  }
  $stats[$bundle] = "$n created, $skipped already there";
  echo "$bundle: $n created, $skipped skipped\n";
};

// 1. Authors.
$run('tsz_szerzo', 'szerzo', function ($row) use ($legacy, $body, $image_for) {
  $v = [];
  if ($b = $body($row->nid, 'tsz_szerzo')) $v['body'] = $b;
  if ($t = $legacy->query("select field_tipus_value from {node__field_tipus} where entity_id=:n", [':n' => $row->nid])->fetchField()) $v['field_tipus'] = $t;
  if ($i = $image_for($row->nid, 'tsz_szerzo')) $v['field_kep'] = $i;
  return $v;
});

// 2. Issues.
$run('tsz_lapszam', 'lapszam', function ($row) use ($legacy, $body, $image_for) {
  $v = [];
  if ($b = $body($row->nid, 'tsz_lapszam')) $v['body'] = $b;
  foreach (['evfolyam' => 'field_evfolyam', 'lapszam' => 'field_lapszam', 'sorszam' => 'field_sorszam'] as $col => $f) {
    $val = $legacy->query("select {$f}_value from {node__$f} where entity_id=:n", [':n' => $row->nid])->fetchField();
    if ($val !== FALSE && $val !== NULL) $v[$f] = $val;
  }
  if ($i = $image_for($row->nid, 'tsz_lapszam')) $v['field_kep'] = $i;
  return $v;
});

// 3. Articles.
$run('trianoni_szemle', 'cikk', function ($row) use ($legacy, $body, $make_file) {
  $v = [];
  if ($b = $body($row->nid, 'trianoni_szemle')) $v['body'] = $b;
  foreach (['field_alcim' => 'value', 'field_oldalszam' => 'value'] as $f => $col) {
    $val = $legacy->query("select {$f}_$col from {node__$f} where entity_id=:n", [':n' => $row->nid])->fetchField();
    if ($val !== FALSE && $val !== NULL && $val !== '') $v[$f] = $val;
  }
  if ($s = $legacy->query("select field_szam_target_id from {node__field_szam} where entity_id=:n", [':n' => $row->nid])->fetchField()) $v['field_szam'] = ['target_id' => $s];
  $a = $legacy->query("select field_szerzo_target_id from {node__field_szerzo} where entity_id=:n order by delta", [':n' => $row->nid])->fetchCol();
  if ($a) $v['field_szerzo'] = array_map(fn($x) => ['target_id' => $x], $a);
  if ($uri = $legacy->query("select f.uri from {node__field_pdf} p join {file_managed} f on f.fid=p.field_pdf_target_id where p.entity_id=:n", [':n' => $row->nid])->fetchField()) {
    if ($f = $make_file($uri)) $v['field_pdf'] = ['target_id' => $f->id(), 'display' => 1, 'description' => ''];
  }
  return $v;
});

echo "\nSTATS: ", json_encode($stats), "\n";
echo "MISSING FILES (", count($missing), "):\n", implode("\n", array_slice($missing, 0, 20)), "\n";
