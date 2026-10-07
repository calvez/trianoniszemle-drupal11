<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$aliasRow = $c->query("select path from {path_alias} where alias='/blog/szidiropulosz-archimedesz-szent-istvan-dij-2021-evi-dijazottja'")->fetchField();
$nid = (int) substr($aliasRow, 6); echo "nid $nid\n";
$secs = $c->query("select p.id, p.type from {node__field_blog_sections} s join {paragraphs_item_field_data} p on p.id=s.field_blog_sections_target_id and p.revision_id=s.field_blog_sections_target_revision_id where s.entity_id=:n order by s.delta", [':n' => $nid])->fetchAll();
foreach ($secs as $s) {
  echo "section $s->id $s->type:";
  foreach ($c->query("select table_name from information_schema.tables where table_schema=database() and table_name like 'trn\\_paragraph\\_\\_field\\_%'")->fetchCol() as $t) {
    $n = $c->query("select count(*) from $t where entity_id=:p", [':p' => $s->id])->fetchField(); if ($n) echo ' ', substr($t, 14), "($n)";
  }
  echo "\n";
}
$f = $c->query("select fid, uri from {file_managed} where uri like '%SZA-Oklev%'")->fetchAll(); echo "file: ", json_encode($f, JSON_UNESCAPED_UNICODE), "\n";
foreach ($f as $r) {
  foreach ($c->query("select entity_id from {media__field_media_image} where field_media_image_target_id=:f", [':f' => $r->fid])->fetchCol() as $mid) {
    echo "media $mid used in: ";
    foreach ($c->query("select table_name from information_schema.tables where table_schema=database() and table_name like 'trn\\_%field\\_d\\_media\\_%' or table_name like 'trn\\_node\\_\\_field%media%'")->fetchCol() as $t) {
      $col = $c->query("select column_name from information_schema.columns where table_schema=database() and table_name=:t and column_name like '%target_id'", [':t' => $t])->fetchField();
      if ($col) { $n = $c->query("select entity_id from $t where $col=:m", [':m' => $mid])->fetchAll(); if ($n) echo substr($t, 4), ':', json_encode(array_column($n, 'entity_id')), ' '; }
    }
    echo "\n";
  }
}
