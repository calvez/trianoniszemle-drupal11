<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$real = [5, 7, 489, 494, 495, 498, 499, 500, 501, 502, 2540];
foreach ($real as $nid) {
  $n = $c->query("select title from {node_field_data} where nid=:n", [':n' => $nid])->fetchField();
  $secs = $c->query("select p.id, p.type from {node__field_page_section} s join {paragraphs_item_field_data} p on p.id=s.field_page_section_target_id and p.revision_id=s.field_page_section_target_revision_id where s.entity_id=:n order by s.delta", [':n' => $nid])->fetchAll();
  $b = $c->query("select length(body_value) from {node__body} where entity_id=:n and bundle='content_page'", [':n' => $nid])->fetchField();
  echo "$nid $n: ", implode(', ', array_map(fn($s) => $s->type, $secs)), "\n";
}
echo "\nreference_content config:\n";
foreach ($c->query("select entity_id, field_d_p_reference_content_target_id t from {paragraph__field_d_p_reference_content}") as $r) echo json_encode($r), " ";
echo "\nform paragraphs: ", json_encode($c->query("select * from {paragraph__field_d_form} limit 3")->fetchAll()), "\n";
echo "contact forms in use: ", json_encode($c->query("select name from {config} where name like 'contact.form.%'")->fetchCol()), "\n";
