<?php

/**
 * @file
 * One-off: core Views for browsing (issue index, articles per issue, per author)
 * plus block placement. Config is exported to config/sync afterwards.
 *
 * Run: drush php:script migration/03-views.php
 */

use Drupal\block\Entity\Block;
use Drupal\views\Entity\View;

$field = function (string $table, string $name, string $plugin, array $extra = []) {
  return $extra + ['id' => $name, 'table' => $table, 'field' => $name, 'relationship' => 'none', 'plugin_id' => $plugin];
};
$title = ['id' => 'title', 'table' => 'node_field_data', 'field' => 'title', 'entity_type' => 'node', 'entity_field' => 'title', 'plugin_id' => 'field', 'label' => 'Cím',
  'settings' => ['link_to_entity' => TRUE], 'type' => 'string'];

$common = fn(array $types) => [
  'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'plugin_id' => 'boolean', 'value' => '1', 'entity_type' => 'node', 'entity_field' => 'status', 'group' => 1],
  'type' => ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'plugin_id' => 'bundle', 'value' => array_combine($types, $types), 'entity_type' => 'node', 'entity_field' => 'type', 'group' => 1],
];

$base_display = [
  'display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Alapértelmezett', 'position' => 0,
  'display_options' => [
    'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']],
    'cache' => ['type' => 'tag', 'options' => []],
    'exposed_form' => ['type' => 'basic', 'options' => []],
    'pager' => ['type' => 'none', 'options' => ['offset' => 0]],
    'query' => ['type' => 'views_query', 'options' => []],
    'style' => ['type' => 'default'],
    'row' => ['type' => 'fields'],
    'relationships' => [], 'header' => [], 'footer' => [], 'empty' => [],
  ],
];

// --- 1. Articles per issue / per author (blocks) -----------------------------
$articles_fields = [
  'title' => $title,
  'field_szerzo' => [
    'id' => 'field_szerzo', 'table' => 'node__field_szerzo', 'field' => 'field_szerzo', 'plugin_id' => 'field', 'label' => 'Szerző',
    'type' => 'entity_reference_label', 'settings' => ['link' => TRUE], 'delta_limit' => 0, 'group_type' => 'group', 'multi_type' => 'separator', 'separator' => ', ',
  ],
  'field_szam' => [
    'id' => 'field_szam', 'table' => 'node__field_szam', 'field' => 'field_szam', 'plugin_id' => 'field', 'label' => 'Lapszám',
    'type' => 'entity_reference_label', 'settings' => ['link' => TRUE],
  ],
  'field_oldalszam' => [
    'id' => 'field_oldalszam', 'table' => 'node__field_oldalszam', 'field' => 'field_oldalszam', 'plugin_id' => 'field', 'label' => 'Oldal',
    'type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => TRUE],
  ],
  'field_pdf' => [
    'id' => 'field_pdf', 'table' => 'node__field_pdf', 'field' => 'field_pdf', 'plugin_id' => 'field', 'label' => 'PDF',
    'type' => 'file_default', 'settings' => ['use_description_as_link_text' => TRUE],
  ],
];
$arg = function (string $table, string $col) {
  return [$col => ['id' => $col, 'table' => $table, 'field' => $col, 'plugin_id' => 'numeric', 'default_action' => 'default', 'default_argument_type' => 'node',
    'default_argument_options' => [], 'summary_options' => ['base_path' => '', 'count' => TRUE, 'override' => FALSE, 'items_per_page' => 25], 'specify_validation' => FALSE, 'break_phrase' => FALSE, 'not' => FALSE]];
};

if (!View::load('cikkek')) {
  $d = $base_display;
  $d['display_options'] += [
    'title' => 'Cikkek', 'fields' => $articles_fields, 'filters' => $common(['cikk']),
    'sorts' => ['field_oldalszam_value' => ['id' => 'field_oldalszam_value', 'table' => 'node__field_oldalszam', 'field' => 'field_oldalszam_value', 'plugin_id' => 'standard', 'order' => 'ASC']],
  ];
  $d['display_options']['style'] = ['type' => 'table', 'options' => ['columns' => ['title' => 'title', 'field_szerzo' => 'field_szerzo', 'field_szam' => 'field_szam', 'field_oldalszam' => 'field_oldalszam', 'field_pdf' => 'field_pdf'],
    'default' => '-1', 'info' => [], 'override' => TRUE, 'sticky' => FALSE, 'empty_table' => FALSE]];

  $issue = ['display_plugin' => 'block', 'id' => 'block_issue', 'display_title' => 'Cikkek a lapszámban', 'position' => 1, 'display_options' => [
    'display_description' => '', 'block_description' => 'Cikkek a lapszámban', 'arguments' => $arg('node__field_szam', 'field_szam_target_id'),
    'defaults' => ['arguments' => FALSE, 'fields' => FALSE, 'title' => FALSE, 'style' => FALSE, 'row' => FALSE, 'sorts' => FALSE], 'title' => 'Cikkek',
    'fields' => array_diff_key($articles_fields, ['field_szam' => 1]), 'sorts' => $d['display_options']['sorts'],
    'style' => ['type' => 'table', 'options' => ['columns' => ['title' => 'title', 'field_szerzo' => 'field_szerzo', 'field_oldalszam' => 'field_oldalszam', 'field_pdf' => 'field_pdf'], 'default' => '-1', 'info' => [], 'override' => TRUE]],
    'row' => ['type' => 'fields', 'options' => []],
  ]];
  $author = ['display_plugin' => 'block', 'id' => 'block_author', 'display_title' => 'A szerző cikkei', 'position' => 2, 'display_options' => [
    'display_description' => '', 'block_description' => 'A szerző cikkei', 'arguments' => $arg('node__field_szerzo', 'field_szerzo_target_id'),
    'defaults' => ['arguments' => FALSE, 'fields' => FALSE, 'title' => FALSE, 'style' => FALSE, 'row' => FALSE, 'sorts' => FALSE], 'title' => 'Cikkek',
    'fields' => array_diff_key($articles_fields, ['field_szerzo' => 1]),
    'sorts' => ['created' => ['id' => 'created', 'table' => 'node_field_data', 'field' => 'created', 'plugin_id' => 'date', 'order' => 'DESC', 'entity_type' => 'node', 'entity_field' => 'created']],
    'style' => ['type' => 'table', 'options' => ['columns' => ['title' => 'title', 'field_szam' => 'field_szam', 'field_oldalszam' => 'field_oldalszam', 'field_pdf' => 'field_pdf'], 'default' => '-1', 'info' => [], 'override' => TRUE]],
    'row' => ['type' => 'fields', 'options' => []],
  ]];
  View::create(['id' => 'cikkek', 'label' => 'Cikkek', 'module' => 'views', 'base_table' => 'node_field_data', 'base_field' => 'nid', 'langcode' => 'hu', 'status' => TRUE,
    'display' => ['default' => $d, 'block_issue' => $issue, 'block_author' => $author]])->save();
  echo "view cikkek created\n";
}

// --- 2. Issue index page ------------------------------------------------------
if (!View::load('lapszamok')) {
  $d = $base_display;
  $d['display_options'] += [
    'title' => 'Évfolyamok és lapszámok',
    'fields' => [
      'field_kep' => ['id' => 'field_kep', 'table' => 'node__field_kep', 'field' => 'field_kep', 'plugin_id' => 'field', 'label' => '', 'type' => 'image', 'settings' => ['image_style' => 'medium', 'image_link' => 'content'], 'element_label_colon' => FALSE],
      'title' => $title + ['label' => ''],
      'field_evfolyam' => ['id' => 'field_evfolyam', 'table' => 'node__field_evfolyam', 'field' => 'field_evfolyam', 'plugin_id' => 'field', 'label' => 'Évfolyam', 'type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => TRUE]],
    ],
    'filters' => $common(['lapszam']),
    'sorts' => ['field_sorszam_value' => ['id' => 'field_sorszam_value', 'table' => 'node__field_sorszam', 'field' => 'field_sorszam_value', 'plugin_id' => 'standard', 'order' => 'DESC']],
  ];
  $d['display_options']['style'] = ['type' => 'html_list', 'options' => ['type' => 'ul', 'wrapper_class' => 'item-list', 'class' => 'lapszamok']];
  $page = ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Oldal', 'position' => 1, 'display_options' => [
    'path' => 'evfolyamok-lista', 'menu' => ['type' => 'none'],
  ]];
  View::create(['id' => 'lapszamok', 'label' => 'Lapszámok', 'module' => 'views', 'base_table' => 'node_field_data', 'base_field' => 'nid', 'langcode' => 'hu', 'status' => TRUE,
    'display' => ['default' => $d, 'page_1' => $page]])->save();
  echo "view lapszamok created\n";
}

// --- 3. Block placement on the Olivero theme ---------------------------------
foreach (['block_issue' => 'lapszam', 'block_author' => 'szerzo'] as $display => $bundle) {
  $id = "olivero_cikkek_$display";
  if (!Block::load($id)) {
    Block::create(['id' => $id, 'theme' => 'olivero', 'region' => 'content', 'weight' => 10, 'plugin' => "views_block:cikkek-$display",
      'settings' => ['id' => "views_block:cikkek-$display", 'label' => 'Cikkek', 'label_display' => 'visible', 'provider' => 'views', 'views_label' => '', 'items_per_page' => 'none'],
      'visibility' => ['entity_bundle:node' => ['id' => 'entity_bundle:node', 'negate' => FALSE, 'context_mapping' => ['node' => '@node.node_route_context:node'], 'bundles' => [$bundle => $bundle]]],
    ])->save();
    echo "block $id placed\n";
  }
}
echo "done\n";
