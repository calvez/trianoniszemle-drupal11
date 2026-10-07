<?php
$s = \Drupal::entityTypeManager()->getStorage('node');
foreach ($s->loadByProperties(['title' => ['Teszt cikk egy', 'Teszt cikk kettő', 'Teszt Szerző', '2099 / 1']]) as $n) {
  echo $n->bundle(), " #", $n->id(), " ", $n->label(), " -> ", $n->toUrl()->toString(), "\n";
}
echo "--- deleting\n";
$nodes = $s->loadByProperties(['title' => ['Teszt cikk egy', 'Teszt cikk kettő', 'Teszt Szerző', '2099 / 1']]);
$s->delete($nodes);
echo "left: ", count($s->loadByProperties(['title' => ['Teszt cikk egy', 'Teszt cikk kettő', 'Teszt Szerző', '2099 / 1']])), " | total nodes now: ", \Drupal::entityQuery('node')->accessCheck(FALSE)->count()->execute(), "\n";
