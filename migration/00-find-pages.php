<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
foreach ($c->query("select name, data from {config} where name like 'views.view.%'") as $r) {
  $d = unserialize($r->data);
  foreach ($d['display'] as $id => $disp) {
    if (($disp['display_plugin'] ?? '') === 'page') {
      $o = $disp['display_options']; $def = $d['display']['default']['display_options'];
      echo sprintf("%-22s %-14s /%-28s menu=%s title=%s style=%s items=%s exposed=%s\n", $d['id'], $id, $o['path'] ?? '', json_encode(($o['menu']['type'] ?? '') . ':' . ($o['menu']['title'] ?? '') . ':' . ($o['menu']['menu_name'] ?? '')), $o['title'] ?? $def['title'] ?? '', $o['style']['type'] ?? $def['style']['type'] ?? '', $o['pager']['options']['items_per_page'] ?? $def['pager']['options']['items_per_page'] ?? '', count(($o['filters'] ?? []) ?: ($def['filters'] ?? [])) );
    }
  }
}
