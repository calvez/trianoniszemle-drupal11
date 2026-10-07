<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$n = \Drupal::database();
echo "new site nodes with <drupal-media: ", $n->query("select count(*) from {node__body} where body_value like '%<drupal-media%'")->fetchField(), " (pages+blog+others)\n";
foreach ($n->query("select bundle, count(*) c, sum((length(body_value)-length(replace(body_value,'<drupal-media','')))/13) tags from {node__body} where body_value like '%<drupal-media%' group by bundle") as $r) echo "   $r->bundle: $r->c nodes, $r->tags tags\n";
echo "legacy node__body with drupal-media: ", $c->query("select count(*) from {node__body} where body_value like '%drupal-media%'")->fetchField(), "\n";
echo "legacy paragraph long_text with drupal-media: ", $c->query("select count(*) from {paragraph__field_d_long_text} where field_d_long_text_value like '%drupal-media%'")->fetchField(), "\n";
echo "media bundles used by embeds:\n";
$uuids = [];
foreach ($n->query("select body_value from {node__body} where body_value like '%<drupal-media%'") as $r) { preg_match_all('/data-entity-uuid="([^"]+)"/', $r->body_value, $m); foreach ($m[1] as $u) $uuids[$u] = 1; }
echo "  distinct embedded media uuids: ", count($uuids), "\n";
$by = [];
foreach (array_keys($uuids) as $u) { $row = $c->query("select m.bundle, m.mid, d.name from {media} m join {media_field_data} d on d.mid=m.mid where m.uuid=:u", [':u' => $u])->fetchObject() ?: NULL; $by[$row ? $row->bundle : 'MISSING'][] = $row ? $row->name : $u; }
foreach ($by as $b => $names) echo "  $b: ", count($names), " e.g. ", json_encode(array_slice($names, 0, 2), JSON_UNESCAPED_UNICODE), "\n";
echo "legacy media tables: ", implode(', ', $c->query("select table_name from information_schema.tables where table_schema=database() and table_name like 'trn\\_media\\_\\_%'")->fetchCol()), "\n";
echo "media_icon uses by paragraph type: "; foreach ($c->query("select bundle, count(*) c from {paragraph__field_d_media_icon} group by bundle") as $r) echo "$r->bundle=$r->c "; echo "\n";
echo "html attrs seen: ", json_encode(array_count_values(array_merge(...array_map(fn($r) => (preg_match_all('/data-align="(\w+)"/', $r, $m) ? $m[1] : []), array_column($n->query("select body_value from {node__body} where body_value like '%<drupal-media%'")->fetchAll(PDO::FETCH_ASSOC), 'body_value') ?: [''])))), "\n";
