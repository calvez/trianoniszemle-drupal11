<?php

/**
 * @file
 * Place blocks for the tsz theme, create the editable footer text block, fix
 * menu order, make tsz the default theme. Idempotent.
 * Run: drush php:script migration/09-theme-blocks.php
 */

use Drupal\block\Entity\Block;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;

$theme = 'tsz';
$place = function (string $id, string $plugin, string $region, array $settings = [], array $visibility = [], int $weight = 0) use ($theme) {
  $id = "{$theme}_$id";
  Block::load($id)?->delete();
  Block::create(['id' => $id, 'theme' => $theme, 'region' => $region, 'weight' => $weight, 'plugin' => $plugin,
    'settings' => $settings + ['id' => $plugin, 'label' => $id, 'label_display' => '0', 'provider' => explode(':', $plugin)[0] === 'views_block' ? 'views' : 'system'],
    'visibility' => $visibility])->save();
  echo "block $id @ $region\n";
};

$place('main_menu', 'system_menu_block:main', 'primary_menu', ['label' => 'Fő navigáció', 'level' => 1, 'depth' => 2, 'expand_all_items' => TRUE, 'provider' => 'system']);
$place('search', 'search_form_block', 'search', ['label' => 'Keresés', 'provider' => 'search']);
$place('page_title', 'page_title_block', 'hero', ['label' => 'Oldalcím', 'provider' => 'core']);
$place('messages', 'system_messages_block', 'highlighted', ['label' => 'Üzenetek', 'provider' => 'system'], [], -10);
$place('local_tasks', 'local_tasks_block', 'highlighted', ['label' => 'Fülek', 'primary' => TRUE, 'secondary' => TRUE, 'provider' => 'core'], [], 0);
$place('help', 'help_block', 'help', ['label' => 'Súgó', 'provider' => 'help']);
$place('content', 'system_main_block', 'content', ['label' => 'Fő tartalom', 'provider' => 'system'], [], 0);
$place('footer_menu', 'system_menu_block:footer', 'footer_menu', ['label' => 'Lábléc', 'level' => 1, 'depth' => 1, 'provider' => 'system']);
$front = ['request_path' => ['id' => 'request_path', 'negate' => FALSE, 'pages' => '<front>']];
$place('front_issues', 'views_block:lapszamok-block_latest', 'content', ['label' => 'Legfrissebb lapszámok', 'label_display' => 'visible', 'views_label' => '', 'items_per_page' => 'none'], $front, 11);
$place('front_blog', 'views_block:blog-block_latest', 'content', ['label' => 'Hírek, események', 'label_display' => 'visible', 'views_label' => '', 'items_per_page' => 'none'], $front, 12);

// Editable footer text (a block, so editors can change it in the UI).
if (!BlockContentType::load('basic')) {
  BlockContentType::create(['id' => 'basic', 'label' => 'Egyszerű blokk', 'revision' => FALSE])->save();
  block_content_add_body_field('basic');
}
$existing = \Drupal::entityTypeManager()->getStorage('block_content')->loadByProperties(['info' => 'Lábléc - elérhetőség']);
if (!$existing) {
  $bc = BlockContent::create(['type' => 'basic', 'info' => 'Lábléc - elérhetőség', 'langcode' => 'hu', 'reusable' => TRUE, 'body' => ['format' => 'full_html', 'value' =>
    '<h2>Trianon Kutató Intézet Közhasznú Alapítvány</h2>' .
    '<p>Levelezési cím: 1038 Budapest, III. kerület, Ibolya u. 14.</p>' .
    '<p>Adószám: 18127832-1-41<br>Számlavezető pénzintézet: MBH Bank Zrt.<br>Bankszámlaszám: 10100716-54704800-01002009</p>' .
    '<p>E-mail: trianonkha (kukac) gmail.com</p>']]);
  $bc->save();
  $existing = [$bc];
}
$bc = reset($existing);
Block::load('tsz_footer_text')?->delete();
Block::create(['id' => 'tsz_footer_text', 'theme' => $theme, 'region' => 'footer_text', 'weight' => 0, 'plugin' => 'block_content:' . $bc->uuid(),
  'settings' => ['id' => 'block_content:' . $bc->uuid(), 'label' => 'Lábléc - elérhetőség', 'label_display' => '0', 'provider' => 'block_content', 'status' => TRUE, 'info' => '', 'view_mode' => 'full']])->save();
echo "footer block placed\n";

// Menu order: Évfolyamok, Hírek, Repertórium, Szerzők, Rólunk, Támogatóink.
$s = \Drupal::entityTypeManager()->getStorage('menu_link_content');
foreach ($s->loadByProperties(['menu_name' => 'main', 'parent' => '']) as $l) {
  $w = ['Évfolyamok' => 0, 'Hírek események' => 1, 'Rólunk' => 4, 'Támogatóink' => 5][$l->label()] ?? NULL;
  if ($w !== NULL) { $l->set('weight', $w)->save(); }
}

// Theme settings + default theme.
\Drupal::configFactory()->getEditable('tsz.settings')
  ->set('logo.use_default', FALSE)->set('logo.path', 'public://logo/header_logo.png')
  ->set('favicon.use_default', FALSE)->set('favicon.path', 'public://logo/Trianoni_Szemle_logo.png')->set('favicon.mimetype', 'image/png')
  ->set('features.favicon', TRUE)->save();
\Drupal::configFactory()->getEditable('system.theme')->set('default', 'tsz')->save();
echo "default theme: tsz\n";
