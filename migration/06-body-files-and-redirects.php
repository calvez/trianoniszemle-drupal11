<?php

/**
 * @file
 * One-off (phase B3): (a) copy files that imported body text links to by plain
 * /sites/default/files/... path; (b) import legacy redirects whose target
 * exists in the new site. Idempotent.
 * Run: drush php:script migration/06-body-files-and-redirects.php
 */

use Drupal\redirect\Entity\Redirect;

$OLD = '/home/ploi/trianon-d10-upgrade/web/sites/default/files';
$NEW = DRUPAL_ROOT . '/sites/default/files';
$db = \Drupal::database();

// (a) body files ----------------------------------------------------------------
$refs = [];
foreach ($db->query("select body_value v from {node__body}") as $r) {
  if (preg_match_all('#/sites/default/files/([^"\'\s)<>?\#]+)#', $r->v, $m)) foreach ($m[1] as $p) $refs[urldecode($p)] = 1;
}
$copied = 0; $present = 0; $missing = []; $styles = 0;
foreach (array_keys($refs) as $rel) {
  if (str_starts_with($rel, 'styles/')) { $styles++; $missing[] = "(style) $rel"; continue; }
  $src = "$OLD/$rel"; $dst = "$NEW/$rel";
  if (is_file($dst)) { $present++; continue; }
  if (!is_file($src)) { $missing[] = $rel; continue; }
  @mkdir(dirname($dst), 0775, TRUE);
  copy($src, $dst);
  $img = \Drupal::service('image.factory')->get($dst);
  if ($img->isValid() && $img->getWidth() > 1600) { $img->scale(1600); $img->save(); }
  $copied++;
}
echo "body file refs: ", count($refs), " | copied $copied, already there $present, missing/other ", count($missing), "\n";
foreach (array_slice($missing, 0, 12) as $m) echo "   $m\n";

// (b) redirects -----------------------------------------------------------------
$legacy = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$made = 0; $skipped_target = 0; $dupe = 0; $self = 0;
$existing_alias = [];
foreach ($db->query("select alias from {path_alias}") as $r) $existing_alias[ltrim($r->alias, '/')] = 1;
foreach ($legacy->query("select * from {redirect}")->fetchAll() as $r) {
  $uri = $r->redirect_redirect__uri;
  if (preg_match('#^internal:/node/(\d+)$#', $uri, $m)) {
    if (!\Drupal\node\Entity\Node::load($m[1])) { $skipped_target++; continue; }
  }
  elseif (str_starts_with($uri, 'internal:/') || str_starts_with($uri, 'entity:')) { $skipped_target++; continue; }
  $source = ltrim($r->redirect_source__path, '/');
  if (isset($existing_alias[$source])) { $self++; continue; }
  if ($db->query("select count(*) from {redirect} where redirect_source__path=:p", [':p' => $source])->fetchField()) { $dupe++; continue; }
  Redirect::create(['redirect_source' => ['path' => $source, 'query' => unserialize($r->redirect_source__query) ?: []], 'redirect_redirect' => ['uri' => $uri],
    'language' => 'hu', 'status_code' => (int) $r->status_code])->save();
  $made++;
}
echo "redirects: created $made | skipped (target not imported) $skipped_target | source is a live alias $self | duplicate $dupe\n";
