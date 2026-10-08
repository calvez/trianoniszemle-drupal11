<?php

namespace Drupal\tz_migrate\Drush\Commands;

use Consolidation\AnnotatedCommand\CommandResult;
use Drupal\Core\Site\Settings;
use Drupal\tz_migrate\LegacyMigrator;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the one-off legacy import. See docs/MIGRATION.md.
 */
final class TzMigrateCommands extends DrushCommands {

  /** A path from the Drupal settings (filled from .env / the environment in settings.php). */
  private function setting(string $name): ?string {
    $v = Settings::get($name);
    return $v === NULL || $v === '' ? NULL : (string) $v;
  }

  private function migrator(array $options): LegacyMigrator {
    $public = $options['legacy-files'] ?: $this->setting('tz_legacy_files_path');
    $private = $options['legacy-private'] ?: $this->setting('tz_legacy_private_path');
    $fallback = $options['fallback-files'] ?: $this->setting('tz_legacy_fallback_files_path');
    if (!$public) {
      throw new \InvalidArgumentException('Give --legacy-files=/path/to/old/web/sites/default/files (or set LEGACY_FILES_PATH).');
    }
    return new LegacyMigrator(rtrim($public, '/'), $private ? rtrim($private, '/') : NULL, $fallback ? rtrim($fallback, '/') : NULL,
      fn(string $m) => $this->io()->writeln($m), (bool) $options['refresh']);
  }

  #[CLI\Command(name: 'tz:migrate', aliases: ['tzm'])]
  #[CLI\Option(name: 'legacy-files', description: "The old site's public files directory (env LEGACY_FILES_PATH).")]
  #[CLI\Option(name: 'legacy-private', description: "The old site's private files directory with the article PDFs (env LEGACY_PRIVATE_PATH).")]
  #[CLI\Option(name: 'fallback-files', description: 'Optional second public files directory searched when a file is missing in the first (env LEGACY_FALLBACK_FILES_PATH).')]
  #[CLI\Option(name: 'steps', description: 'Comma separated: content,blog,pages,files,redirects,menus,cleanup (default: all, in that order).')]
  #[CLI\Option(name: 'refresh', description: 'Also rebuild the body of blog posts / pages that already exist.')]
  #[CLI\Usage(name: 'drush tz:migrate --legacy-files=/srv/old/web/sites/default/files --legacy-private=/srv/old/private', description: 'Run the whole import.')]
  #[CLI\Usage(name: 'drush tz:migrate --steps=blog --refresh', description: 'Rebuild blog bodies only.')]
  public function migrate(array $options = ['legacy-files' => NULL, 'legacy-private' => NULL, 'fallback-files' => NULL, 'steps' => 'content,blog,pages,files,redirects,menus,cleanup', 'refresh' => FALSE]): int {
    $migrator = $this->migrator($options);
    $steps = array_values(array_filter(array_map('trim', explode(',', (string) $options['steps']))));
    $unknown = array_diff($steps, LegacyMigrator::STEPS);
    if ($unknown) {
      throw new \InvalidArgumentException('Unknown step(s): ' . implode(', ', $unknown) . '. Valid: ' . implode(', ', LegacyMigrator::STEPS));
    }
    $problems = $migrator->preflight();
    if ($problems) {
      $this->io()->error(array_merge(['Pre-flight check failed:'], array_map(fn($p) => ' - ' . $p, $problems)));
      return self::EXIT_FAILURE;
    }
    $this->io()->success('Pre-flight check passed. Steps: ' . implode(', ', $steps));
    if (!$this->io()->confirm('Import the legacy content into THIS site now? (existing nodes are skipped)', TRUE)) {
      return self::EXIT_FAILURE_WITH_CLARITY;
    }
    $start = microtime(TRUE);
    $stats = $migrator->run($steps);
    $this->io()->newLine();
    $this->io()->section('Result');
    foreach ($stats as $k => $v) {
      $this->io()->writeln(sprintf('  %-24s %s', $k, is_array($v) ? count($v) . ($v ? ' (' . implode(', ', array_slice($v, 0, 5)) . (count($v) > 5 ? ', ...' : '') . ')' : '') : $v));
    }
    $this->io()->success(sprintf('Done in %.0f s. Next: drush tz:migrate-verify, then rebuild the sitemap and search index (see docs/MIGRATION.md).', microtime(TRUE) - $start));
    return self::EXIT_SUCCESS;
  }

  #[CLI\Command(name: 'tz:migrate-verify', aliases: ['tzv'])]
  #[CLI\Option(name: 'legacy-files', description: 'Not needed; accepted for symmetry.')]
  #[CLI\Usage(name: 'drush tz:migrate-verify', description: 'Compare this site with the legacy data; exit code 1 on any failure.')]
  public function verify(array $options = ['legacy-files' => NULL]): CommandResult {
    $migrator = new LegacyMigrator('/nonexistent', NULL, NULL, fn(string $m) => NULL);
    $ok = TRUE;
    foreach ($migrator->verify() as $label => [$pass, $detail]) {
      $this->io()->writeln(sprintf('  [%s] %-36s %s', $pass ? ' OK ' : 'FAIL', $label, $detail));
      $ok = $ok && $pass;
    }
    $this->io()->newLine();
    $ok ? $this->io()->success('All checks passed.') : $this->io()->error('Some checks failed.');
    return CommandResult::exitCode($ok ? 0 : 1);
  }

}
