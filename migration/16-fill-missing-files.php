<?php
/**
 * @file
 * Copy files that imported body/summary text links to (plain /sites/default/files/ paths)
 * but that are missing in the new site, from the live site's files folder (read-only source).
 * Large images are scaled to 1600px. Idempotent.
 */
$LIVE = '/home/ploi/trianoniszemle.hu/web/sites/default/files';
$NEW = DRUPAL_ROOT . '/sites/default/files';
$db = \Drupal::database();
$refs = [];
foreach ($db->query("select body_value, body_summary from {node__body}") as $r) {
  foreach ([$r->body_value, $r->body_summary] as $h) {
    if (preg_match_all('#/sites/default/files/([^"\'\s)<>?\#]+)#', (string) $h, $m)) foreach ($m[1] as $p) $refs[urldecode($p)] = 1;
  }
}
$copied = []; $still = []; $present = 0;
foreach (array_keys($refs) as $rel) {
  if (str_starts_with($rel, 'styles/')) continue;
  $dst = "$NEW/$rel";
  if (is_file($dst)) { $present++; continue; }
  $src = "$LIVE/$rel";
  if (!is_file($src)) { $still[] = $rel; continue; }
  @mkdir(dirname($dst), 0775, TRUE); copy($src, $dst);
  $img = \Drupal::service('image.factory')->get($dst);
  if ($img->isValid() && $img->getWidth() > 1600) { $img->scale(1600); $img->save(); }
  $copied[] = $rel;
}
printf("referenced files: %d | already present: %d | copied from live: %d | still missing (also missing on live): %d\n", count($refs), $present, count($copied), count($still));
foreach ($copied as $c) echo "  + $c\n";
foreach ($still as $c) echo "  ! $c\n";
