<?php

/**
 * @file
 * Design pass on the views: contents lists, issue index grouped by year, and the
 * two pages the live site has that were missing: Repertórium and Szerzők.
 * Idempotent (rewrites config of these views). Run: drush php:script migration/08-views-design.php
 */

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\views\Entity\View;

$base_display = function (string $title, array $extra = []) {
  return ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Alapértelmezett', 'position' => 0, 'display_options' => $extra + [
    'title' => $title,
    'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']], 'cache' => ['type' => 'tag', 'options' => []],
    'exposed_form' => ['type' => 'basic', 'options' => ['submit_button' => 'Keresés', 'reset_button' => FALSE]],
    'query' => ['type' => 'views_query', 'options' => []],
    'relationships' => [], 'header' => [], 'footer' => [], 'empty' => [], 'arguments' => [],
  ]];
};
$f = function (string $field, string $plugin, array $o = []) {
  $table = $o['table'] ?? "node__$field";
  unset($o['table']);
  return [$field => $o + ['id' => $field, 'table' => $table, 'field' => $field, 'plugin_id' => $plugin, 'relationship' => 'none', 'label' => '', 'element_label_colon' => FALSE]];
};
$title = fn(array $o = []) => ['title' => $o + ['id' => 'title', 'table' => 'node_field_data', 'field' => 'title', 'entity_type' => 'node', 'entity_field' => 'title', 'plugin_id' => 'field', 'label' => '', 'element_label_colon' => FALSE,
  'type' => 'string', 'settings' => ['link_to_entity' => TRUE]]];
$status_type = fn(array $types) => [
  'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'plugin_id' => 'boolean', 'value' => '1', 'entity_type' => 'node', 'entity_field' => 'status', 'group' => 1],
  'type' => ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'plugin_id' => 'bundle', 'value' => array_combine($types, $types), 'entity_type' => 'node', 'entity_field' => 'type', 'group' => 1],
];
$refs = [
  'szerzo' => $f('field_szerzo', 'field', ['type' => 'entity_reference_label', 'settings' => ['link' => TRUE], 'delta_limit' => 0, 'group_type' => 'group', 'multi_type' => 'separator', 'separator' => ', ']),
  'szam' => $f('field_szam', 'field', ['type' => 'entity_reference_label', 'settings' => ['link' => TRUE]]),
  'oldal' => $f('field_oldalszam', 'field', ['type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => FALSE]]),
  'pdf' => $f('field_pdf', 'field', ['type' => 'file_url_plain', 'settings' => []]),
];

// --- 1. cikkek: contents lists (issue + author blocks) ---------------------------------
$v = View::load('cikkek');
$d = $v->get('display');
$fields = $title() + $refs['szerzo'] + $refs['szam'] + $refs['oldal'] + $refs['pdf'];
$d['default']['display_options']['fields'] = $fields;
$d['default']['display_options']['style'] = ['type' => 'html_list', 'options' => ['type' => 'ul', 'class' => 'toc-list', 'wrapper_class' => 'toc__wrap']];
$d['default']['display_options']['row'] = ['type' => 'fields', 'options' => []];
foreach (['block_issue' => ['field_szam'], 'block_author' => ['field_szerzo']] as $disp => $drop) {
  $o = &$d[$disp]['display_options'];
  $o['fields'] = array_diff_key($fields, array_flip($drop));
  $o['defaults']['style'] = TRUE; $o['defaults']['row'] = TRUE;
  unset($o['style'], $o['row']);
  $o['header'] = []; $o['defaults']['header'] = TRUE;
  unset($o);
}
$d['block_issue']['display_options']['title'] = 'Tartalom';
$d['block_author']['display_options']['title'] = 'A Trianoni Szemlében megjelent írásai';
// Titles are rendered by the node templates; hide the view's own title in blocks.
$d['block_issue']['display_options']['defaults']['title'] = FALSE; $d['block_author']['display_options']['defaults']['title'] = FALSE;
$d['block_issue']['display_options']['title'] = ''; $d['block_author']['display_options']['title'] = '';
$v->set('display', $d)->save();
foreach (['olivero_cikkek_block_issue', 'olivero_cikkek_block_author'] as $b) { \Drupal\block\Entity\Block::load($b)?->delete(); }
echo "cikkek updated\n";

// --- 2. lapszamok: issue index grouped by year, cards --------------------------------
$v = View::load('lapszamok');
$d = $v->get('display');
$img = ['field_kep' => ['id' => 'field_kep', 'table' => 'node__field_kep', 'field' => 'field_kep', 'plugin_id' => 'field', 'label' => '', 'element_label_colon' => FALSE, 'type' => 'image', 'settings' => ['image_style' => 'medium', 'image_link' => 'content']]];
$evf = $f('field_evfolyam', 'field', ['type' => 'number_integer', 'settings' => ['thousand_separator' => '', 'prefix_suffix' => FALSE], 'exclude' => TRUE]);
$d['default']['display_options']['fields'] = $img + $title() + $evf;
$d['default']['display_options']['title'] = 'Évfolyamok és lapszámok';
$d['default']['display_options']['sorts'] = [
  'field_evfolyam_value' => ['id' => 'field_evfolyam_value', 'table' => 'node__field_evfolyam', 'field' => 'field_evfolyam_value', 'plugin_id' => 'standard', 'order' => 'DESC'],
  'field_sorszam_value' => ['id' => 'field_sorszam_value', 'table' => 'node__field_sorszam', 'field' => 'field_sorszam_value', 'plugin_id' => 'standard', 'order' => 'DESC'],
];
$d['default']['display_options']['pager'] = ['type' => 'none', 'options' => ['offset' => 0]];
$d['default']['display_options']['style'] = ['type' => 'html_list', 'options' => ['type' => 'ul', 'class' => 'cards', 'grouping' => [['field' => 'field_evfolyam', 'rendered' => TRUE, 'rendered_strip' => FALSE]]]];
$d['default']['display_options']['row'] = ['type' => 'fields', 'options' => []];
$d['block_latest']['display_options']['defaults'] = ['pager' => FALSE, 'title' => FALSE, 'style' => FALSE, 'row' => FALSE];
$d['block_latest']['display_options']['style'] = ['type' => 'html_list', 'options' => ['type' => 'ul', 'class' => 'cards']];
$d['block_latest']['display_options']['row'] = ['type' => 'fields', 'options' => []];
$d['block_latest']['display_options']['title'] = 'Legfrissebb lapszámok';
$v->set('display', $d)->save();
echo "lapszamok updated\n";

// --- 3. repertorium -------------------------------------------------------------------------
if ($old = View::load('repertorium')) { $old->delete(); }
$rel_issue = ['field_szam' => ['id' => 'field_szam', 'table' => 'node__field_szam', 'field' => 'field_szam', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => 'Lapszám', 'entity_type' => 'node', 'plugin_id' => 'standard', 'required' => FALSE]];
$rel_author = ['field_szerzo' => ['id' => 'field_szerzo', 'table' => 'node__field_szerzo', 'field' => 'field_szerzo', 'relationship' => 'none', 'group_type' => 'group', 'admin_label' => 'Szerző', 'entity_type' => 'node', 'plugin_id' => 'standard', 'required' => FALSE]];
$szam_text = ['field_szam' => ['id' => 'field_szam', 'table' => 'node__field_szam', 'field' => 'field_szam', 'plugin_id' => 'field', 'relationship' => 'none', 'label' => 'Lapszám', 'type' => 'entity_reference_label', 'settings' => ['link' => TRUE]]];
$rep_fields = $szam_text + [
  'field_szerzo' => ['label' => 'Szerző'] + $refs['szerzo']['field_szerzo'],
] + $title(['label' => 'Cím']) + [
  'field_alcim' => ['id' => 'field_alcim', 'table' => 'node__field_alcim', 'field' => 'field_alcim', 'plugin_id' => 'field', 'relationship' => 'none', 'label' => 'Alcím', 'type' => 'string', 'settings' => ['link_to_entity' => FALSE]],
  'field_oldalszam' => ['label' => 'Oldalszám'] + $refs['oldal']['field_oldalszam'],
  'field_pdf' => ['label' => 'PDF'] + $refs['pdf']['field_pdf'] + [ 'alter' => ['alter_text' => TRUE, 'text' => '<a href="{{ field_pdf }}">PDF</a>'], 'empty' => '-', 'hide_empty' => FALSE],
];
$rep_fields['field_alcim']['element_label_colon'] = FALSE;
$dd = $base_display('Repertórium', [
  'fields' => $rep_fields,
  'filters' => $status_type(['cikk']) + [
    'combine' => ['id' => 'combine', 'table' => 'views', 'field' => 'combine', 'plugin_id' => 'combine', 'operator' => 'contains', 'value' => '', 'group' => 1,
      'fields' => ['title' => 'title', 'field_alcim' => 'field_alcim'],
      'exposed' => TRUE, 'expose' => ['operator_id' => 'combine_op', 'label' => 'Cím vagy alcím', 'identifier' => 'szoveg', 'remember' => FALSE, 'required' => FALSE]],
    'szerzo_nev' => ['id' => 'szerzo_nev', 'table' => 'node_field_data', 'field' => 'title', 'relationship' => 'field_szerzo', 'plugin_id' => 'string', 'entity_type' => 'node', 'entity_field' => 'title', 'operator' => 'contains', 'value' => '', 'group' => 1,
      'exposed' => TRUE, 'expose' => ['operator_id' => 'szerzo_op', 'label' => 'Szerző', 'identifier' => 'szerzo', 'remember' => FALSE, 'required' => FALSE]],
  ],
  'relationships' => $rel_issue + $rel_author,
  'sorts' => [
    'field_sorszam_value' => ['id' => 'field_sorszam_value', 'table' => 'node__field_sorszam', 'field' => 'field_sorszam_value', 'relationship' => 'field_szam', 'plugin_id' => 'standard', 'order' => 'DESC'],
    'field_oldalszam_value' => ['id' => 'field_oldalszam_value', 'table' => 'node__field_oldalszam', 'field' => 'field_oldalszam_value', 'relationship' => 'none', 'plugin_id' => 'standard', 'order' => 'ASC'],
  ],
  'pager' => ['type' => 'full', 'options' => ['items_per_page' => 50, 'offset' => 0, 'tags' => ['previous' => '‹ Előző', 'next' => 'Következő ›', 'first' => '« Első', 'last' => 'Utolsó »'], 'quantity' => 7]],
  'style' => ['type' => 'table', 'options' => ['columns' => ['field_szam' => 'field_szam', 'field_szerzo' => 'field_szerzo', 'title' => 'title', 'field_alcim' => 'field_alcim', 'field_oldalszam' => 'field_oldalszam', 'field_pdf' => 'field_pdf'], 'default' => '-1', 'info' => [], 'sticky' => FALSE, 'empty_table' => FALSE]],
  'row' => ['type' => 'fields', 'options' => []],
  'empty' => ['area_text_custom' => ['id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'plugin_id' => 'text_custom', 'empty' => TRUE, 'content' => '<p>Nincs a keresésnek megfelelő cikk.</p>']],
]);
$dd['display_options']['query']['options']['distinct'] = TRUE;
View::create(['id' => 'repertorium', 'label' => 'Repertórium', 'module' => 'views', 'base_table' => 'node_field_data', 'base_field' => 'nid', 'langcode' => 'hu', 'status' => TRUE, 'display' => [
  'default' => $dd,
  'page_1' => ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Oldal', 'position' => 1, 'display_options' => ['path' => 'repertorium', 'menu' => ['type' => 'normal', 'title' => 'Repertórium', 'menu_name' => 'main', 'weight' => 2]]],
]])->save();
echo "repertorium created\n";

// --- 4. szerzok ----------------------------------------------------------------------------------
if ($old = View::load('szerzok')) { $old->delete(); }
$dd = $base_display('Szerzők', [
  'fields' => [
    'field_kep' => ['id' => 'field_kep', 'table' => 'node__field_kep', 'field' => 'field_kep', 'plugin_id' => 'field', 'relationship' => 'none', 'label' => '', 'element_label_colon' => FALSE, 'type' => 'image', 'settings' => ['image_style' => 'medium', 'image_link' => 'content']],
  ] + $title() + [
    'body' => ['id' => 'body', 'table' => 'node__body', 'field' => 'body', 'plugin_id' => 'field', 'relationship' => 'none', 'label' => '', 'element_label_colon' => FALSE, 'type' => 'text_trimmed', 'settings' => ['trim_length' => 110]],
  ],
  'filters' => $status_type(['szerzo']) + [
    'betu' => ['id' => 'betu', 'table' => 'node_field_data', 'field' => 'title', 'plugin_id' => 'string', 'entity_type' => 'node', 'entity_field' => 'title', 'operator' => 'starts', 'value' => '', 'group' => 1,
      'exposed' => TRUE, 'expose' => ['operator_id' => '', 'label' => 'Kezdőbetű', 'identifier' => 'betu', 'remember' => FALSE, 'required' => FALSE]],
  ],
  'sorts' => ['title' => ['id' => 'title', 'table' => 'node_field_data', 'field' => 'title', 'plugin_id' => 'standard', 'order' => 'ASC', 'entity_type' => 'node', 'entity_field' => 'title']],
  'pager' => ['type' => 'full', 'options' => ['items_per_page' => 48, 'offset' => 0, 'tags' => ['previous' => '‹ Előző', 'next' => 'Következő ›', 'first' => '« Első', 'last' => 'Utolsó »'], 'quantity' => 7]],
  'style' => ['type' => 'html_list', 'options' => ['type' => 'ul', 'class' => 'cards']],
  'row' => ['type' => 'fields', 'options' => []],
]);
View::create(['id' => 'szerzok', 'label' => 'Szerzők', 'module' => 'views', 'base_table' => 'node_field_data', 'base_field' => 'nid', 'langcode' => 'hu', 'status' => TRUE, 'display' => [
  'default' => $dd,
  'page_1' => ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Oldal', 'position' => 1, 'display_options' => ['path' => 'szerzok', 'menu' => ['type' => 'normal', 'title' => 'Szerzők', 'menu_name' => 'main', 'weight' => 3]]],
]])->save();
echo "szerzok created\n";

// --- 5. Display settings of the node types (no field labels; hide helper fields) ------------------------
$hide = ['field_evfolyam', 'field_lapszam', 'field_sorszam', 'field_tipus', 'field_szerzo', 'field_szam', 'field_oldalszam', 'field_pdf', 'field_alcim', 'field_kategoria'];
foreach (['lapszam', 'szerzo', 'cikk', 'blog_post', 'page'] as $bundle) {
  foreach (['default', 'teaser'] as $mode) {
    if ($disp = EntityViewDisplay::load("node.$bundle.$mode")) {
      foreach ($hide as $h) { $disp->removeComponent($h); }
      foreach (['body', 'field_kep'] as $c) { if ($comp = $disp->getComponent($c)) { $comp['label'] = 'hidden'; $disp->setComponent($c, $comp); } }
      if ($mode === 'default' && $bundle === 'lapszam') { $disp->setComponent('field_kep', ['type' => 'image', 'label' => 'hidden', 'weight' => 0, 'settings' => ['image_style' => 'large', 'image_link' => '']]); }
      if ($mode === 'default' && $bundle === 'szerzo') { $disp->setComponent('field_kep', ['type' => 'image', 'label' => 'hidden', 'weight' => 0, 'settings' => ['image_style' => 'large', 'image_link' => '']]); }
      $disp->save();
    }
  }
}
echo "displays updated\ndone\n";
