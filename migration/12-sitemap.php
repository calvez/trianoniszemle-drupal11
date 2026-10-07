<?php
// Sitemap: index the content types and the main listing pages. Idempotent.
$em = \Drupal::service('simple_sitemap.entity_manager');
foreach (['cikk' => ['monthly', 0.5], 'lapszam' => ['yearly', 0.7], 'szerzo' => ['monthly', 0.4], 'blog_post' => ['monthly', 0.6], 'page' => ['yearly', 0.6]] as $bundle => [$freq, $prio]) {
  $em->setBundleSettings('node', $bundle, ['index' => TRUE, 'priority' => $prio, 'changefreq' => $freq]);
}
$cl = \Drupal::service('simple_sitemap.custom_link_manager');
foreach (['/' => 1.0, '/evfolyamok' => 0.9, '/blog' => 0.8, '/repertorium' => 0.8, '/szerzok' => 0.8] as $path => $prio) {
  $cl->add($path, ['priority' => $prio, 'changefreq' => $path === '/' ? 'weekly' : 'monthly']);
}
// Keep the home node (/kezdolap) out: "/" is listed instead.
$em->setBundleSettings('node', 'page', ['index' => TRUE, 'priority' => 0.6, 'changefreq' => 'yearly']);
echo "sitemap configured\n";
