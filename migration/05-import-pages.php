<?php

/**
 * @file
 * One-off import (phase B2): the real static pages (Droopler demo pages are
 * dropped). "Kezdőlap" and "Évfolyamok" are listings and are rebuilt as Views.
 * Run: drush php:script migration/05-import-pages.php   (idempotent)
 */

use Drupal\node\Entity\Node;
use Drupal\pathauto\PathautoState;

$legacy = \Drupal\Core\Database\Database::getConnection('default', 'legacy');
$pages = [489, 494, 495, 498, 499, 500, 501, 502, 2540];
$link = fn($u) => str_starts_with((string) $u, 'internal:') ? substr($u, 9) : $u;
$val = fn(string $t, string $col, int $pid) => $legacy->query("select $col from {paragraph__$t} where entity_id=:p order by delta limit 1", [':p' => $pid])->fetchField();

$n = 0;
foreach ($pages as $nid) {
  if (Node::load($nid)) continue;
  $row = $legacy->query("select * from {node_field_data} where nid=:n", [':n' => $nid])->fetchObject();
  $html = '';
  foreach ($legacy->query("select p.id, p.type from {node__field_page_section} s join {paragraphs_item_field_data} p on p.id=s.field_page_section_target_id and p.revision_id=s.field_page_section_target_revision_id where s.entity_id=:n order by s.delta", [':n' => $nid]) as $s) {
    if ($s->type !== 'd_p_text_paged') { echo "  skipped section $s->type on $nid\n"; continue; }
    $pid = (int) $s->id;
    if ($t = trim((string) $val('field_d_main_title', 'field_d_main_title_value', $pid))) $html .= "<h2>$t</h2>\n";
    if ($t = trim((string) $val('field_d_subtitle', 'field_d_subtitle_value', $pid))) $html .= "<h3>$t</h3>\n";
    if ($t = $val('field_d_long_text', 'field_d_long_text_value', $pid)) $html .= $t . "\n";
    $cta = $legacy->query("select field_d_cta_link_uri u, field_d_cta_link_title t from {paragraph__field_d_cta_link} where entity_id=:p limit 1", [':p' => $pid])->fetchObject();
    if ($cta && $cta->u) $html .= sprintf('<p><a href="%s">%s</a></p>' . "\n", htmlspecialchars($link($cta->u), ENT_QUOTES), htmlspecialchars($cta->t ?: $cta->u));
  }
  $alias = $legacy->query("select alias from {path_alias} where path=:p and status=1", [':p' => "/node/$nid"])->fetchField();
  $v = ['nid' => $nid, 'type' => 'page', 'title' => $row->title, 'status' => (int) $row->status, 'created' => (int) $row->created, 'changed' => (int) $row->changed,
    'uid' => 1, 'langcode' => 'hu', 'promote' => 0, 'sticky' => 0, 'body' => ['value' => $html, 'format' => 'full_html']];
  if ($alias) $v['path'] = ['alias' => $alias, 'pathauto' => PathautoState::SKIP];
  Node::create($v)->save();
  $n++;
  echo "page $nid $row->title -> $alias (", strlen($html), " bytes)\n";
}
echo "pages created: $n\n";

// The issue index replaces the old "Évfolyamok" page at its old URL.
$view = \Drupal\views\Entity\View::load('lapszamok');
$d = $view->getDisplay('page_1');
$d['display_options']['path'] = 'evfolyamok';
$view->set('display', array_merge($view->get('display'), ['page_1' => $d]));
$view->save();
echo "lapszamok view now at /evfolyamok\n";
