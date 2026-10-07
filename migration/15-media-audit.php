<?php
/**
 * @file
 * Media / file audit (read-only except: empty alt text of photo fields is set to the node title).
 * Run: drush php:script migration/15-media-audit.php
 */
$db = \Drupal::database();
$pub = DRUPAL_ROOT . '/sites/default/files'; $priv = realpath(DRUPAL_ROOT . '/../private');
$real = fn(string $uri) => str_starts_with($uri, 'private://') ? "$priv/" . substr($uri, 10) : "$pub/" . substr($uri, 9);

// 1. managed files exist on disk -----------------------------------------------------------------
$files = $db->query("select fid, uri, filesize, filemime, status from {file_managed}")->fetchAll();
$missing = []; $bytes = ['public' => 0, 'private' => 0]; $n = ['public' => 0, 'private' => 0];
foreach ($files as $f) {
  $s = str_starts_with($f->uri, 'private://') ? 'private' : 'public'; $n[$s]++;
  $p = $real($f->uri);
  if (!is_file($p)) { $missing[] = $f->uri; continue; }
  $bytes[$s] += filesize($p);
}
printf("1. managed files: %d public (%.0f MB), %d private (%.0f MB) | missing on disk: %d %s\n", $n['public'], $bytes['public'] / 1048576, $n['private'], $bytes['private'] / 1048576, count($missing), json_encode(array_slice($missing, 0, 3)));

// 2. PDFs are real PDFs --------------------------------------------------------------------------------
$bad = []; $pdfs = 0;
foreach ($files as $f) { if (!str_ends_with(strtolower($f->uri), '.pdf')) continue; $pdfs++; $p = $real($f->uri); if (is_file($p)) { $h = file_get_contents($p, FALSE, NULL, 0, 5); if ($h !== '%PDF-') $bad[] = $f->uri; if (filesize($p) < 1000) $bad[] = "tiny: $f->uri"; } }
printf("2. PDFs: %d checked, invalid/tiny: %d %s\n", $pdfs, count($bad), json_encode(array_slice($bad, 0, 3)));

// 3. file references in bodies resolve -------------------------------------------------------------------
$broken = []; $refs = 0;
foreach ($db->query("select entity_id, body_value, body_summary from {node__body}") as $r) {
  foreach ([$r->body_value, $r->body_summary] as $h) {
    if (preg_match_all('#(?:src|href)="(/(?:sites/default/files|system/files)/[^"?\#]+)#', (string) $h, $m)) foreach ($m[1] as $u) {
      $refs++; $u = urldecode($u);
      $p = str_starts_with($u, '/system/files/') ? "$priv/" . substr($u, 14) : DRUPAL_ROOT . $u;
      if (!is_file($p) && !str_contains($u, '/styles/')) $broken[$u . " (node $r->entity_id)"] = 1;
    }
  }
}
printf("3. file links in bodies: %d, broken: %d %s\n", $refs, count($broken), json_encode(array_slice(array_keys($broken), 0, 4), JSON_UNESCAPED_UNICODE));

// 4. oversized images ------------------------------------------------------------------------------------
$big = []; $huge = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pub, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
  $path = $file->getPathname(); if (str_contains($path, '/styles/') || str_contains($path, '/css/') || str_contains($path, '/js/') || str_contains($path, '/php/')) continue;
  if (!preg_match('/\.(jpe?g|png|gif|webp)$/i', $path)) continue;
  $sz = @getimagesize($path); if (!$sz) continue;
  if (max($sz[0], $sz[1]) > 2000) $big[] = basename($path) . " {$sz[0]}x{$sz[1]}";
  if ($file->getSize() > 3 * 1048576) $huge++;
}
printf("4. images wider/taller than 2000px: %d | files over 3 MB: %d %s\n", count($big), $huge, json_encode(array_slice($big, 0, 3)));

// 5. orphan files (no node field, no body reference) ----------------------------------------------------------------
$used = [];
foreach (['node__field_kep' => 'field_kep_target_id', 'node__field_pdf' => 'field_pdf_target_id'] as $t => $c) foreach ($db->query("select $c fid from {{$t}}") as $r) $used[$r->fid] = 1;
foreach ($db->query("select body_value from {node__body}") as $r) { if (preg_match_all('/data-entity-uuid="([^"]+)"/', $r->body_value, $m)) foreach ($m[1] as $u) { $fid = $db->query("select fid from {file_managed} where uuid=:u", [':u' => $u])->fetchField(); if ($fid) $used[$fid] = 1; } }
$orph = array_filter($files, fn($f) => !isset($used[$f->fid]));
printf("5. managed files not referenced by any field/body: %d %s\n", count($orph), json_encode(array_map(fn($f) => $f->uri, array_slice($orph, 0, 3))));

// 6. alt text of photo fields: set empty alt to the node title -----------------------------------------------------------------
$fixed = 0; $s = \Drupal::entityTypeManager()->getStorage('node');
foreach (['szerzo', 'lapszam', 'blog_post'] as $bundle) {
  foreach ($s->loadByProperties(['type' => $bundle]) as $node) {
    if (!$node->hasField('field_kep') || $node->get('field_kep')->isEmpty()) continue;
    $alt = trim((string) $node->get('field_kep')->alt);
    if ($alt === '') {
      $old = $node->getChangedTime(); $node->get('field_kep')->alt = $node->label(); $node->save();
      foreach (['node_field_data', 'node_field_revision'] as $t) $db->update($t)->fields(['changed' => $old])->condition('nid', $node->id())->execute();
      $fixed++;
    }
  }
  $s->resetCache();
}
echo "6. photo fields with empty alt text, now set to the node title: $fixed\n";
$empty_alt = 0; $all = 0;
foreach ($db->query("select body_value from {node__body}") as $r) { if (preg_match_all('/<img\b[^>]*>/i', $r->body_value, $m)) foreach ($m[0] as $tag) { $all++; if (!preg_match('/\balt="[^"]+"/i', $tag)) $empty_alt++; } }
echo "7. images inside body text without alt text: $empty_alt of $all (left empty on purpose: repeating the post title on every gallery photo is worse for screen readers)\n";
