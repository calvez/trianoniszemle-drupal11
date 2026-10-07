<?php

/**
 * @file
 * One-off: create the Trianoni Szemle content model (core features only).
 *
 * Run: drush php:script migration/01-content-model.php
 * Idempotent. The resulting config is exported to config/sync; after that
 * this script is documentation only.
 */

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;

if (!FieldStorageConfig::loadByName('node', 'body')) {
  FieldStorageConfig::create(['field_name' => 'body', 'entity_type' => 'node', 'type' => 'text_with_summary', 'cardinality' => 1])->save();
  echo "body storage created\n";
}

function tz_type(string $id, string $label, string $title_label, string $desc, bool $body = TRUE): void {
  if (!NodeType::load($id)) {
    NodeType::create([
      'type' => $id, 'name' => $label, 'description' => $desc, 'title_label' => $title_label,
      'new_revision' => FALSE, 'display_submitted' => FALSE, 'preview_mode' => 0,
    ])->save();
    echo "type $id created\n";
  }
  if ($body) {
    node_add_body_field(NodeType::load($id), 'Szöveg');
  }
}

function tz_field(string $bundle, string $name, string $type, string $label, array $storage = [], array $config = [], string $widget = NULL, array $widget_settings = [], string $formatter = NULL, array $formatter_settings = [], int $cardinality = 1, bool $required = FALSE): void {
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name, 'entity_type' => 'node', 'type' => $type,
      'cardinality' => $cardinality,
    ] + ['settings' => $storage])->save();
  }
  if (!FieldConfig::loadByName('node', $bundle, $name)) {
    FieldConfig::create([
      'field_name' => $name, 'entity_type' => 'node', 'bundle' => $bundle,
      'label' => $label, 'required' => $required,
    ] + $config)->save();
    echo "field $bundle.$name created\n";
  }
  $form = EntityFormDisplay::load("node.$bundle.default") ?: EntityFormDisplay::create(['targetEntityType' => 'node', 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE]);
  if ($widget) {
    $form->setComponent($name, ['type' => $widget, 'settings' => $widget_settings])->save();
  }
  $view = EntityViewDisplay::load("node.$bundle.default") ?: EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => $bundle, 'mode' => 'default', 'status' => TRUE]);
  if ($formatter) {
    $view->setComponent($name, ['type' => $formatter, 'label' => 'above', 'settings' => $formatter_settings])->save();
  }
}

$ref = fn(string $target_bundle) => ['settings' => ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => [$target_bundle => $target_bundle], 'sort' => ['field' => '_none']]]];
$img = ['settings' => ['file_directory' => 'kepek/[date:custom:Y]', 'file_extensions' => 'png gif jpg jpeg webp', 'max_filesize' => '10 MB', 'alt_field' => TRUE, 'alt_field_required' => FALSE, 'title_field' => FALSE]];

// --- Szerző -----------------------------------------------------------------
tz_type('szerzo', 'Szerző', 'Név', 'A Trianoni Szemle szerzői és kapcsolódó személyek.');
tz_field('szerzo', 'field_tipus', 'list_string', 'Típus',
  ['allowed_values' => ['Szerző' => 'Szerző', 'Kapcsolódó személy' => 'Kapcsolódó személy']], [],
  'options_select', [], 'list_default');
tz_field('szerzo', 'field_kep', 'image', 'Fénykép', ['target_type' => 'file', 'uri_scheme' => 'public'], $img,
  'image_image', ['preview_image_style' => 'thumbnail'], 'image', ['image_style' => 'medium', 'image_link' => '']);

// --- Lapszám ----------------------------------------------------------------
tz_type('lapszam', 'Lapszám', 'Cím', 'A Trianoni Szemle egy lapszáma / kiadványa.');
tz_field('lapszam', 'field_evfolyam', 'integer', 'Évfolyam', [], [], 'number', [], 'number_integer');
tz_field('lapszam', 'field_lapszam', 'string', 'Lapszám jelölése', ['max_length' => 48], [], 'string_textfield', [], 'string');
tz_field('lapszam', 'field_sorszam', 'integer', 'Sorszám (rendezéshez)', [], [], 'number', [], 'number_integer');
tz_field('lapszam', 'field_kep', 'image', 'Címlap', ['target_type' => 'file', 'uri_scheme' => 'public'], $img,
  'image_image', ['preview_image_style' => 'thumbnail'], 'image', ['image_style' => 'medium', 'image_link' => '']);

// --- Cikk -------------------------------------------------------------------
tz_type('cikk', 'Cikk', 'Cím', 'Egy Trianoni Szemle cikk: szerző, lapszám, oldalszám, opcionális PDF.');
tz_field('cikk', 'field_alcim', 'string', 'Alcím', ['max_length' => 255], [], 'string_textfield', [], 'string');
tz_field('cikk', 'field_szam', 'entity_reference', 'Lapszám', ['target_type' => 'node'], $ref('lapszam') + [],
  'entity_reference_autocomplete', ['match_operator' => 'CONTAINS', 'size' => 60, 'placeholder' => ''], 'entity_reference_label', ['link' => TRUE], 1, TRUE);
tz_field('cikk', 'field_szerzo', 'entity_reference', 'Szerző(k)', ['target_type' => 'node'], $ref('szerzo'),
  'entity_reference_autocomplete', ['match_operator' => 'CONTAINS', 'size' => 60, 'placeholder' => ''], 'entity_reference_label', ['link' => TRUE], -1);
tz_field('cikk', 'field_oldalszam', 'integer', 'Oldalszám', [], [], 'number', [], 'number_integer');
tz_field('cikk', 'field_pdf', 'file', 'PDF', ['target_type' => 'file', 'display_field' => FALSE, 'display_default' => FALSE, 'uri_scheme' => 'private'],
  ['settings' => ['file_directory' => 'pdf/[date:custom:Y]', 'file_extensions' => 'pdf', 'max_filesize' => '50 MB', 'description_field' => FALSE]],
  'file_generic', [], 'file_default');

// --- Blog -------------------------------------------------------------------
if (!Vocabulary::load('blog_kategoria')) {
  Vocabulary::create(['vid' => 'blog_kategoria', 'name' => 'Blog kategória'])->save();
}
tz_type('blog_post', 'Blogbejegyzés', 'Cím', 'Hírek, események, bejelentések.');
tz_field('blog_post', 'field_kep', 'image', 'Főkép', ['target_type' => 'file', 'uri_scheme' => 'public'], $img,
  'image_image', ['preview_image_style' => 'thumbnail'], 'image', ['image_style' => 'large', 'image_link' => '']);
tz_field('blog_post', 'field_kategoria', 'entity_reference', 'Kategória', ['target_type' => 'taxonomy_term'],
  ['settings' => ['handler' => 'default:taxonomy_term', 'handler_settings' => ['target_bundles' => ['blog_kategoria' => 'blog_kategoria'], 'auto_create' => TRUE]]],
  'options_select', [], 'entity_reference_label', ['link' => TRUE]);

// --- Oldal (static pages) -----------------------------------------------------
tz_type('page', 'Oldal', 'Cím', 'Statikus oldalak (Rólunk, Kapcsolat, Szabályzatok stb.).');

// --- Remove the standard profile's demo types --------------------------------
foreach (['article'] as $t) {
  if ($type = NodeType::load($t)) {
    $count = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', $t)->count()->execute();
    if (!$count) { $type->delete(); echo "type $t removed\n"; }
  }
}
if ($v = Vocabulary::load('tags')) {
  $v->delete();
  echo "vocabulary tags removed\n";
}

// Body of the full-text types: full_html by default is applied at import time.
echo "done\n";
