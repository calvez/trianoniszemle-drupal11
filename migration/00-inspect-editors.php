<?php
foreach (\Drupal\filter\Entity\FilterFormat::loadMultiple() as $f) {
  $on = []; foreach ($f->filters() as $id => $flt) if ($flt->status) $on[] = $id;
  echo $f->id(), " (", $f->label(), ") weight=", $f->get('weight'), " filters: ", implode(', ', $on), "\n";
}
foreach (\Drupal\editor\Entity\Editor::loadMultiple() as $e) {
  $s = $e->getSettings();
  echo "\neditor ", $e->id(), " [", $e->getEditor(), "] toolbar: ", implode(' ', $s['toolbar']['items'] ?? []), "\n  plugins: ", json_encode(array_keys($s['plugins'] ?? [])), "\n  image upload: ", json_encode($e->getImageUploadSettings()), "\n";
}
echo "\nroles & content perms:\n";
foreach (\Drupal\user\Entity\Role::loadMultiple() as $r) {
  $p = array_filter($r->getPermissions(), fn($x) => preg_match('/(content|node|file|format|alias|media|taxonomy|search|revision|administer|bypass|access)/', $x));
  echo $r->id(), " (", count($r->getPermissions()), " perms): ", implode(', ', array_slice($r->getPermissions(), 0, 40)), "\n";
}
