<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$p = function ($t, $q) use ($c) { echo "-- $t\n"; foreach ($c->query($q) as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n"; };
$p('file schemes', "select substring_index(uri,'://',1) s, count(*) n, round(sum(filesize)/1048576) mb from {file_managed} group by s");
$p('pdf sample', "select f.fid,f.uri,f.filesize from {node__field_pdf} p join {file_managed} f on f.fid=p.field_pdf_target_id limit 3");
$p('media image sample', "select m.mid, i.field_media_image_target_id fid, f.uri, i.field_media_image_alt alt from {media_field_data} m join {media__field_media_image} i on i.entity_id=m.mid join {file_managed} f on f.fid=i.field_media_image_target_id limit 3");
$p('images used by szerzo/lapszam/blog', "select count(distinct f.fid) n, round(sum(f.filesize)/1048576) mb from {file_managed} f join {media__field_media_image} i on i.field_media_image_target_id=f.fid where i.entity_id in (select field_teaser_media_image_target_id from {node__field_teaser_media_image} where bundle in ('tsz_szerzo','tsz_lapszam') union select field_blog_media_main_image_target_id from {node__field_blog_media_main_image})");
$p('redirect sample', "select rid,redirect_source__path,redirect_source__query,redirect_redirect__uri,status_code,language from {redirect} limit 3");
$p('alias langs', "select langcode,status,count(*) n from {path_alias} group by 1,2");
$p('body formats', "select bundle, body_format, count(*) n from {node__body} group by 1,2");
$p('embeds in bodies', "select sum(body_value like '%drupal-media%') media, sum(body_value like '%data-entity-uuid%') uuid, sum(body_value like '%<img%') img, sum(body_value like '%/sites/default/files%') filelinks from {node__body}");
$p('cikk with body', "select entity_id, left(body_value,80) b from {node__body} where bundle='trianoni_szemle' limit 3");
