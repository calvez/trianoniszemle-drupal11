<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
foreach (['main', 'bottom-footer-menu', 'secondary-menu'] as $m) {
  echo "== $m\n";
  foreach ($c->query("select d.id, d.title, d.link__uri u, d.parent, d.weight, d.enabled from {menu_link_content_data} d where d.menu_name=:m order by d.weight", [':m' => $m]) as $r) echo sprintf("%-4s %-34s %-42s parent=%s w=%s on=%s\n", $r->id, $r->title, $r->u, $r->parent, $r->weight, $r->enabled);
}
