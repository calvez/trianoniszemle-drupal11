<?php

/**
 * @file
 * One-off (phase B4): blog teaser display, /blog listing, latest-issues and
 * latest-posts blocks, homepage (node 5, front page), main + footer menus.
 * Run: drush php:script migration/07-front-menus.php   (idempotent)
 */

use Drupal\block\Entity\Block;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\views\Entity\View;

// 1. Teaser display for blog posts ----------------------------------------------
EntityViewDisplay::load('node.blog_post.teaser')?->delete();
EntityViewDisplay::create(['targetEntityType' => 'node', 'bundle' => 'blog_post', 'mode' => 'teaser', 'status' => TRUE])
  ->setComponent('field_kep', ['type' => 'image', 'label' => 'hidden', 'weight' => 0, 'settings' => ['image_style' => 'medium', 'image_link' => 'content']])
  ->setComponent('body', ['type' => 'text_summary_or_trimmed', 'label' => 'hidden', 'weight' => 1, 'settings' => ['trim_length' => 300]])
  ->save();
// Full display: image above text, no category clutter in teaser.
$full = EntityViewDisplay::load('node.blog_post.default');
$full->setComponent('body', ['type' => 'text_default', 'label' => 'hidden', 'weight' => 1, 'settings' => []])
  ->setComponent('field_kep', ['type' => 'image', 'label' => 'hidden', 'weight' => 0, 'settings' => ['image_style' => 'large', 'image_link' => '']])
  ->save();

// 2. Views ---------------------------------------------------------------------------
$display_defaults = [
  'access' => ['type' => 'perm', 'options' => ['perm' => 'access content']], 'cache' => ['type' => 'tag', 'options' => []],
  'exposed_form' => ['type' => 'basic', 'options' => []], 'query' => ['type' => 'views_query', 'options' => []],
  'style' => ['type' => 'default'], 'row' => ['type' => 'entity:node', 'options' => ['view_mode' => 'teaser']],
  'relationships' => [], 'header' => [], 'footer' => [], 'empty' => [], 'fields' => [], 'arguments' => [],
];
$filters = [
  'status' => ['id' => 'status', 'table' => 'node_field_data', 'field' => 'status', 'plugin_id' => 'boolean', 'value' => '1', 'entity_type' => 'node', 'entity_field' => 'status', 'group' => 1],
  'type' => ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type', 'plugin_id' => 'bundle', 'value' => ['blog_post' => 'blog_post'], 'entity_type' => 'node', 'entity_field' => 'type', 'group' => 1],
];
$sort = ['created' => ['id' => 'created', 'table' => 'node_field_data', 'field' => 'created', 'plugin_id' => 'date', 'order' => 'DESC', 'entity_type' => 'node', 'entity_field' => 'created']];

if (!View::load('blog')) {
  View::create(['id' => 'blog', 'label' => 'Blog', 'module' => 'views', 'base_table' => 'node_field_data', 'base_field' => 'nid', 'langcode' => 'hu', 'status' => TRUE, 'display' => [
    'default' => ['display_plugin' => 'default', 'id' => 'default', 'display_title' => 'Alapértelmezett', 'position' => 0, 'display_options' => $display_defaults + [
      'title' => 'Hírek, események', 'filters' => $filters, 'sorts' => $sort, 'pager' => ['type' => 'full', 'options' => ['items_per_page' => 10, 'offset' => 0]]]],
    'page_1' => ['display_plugin' => 'page', 'id' => 'page_1', 'display_title' => 'Oldal', 'position' => 1, 'display_options' => ['path' => 'blog', 'menu' => ['type' => 'none']]],
    'block_latest' => ['display_plugin' => 'block', 'id' => 'block_latest', 'display_title' => 'Legfrissebb hírek', 'position' => 2, 'display_options' => [
      'block_description' => 'Legfrissebb hírek', 'display_description' => '', 'defaults' => ['pager' => FALSE, 'title' => FALSE], 'title' => 'Hírek, események',
      'pager' => ['type' => 'some', 'options' => ['items_per_page' => 4, 'offset' => 0]]]],
  ]])->save();
  echo "view blog created\n";
}
$l = View::load('lapszamok');
$disp = $l->get('display');
if (!isset($disp['block_latest'])) {
  $disp['block_latest'] = ['display_plugin' => 'block', 'id' => 'block_latest', 'display_title' => 'Legfrissebb lapszámok', 'position' => 2, 'display_options' => [
    'block_description' => 'Legfrissebb lapszámok', 'display_description' => '', 'defaults' => ['pager' => FALSE, 'title' => FALSE], 'title' => 'Legfrissebb lapszámok',
    'pager' => ['type' => 'some', 'options' => ['items_per_page' => 4, 'offset' => 0]]]];
  $l->set('display', $disp)->save();
  echo "lapszamok block_latest added\n";
}

// 3. Homepage ------------------------------------------------------------------------
if (!Node::load(5)) {
  Node::create(['nid' => 5, 'type' => 'page', 'title' => 'Kezdőlap', 'status' => 1, 'uid' => 1, 'langcode' => 'hu',
    'created' => 1619273000, 'path' => ['alias' => '/kezdolap', 'pathauto' => \Drupal\pathauto\PathautoState::SKIP],
    'body' => ['value' => '<p>A Trianoni Szemle a Trianon Kutatóintézet Közhasznú Alapítvány Magyar Örökség-díjas folyóirata.</p>', 'format' => 'full_html']])->save();
  echo "home node created\n";
}
\Drupal::configFactory()->getEditable('system.site')->set('page.front', '/node/5')->save();

// 4. Blocks on the front page ----------------------------------------------------------
foreach (['lapszamok-block_latest' => 11, 'blog-block_latest' => 12] as $plugin => $weight) {
  $id = 'olivero_front_' . str_replace('-', '_', $plugin);
  if (!Block::load($id)) {
    Block::create(['id' => $id, 'theme' => 'olivero', 'region' => 'content', 'weight' => $weight, 'plugin' => "views_block:$plugin",
      'settings' => ['id' => "views_block:$plugin", 'label' => '', 'label_display' => '0', 'provider' => 'views', 'views_label' => '', 'items_per_page' => 'none'],
      'visibility' => ['request_path' => ['id' => 'request_path', 'negate' => FALSE, 'pages' => '<front>']]])->save();
    echo "block $id\n";
  }
}
if (!Block::load('olivero_footer_menu')) {
  Block::create(['id' => 'olivero_footer_menu', 'theme' => 'olivero', 'region' => 'footer_top', 'weight' => 0, 'plugin' => 'system_menu_block:footer',
    'settings' => ['id' => 'system_menu_block:footer', 'label' => 'Lábléc', 'label_display' => '0', 'provider' => 'system', 'level' => 1, 'depth' => 1, 'expand_all_items' => FALSE]])->save();
  echo "footer menu block\n";
}

// 5. Menus -----------------------------------------------------------------------------------
$storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
if (!$storage->loadByProperties(['menu_name' => 'main'])) {
  $mk = fn($menu, $title, $uri, $weight, $parent = NULL) => tap_menu($menu, $title, $uri, $weight, $parent);
  function tap_menu($menu, $title, $uri, $weight, $parent) {
    $l = MenuLinkContent::create(['menu_name' => $menu, 'title' => $title, 'link' => ['uri' => $uri], 'weight' => $weight, 'expanded' => TRUE, 'langcode' => 'hu'] + ($parent ? ['parent' => $parent] : []));
    $l->save();
    return 'menu_link_content:' . $l->uuid();
  }
  $mk('main', 'Évfolyamok', 'internal:/evfolyamok', 0);
  $mk('main', 'Hírek események', 'internal:/blog', 1);
  $about = $mk('main', 'Rólunk', 'entity:node/495', 2);
  foreach ([[494, 'Kapcsolat', 0], [500, 'Kuratórium', 1], [501, 'A kuratórium döntései', 2], [502, 'Szerkesztőség', 3], [2540, 'Szabályzóink', 4]] as [$n, $t, $w]) $mk('main', $t, "entity:node/$n", $w, $about);
  $mk('main', 'Támogatóink', 'entity:node/499', 3);
  $mk('footer', 'Adatkezelési tájékoztató', 'entity:node/489', 0);
  $mk('footer', 'Támogatóink', 'entity:node/499', 1);
  echo "menus created\n";
}
echo "done\n";
