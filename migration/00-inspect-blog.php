<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$sections = "select s.entity_id nid, s.delta, p.id, p.type from {node__field_blog_sections} s join {paragraphs_item_field_data} p on p.id=s.field_blog_sections_target_id and p.revision_id=s.field_blog_sections_target_revision_id";
$fids = $c->query("select distinct i.field_media_image_target_id fid from {media__field_media_image} i where i.entity_id in (
  select field_blog_media_main_image_target_id from {node__field_blog_media_main_image}
  union select m.field_d_media_image_target_id from {paragraph__field_d_media_image} m where m.entity_id in (select id from ($sections) x))")->fetchCol();
$r = $c->query("select count(*) n, round(sum(filesize)/1048576) mb, round(avg(filesize)/1024) avg_kb, round(max(filesize)/1048576,1) max_mb from {file_managed} where fid in (:f[])", [':f[]' => $fids])->fetchObject();
echo "blog images: ", json_encode($r), "\n";
$ex = $c->query("select f.uri from {file_managed} f where f.fid in (:f[]) limit 3", [':f[]' => $fids])->fetchCol(); echo implode("\n", $ex), "\n";
// sample converted text
$p = $c->query("select field_d_long_text_value v, field_d_long_text_format f from {paragraph__field_d_long_text} where bundle='d_p_text_paged' limit 1")->fetchObject(); echo "\ntext_paged sample: ", substr($p->v, 0, 200), " [", $p->f, "]\n";
echo "cta sample: ", json_encode($c->query("select field_d_cta_link_uri u, field_d_cta_link_title t from {paragraph__field_d_cta_link} limit 2")->fetchAll(), JSON_UNESCAPED_UNICODE), "\n";
echo "blog posts w/o sections: ", $c->query("select count(*) from {node_field_data} n where type='blog_post' and nid not in (select entity_id from {node__field_blog_sections})")->fetchField(), "\n";
echo "blog video main: ", $c->query("select count(*) from {node__field_blog_media_main_image} b join {media_field_data} m on m.mid=b.field_blog_media_main_image_target_id where m.bundle<>'d_image'")->fetchField(), "\n";
