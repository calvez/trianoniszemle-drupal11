<?php
// Fix main menu: remove the standard "Címlap" item, set explicit top-level order.
$order = ['Évfolyamok' => 0, 'Hírek események' => 1, 'Repertórium' => 2, 'Szerzők' => 3, 'Rólunk' => 4, 'Támogatóink' => 5];
$tree = \Drupal::menuTree()->load('main', new \Drupal\Core\Menu\MenuTreeParameters());
$mlm = \Drupal::service('plugin.manager.menu.link');
foreach ($tree as $el) {
  $title = (string) $el->link->getTitle();
  if ($title === 'Címlap' || $title === 'Home') { echo "disable $title (", $el->link->getPluginId(), ")\n"; $mlm->updateDefinition($el->link->getPluginId(), ['enabled' => 0]); continue; }
  if (isset($order[$title])) { $mlm->updateDefinition($el->link->getPluginId(), ['weight' => $order[$title]]); echo "$title => ", $order[$title], "\n"; }
}
