<?php

/**
 * @file
 * Editor experience + content hygiene:
 *  - content_editor role gets real permissions for the five content types
 *  - full_html becomes the default format for editors
 *  - upload limits (images auto-scaled to 1600px, 10 MB max) for inline images and image fields
 *  - attachments field (documents) on page and blog_post, shown as a download list
 *  - strip empty <p>&nbsp;</p> filler paragraphs from all imported bodies
 * Idempotent. Run: drush php:script migration/14-editor-setup.php
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\editor\Entity\Editor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\user\Entity\Role;

// 1. Role permissions ----------------------------------------------------------------
$role = Role::load('content_editor');
$perms = ['use text format full_html', 'access content overview', 'view own unpublished content', 'create url aliases', 'administer url aliases', 'access files overview'];
foreach (['cikk', 'lapszam', 'szerzo', 'blog_post', 'page'] as $t) {
  foreach (['create %s content', 'edit own %s content', 'edit any %s content', 'delete own %s content', 'delete any %s content'] as $p) $perms[] = sprintf($p, $t);
}
foreach ($perms as $p) $role->grantPermission($p);
$role->save();
echo "content_editor: ", count($role->getPermissions()), " permissions\n";

// 2. Text format order: full_html first so editors get it by default ----------------------
FilterFormat::load('full_html')->set('weight', -10)->save();
FilterFormat::load('basic_html')->set('weight', 0)->save();

// 3. Upload limits -----------------------------------------------------------------------
foreach (['basic_html', 'full_html'] as $f) {
  $e = Editor::load($f);
  $e->setImageUploadSettings(['status' => TRUE, 'scheme' => 'public', 'directory' => 'inline-images', 'max_size' => '10 MB', 'max_dimensions' => ['width' => 1600, 'height' => 1600]])->save();
}
foreach (['szerzo' => '1200x1200', 'lapszam' => '1400x1400', 'blog_post' => '2000x2000'] as $bundle => $res) {
  if ($fc = FieldConfig::loadByName('node', $bundle, 'field_kep')) { $fc->setSetting('max_resolution', $res)->setSetting('max_filesize', '10 MB')->save(); }
}
echo "upload limits set\n";

// 4. Attachments field (documents) -----------------------------------------------------------
if (!FieldStorageConfig::loadByName('node', 'field_csatolmany')) {
  FieldStorageConfig::create(['field_name' => 'field_csatolmany', 'entity_type' => 'node', 'type' => 'file', 'cardinality' => -1, 'settings' => ['uri_scheme' => 'public', 'display_field' => FALSE, 'display_default' => TRUE]])->save();
}
foreach (['page', 'blog_post'] as $bundle) {
  if (!FieldConfig::loadByName('node', $bundle, 'field_csatolmany')) {
    FieldConfig::create(['field_name' => 'field_csatolmany', 'entity_type' => 'node', 'bundle' => $bundle, 'label' => 'Letölthető dokumentumok',
      'description' => 'PDF, Word, Excel stb. fájlok, amelyek az oldal alján letölthető listaként jelennek meg.',
      'settings' => ['file_directory' => 'dokumentumok/[date:custom:Y]', 'file_extensions' => 'pdf doc docx xls xlsx ppt pptx odt ods txt zip', 'max_filesize' => '25 MB', 'description_field' => TRUE]])->save();
  }
  $form = EntityFormDisplay::load("node.$bundle.default"); $form->setComponent('field_csatolmany', ['type' => 'file_generic', 'weight' => 20, 'settings' => ['progress_indicator' => 'throbber']])->save();
  $view = EntityViewDisplay::load("node.$bundle.default"); $view->setComponent('field_csatolmany', ['type' => 'file_default', 'label' => 'above', 'weight' => 20, 'settings' => ['use_description_as_link_text' => TRUE]])->save();
}
echo "attachments field ready\n";

// 5. Strip empty filler paragraphs ----------------------------------------------------------------
$s = \Drupal::entityTypeManager()->getStorage('node');
$ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->exists('body')->execute();
$changed = 0; $removed = 0;
$clean = function (string $html) use (&$removed): string {
  return preg_replace_callback('#<p\b[^>]*>(?:\s|&nbsp;|&\#160;|\x{00A0}|<br\s*/?>)*</p>\s*#iu', function ($m) use (&$removed) { $removed++; return ''; }, $html);
};
$db = \Drupal::database();
foreach (array_chunk($ids, 150) as $chunk) {
  foreach ($s->loadMultiple($chunk) as $n) {
    $v = (string) $n->body->value; $sum = (string) $n->body->summary;
    $nv = $clean($v); $ns = $clean($sum);
    if ($nv !== $v || $ns !== $sum) {
      $old = $n->getChangedTime();
      $n->set('body', ['value' => $nv, 'summary' => $ns, 'format' => $n->body->format]); $n->save();
      foreach (['node_field_data', 'node_field_revision'] as $t) $db->update($t)->fields(['changed' => $old])->condition('nid', $n->id())->execute();
      $changed++;
    }
  }
  $s->resetCache();
}
echo "empty paragraphs removed: $removed in $changed nodes\n";
