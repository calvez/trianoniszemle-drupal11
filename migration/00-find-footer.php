<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
foreach ($c->query("select b.id, b.type, b.info, left(body.body_value, 700) v from {block_content_field_data} b left join {block_content__body} body on body.entity_id=b.id") as $r) echo "#$r->id [$r->type] $r->info: ", preg_replace('/\s+/', ' ', $r->v ?? ''), "\n\n";
echo "block placements: "; foreach ($c->query("select name from {config} where name like 'block.block.%' and data like '%footer%'") as $r) echo $r->name, " "; echo "\n";
