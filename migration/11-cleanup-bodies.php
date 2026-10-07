<?php
// Clear bodies that contain nothing but whitespace/&nbsp;/empty tags (legacy leftovers).
$s = \Drupal::entityTypeManager()->getStorage('node');
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->exists('body')->execute();
$cleared = 0; $by = [];
foreach (array_chunk($ids, 200) as $chunk) {
  foreach ($s->loadMultiple($chunk) as $n) {
    $v = (string) $n->body->value;
    if (preg_match('/<(img|iframe|video|audio|object|embed)\b/i', $v)) continue;
    $text = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    if ($text === '' && ($v !== '' || (string) $n->body->summary !== '')) {
      $n->set('body', NULL); $n->save(); $cleared++; $by[$n->bundle()] = ($by[$n->bundle()] ?? 0) + 1;
    }
  }
  $s->resetCache();
}
echo "cleared $cleared empty bodies: ", json_encode($by), "\n";
