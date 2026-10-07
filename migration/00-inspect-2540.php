<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$tabs = $c->query("select table_name from information_schema.tables where table_schema=database() and table_name like 'trn\\_paragraph\\_\\_%'")->fetchCol();
$ids = $c->query("select field_page_section_target_id from {node__field_page_section} where entity_id in (2540, 502)")->fetchCol();
foreach ([2540, 502] as $nid) {
  $pids = $c->query("select field_page_section_target_id from {node__field_page_section} where entity_id=:n order by delta", [':n' => $nid])->fetchCol();
  echo "node $nid sections: ", count($pids), "\n";
  $used = [];
  foreach ($tabs as $t) { $n = $c->query("select count(*) from $t where entity_id in (:p[])", [':p[]' => $pids])->fetchField(); if ($n) $used[] = substr($t, 14) . "($n)"; }
  echo "  fields used: ", implode(', ', $used), "\n";
}
