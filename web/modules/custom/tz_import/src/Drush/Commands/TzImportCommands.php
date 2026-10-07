<?php

declare(strict_types=1);

namespace Drupal\tz_import\Drush\Commands;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Imports articles (and the authors / issue they need) from a CSV file.
 *
 * Columns (UTF-8, header row required; order does not matter):
 *   cim        article title (required)
 *   alcim      subtitle (optional)
 *   szerzok    author names separated by ";" (optional)
 *   lapszam    issue title, e.g. "2026 / 3" (required)
 *   oldalszam  first page number (optional)
 *   pdf        file name of the article PDF (optional); the file must be in
 *              private/import/ in the project root
 *
 * Authors and issues are created when missing. Re-running the same CSV is
 * safe: an article with the same title in the same issue is skipped.
 */
final class TzImportCommands extends DrushCommands {

  #[CLI\Command(name: 'tz:import-csv', aliases: ['tzcsv'])]
  #[CLI\Argument(name: 'file', description: 'Path to the CSV file.')]
  #[CLI\Option(name: 'dry-run', description: 'Show what would be created without changing anything.')]
  #[CLI\Usage(name: 'drush tz:import-csv cikkek.csv --dry-run', description: 'Preview an import.')]
  #[CLI\Usage(name: 'drush tz:import-csv cikkek.csv', description: 'Run the import.')]
  public function importCsv(string $file, array $options = ['dry-run' => FALSE]): void {
    if (!is_readable($file)) {
      throw new \InvalidArgumentException("Cannot read $file");
    }
    $dry = (bool) $options['dry-run'];
    $fh = fopen($file, 'r');
    $header = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), (array) fgetcsv($fh));
    foreach (['cim', 'lapszam'] as $required) {
      if (!in_array($required, $header, TRUE)) {
        throw new \InvalidArgumentException("Missing required column '$required'. Found: " . implode(', ', $header));
      }
    }
    $stats = ['articles' => 0, 'skipped' => 0, 'authors' => 0, 'issues' => 0, 'pdfs' => 0, 'warnings' => 0];
    $line = 1;
    $seen = [];
    while (($raw = fgetcsv($fh)) !== FALSE) {
      $line++;
      if ($raw === [NULL] || count(array_filter($raw, 'strlen')) === 0) {
        continue;
      }
      $row = array_combine($header, array_pad($raw, count($header), ''));
      $title = trim($row['cim']);
      $issue_title = trim($row['lapszam']);
      if ($title === '' || $issue_title === '') {
        $this->logger()->warning("Line $line: title or issue missing, skipped.");
        $stats['warnings']++;
        continue;
      }
      $issue = $this->findByTitle('lapszam', $issue_title);
      if (!$issue && !isset($seen['i:' . $issue_title])) {
        $seen['i:' . $issue_title] = TRUE;
        $stats['issues']++;
        $this->logger()->notice(($dry ? '[dry] ' : '') . "Create issue: $issue_title");
        if (!$dry) {
          $values = ['type' => 'lapszam', 'title' => $issue_title, 'status' => 1, 'uid' => 1, 'langcode' => 'hu'];
          if (preg_match('/^(\d{4})\s*\/\s*(\d+)/', $issue_title, $m)) {
            $values['field_evfolyam'] = (int) $m[1];
            $values['field_lapszam'] = $m[2];
            $values['field_sorszam'] = (int) $m[1] * 100 + (int) $m[2];
          }
          $issue = Node::create($values);
          $issue->save();
        }
      }
      if ($issue && $this->articleExists($title, (int) $issue->id())) {
        $stats['skipped']++;
        continue;
      }
      $author_ids = [];
      foreach (array_filter(array_map('trim', explode(';', (string) $row['szerzok']))) as $name) {
        $author = $this->findByTitle('szerzo', $name);
        if (!$author && !isset($seen['a:' . $name])) {
          $seen['a:' . $name] = TRUE;
          $stats['authors']++;
          $this->logger()->notice(($dry ? '[dry] ' : '') . "Create author: $name");
          if (!$dry) {
            $author = Node::create(['type' => 'szerzo', 'title' => $name, 'field_tipus' => 'Szerző', 'status' => 1, 'uid' => 1, 'langcode' => 'hu']);
            $author->save();
          }
        }
        if ($author) {
          $author_ids[] = ['target_id' => $author->id()];
        }
      }
      $values = ['type' => 'cikk', 'title' => $title, 'status' => 1, 'uid' => 1, 'langcode' => 'hu'];
      if (trim($row['alcim'] ?? '') !== '') {
        $values['field_alcim'] = trim($row['alcim']);
      }
      if (trim($row['oldalszam'] ?? '') !== '') {
        $values['field_oldalszam'] = (int) $row['oldalszam'];
      }
      if ($issue) {
        $values['field_szam'] = ['target_id' => $issue->id()];
      }
      if ($author_ids) {
        $values['field_szerzo'] = $author_ids;
      }
      if (($pdf = trim($row['pdf'] ?? '')) !== '') {
        $src = DRUPAL_ROOT . '/../private/import/' . basename($pdf);
        if (!is_file($src)) {
          $this->logger()->warning("Line $line: PDF not found: private/import/" . basename($pdf));
          $stats['warnings']++;
        }
        elseif (!$dry) {
          $dir = 'private://pdf/' . date('Y-m');
          \Drupal::service('file_system')->prepareDirectory($dir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
          $dest = \Drupal::service('file_system')->copy($src, "$dir/" . basename($pdf), \Drupal\Core\File\FileExists::Rename);
          $f = File::create(['uri' => $dest, 'filename' => basename($dest), 'uid' => 1, 'status' => 1, 'filemime' => 'application/pdf']);
          $f->save();
          $values['field_pdf'] = ['target_id' => $f->id(), 'display' => 1, 'description' => ''];
          $stats['pdfs']++;
        }
        else {
          $stats['pdfs']++;
        }
      }
      $stats['articles']++;
      $this->logger()->notice(($dry ? '[dry] ' : '') . "Create article: $title ($issue_title)");
      if (!$dry) {
        Node::create($values)->save();
      }
    }
    fclose($fh);
    $this->io()->success(($dry ? 'DRY RUN - nothing saved. ' : '') . sprintf('Articles: %d new, %d already existed | issues created: %d | authors created: %d | PDFs: %d | warnings: %d',
      $stats['articles'], $stats['skipped'], $stats['issues'], $stats['authors'], $stats['pdfs'], $stats['warnings']));
  }

  private function findByTitle(string $bundle, string $title): ?Node {
    $ids = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', $bundle)->condition('title', $title)->range(0, 1)->execute();
    return $ids ? Node::load(reset($ids)) : NULL;
  }

  private function articleExists(string $title, int $issue_id): bool {
    return (bool) \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'cikk')->condition('title', $title)->condition('field_szam', $issue_id)->range(0, 1)->execute();
  }

}
