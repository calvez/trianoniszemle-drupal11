<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$r = $c->query("select p.id from {node__field_page_section} s join {paragraphs_item_field_data} p on p.id=s.field_page_section_target_id and p.revision_id=s.field_page_section_target_revision_id where s.entity_id=5 and p.type='d_p_side_image'")->fetchField();
foreach (['field_d_main_title' => 'field_d_main_title_value', 'field_d_subtitle' => 'field_d_subtitle_value', 'field_d_long_text' => 'field_d_long_text_value'] as $t => $col) echo "$t: ", substr((string) $c->query("select $col from {paragraph__$t} where entity_id=:p", [':p' => $r])->fetchField(), 0, 400), "\n";
echo "ref content nodes 2833,2835,2836,2950: ", json_encode($c->query("select nid,type,title from {node_field_data} where nid in (2833,2835,2836,2950)")->fetchAll(), JSON_UNESCAPED_UNICODE), "\n";
echo "olivero regions: ", implode(', ', array_keys(\Drupal::service('theme_handler')->getTheme('olivero')->info['regions'])), "\n";
echo "blocks: "; foreach (\Drupal::entityTypeManager()->getStorage('block')->loadByProperties(['theme' => 'olivero']) as $b) echo $b->id(), "@", $b->getRegion(), " "; echo "\n";
