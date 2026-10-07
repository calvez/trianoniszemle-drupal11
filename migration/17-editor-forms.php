<?php
/**
 * @file
 * Editor forms: Published checkbox (+ permission), sensible field order for articles/issues,
 * authors can be created on the fly from the article form. Idempotent.
 */
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\Entity\Role;

Role::load('content_editor')->grantPermission('administer nodes')->save();

$order = [
  'cikk' => ['title' => 0, 'field_alcim' => 1, 'field_szam' => 2, 'field_szerzo' => 3, 'field_oldalszam' => 4, 'field_pdf' => 5, 'body' => 6],
  'lapszam' => ['title' => 0, 'field_evfolyam' => 1, 'field_lapszam' => 2, 'field_sorszam' => 3, 'field_kep' => 4, 'body' => 5],
  'szerzo' => ['title' => 0, 'field_tipus' => 1, 'field_kep' => 2, 'body' => 3],
  'blog_post' => ['title' => 0, 'field_kategoria' => 1, 'field_kep' => 2, 'body' => 3, 'field_csatolmany' => 4],
  'page' => ['title' => 0, 'body' => 1, 'field_csatolmany' => 2],
];
foreach ($order as $bundle => $weights) {
  $form = EntityFormDisplay::load("node.$bundle.default");
  foreach ($weights as $name => $w) {
    $c = $form->getComponent($name) ?: [];
    if ($name === 'body') $c = ['type' => 'text_textarea_with_summary', 'settings' => ['rows' => $bundle === 'cikk' ? 8 : 14, 'summary_rows' => 3, 'placeholder' => '', 'show_summary' => FALSE]] + $c;
    if ($name === 'title') $c = ['type' => 'string_textfield', 'settings' => ['size' => 80, 'placeholder' => '']] + $c;
    $c['weight'] = $w;
    $form->setComponent($name, $c);
  }
  $form->setComponent('status', ['type' => 'boolean_checkbox', 'weight' => 30, 'settings' => ['display_label' => TRUE]]);
  $form->setComponent('path', ['type' => 'path', 'weight' => 40, 'settings' => []]);
  foreach (['promote', 'sticky', 'created', 'uid'] as $hide) $form->removeComponent($hide);
  $form->save();
}
// Authors: allow creating a new author from the article form.
$fc = FieldConfig::loadByName('node', 'cikk', 'field_szerzo');
$hs = $fc->getSetting('handler_settings'); $hs['auto_create'] = TRUE; $hs['auto_create_bundle'] = 'szerzo'; $fc->setSetting('handler_settings', $hs)->save();
echo "editor forms updated; field_szerzo auto_create on\n";
