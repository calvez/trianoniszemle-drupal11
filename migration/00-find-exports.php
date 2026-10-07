<?php
$c = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
foreach ($c->query("select name, data from {config} where name like 'views.view.%'") as $r) {
  $d = unserialize($r->data);
  foreach ($d['display'] as $id => $disp) {
    if (($disp['display_plugin'] ?? '') === 'rest_export') {
      echo $d['id'], ".", $id, " path=", $disp['display_options']['path'] ?? '', " format=", $disp['display_options']['style']['options']['formats'][0] ?? json_encode($disp['display_options']['style']['options'] ?? ''), " auth=", json_encode($disp['display_options']['auth'] ?? []), " access=", json_encode($disp['display_options']['access'] ?? $d['display']['default']['display_options']['access'] ?? ''), "\n";
    }
  }
}
