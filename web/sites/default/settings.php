<?php

/**
 * @file
 * Trianoni Szemle - environment driven settings. There are NO secrets in this file.
 *
 * Everything server specific comes from the process environment or from a
 * ".env" file in the project root (one KEY=value per line; Laravel Forge writes
 * exactly this file from its "Environment" editor). See .env.example and
 * docs/DEPLOY-FORGE.md.
 */

/**
 * Reads a setting from the environment, then from ../.env, then the default.
 */
if (!function_exists('tz_env')) {
  function tz_env(string $key, ?string $default = NULL): ?string {
    static $file = NULL;
    if ($file === NULL) {
      $file = [];
      $path = dirname(DRUPAL_ROOT) . '/.env';
      if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
          $line = trim($line);
          if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
          }
          [$k, $v] = explode('=', $line, 2);
          $v = trim($v);
          if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
            $v = substr($v, 1, -1);
          }
          $file[trim($k)] = $v;
        }
      }
    }
    $value = getenv($key);
    if ($value !== FALSE && $value !== '') {
      return $value;
    }
    return $file[$key] ?? $default;
  }
}

/**
 * Database.
 */
$tz_mysql = [
  'driver' => 'mysql',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
];
$databases['default']['default'] = [
  'database' => tz_env('DB_NAME'),
  'username' => tz_env('DB_USER'),
  'password' => tz_env('DB_PASSWORD', ''),
  'host' => tz_env('DB_HOST', '127.0.0.1'),
  'port' => tz_env('DB_PORT', '3306'),
  'prefix' => tz_env('DB_PREFIX', ''),
] + $tz_mysql;

// Read-only source of the one-off legacy import ("drush tz:migrate"). Only
// defined when LEGACY_DB_NAME or LEGACY_DB_PREFIX is set; defaults to the
// main database credentials.
if (tz_env('LEGACY_DB_NAME') || tz_env('LEGACY_DB_PREFIX')) {
  $databases['legacy']['default'] = [
    'database' => tz_env('LEGACY_DB_NAME', tz_env('DB_NAME')),
    'username' => tz_env('LEGACY_DB_USER', tz_env('DB_USER')),
    'password' => tz_env('LEGACY_DB_PASSWORD', tz_env('DB_PASSWORD', '')),
    'host' => tz_env('LEGACY_DB_HOST', tz_env('DB_HOST', '127.0.0.1')),
    'port' => tz_env('LEGACY_DB_PORT', tz_env('DB_PORT', '3306')),
    'prefix' => tz_env('LEGACY_DB_PREFIX', ''),
  ] + $tz_mysql;
}

/**
 * Core settings.
 */
$settings['hash_salt'] = tz_env('HASH_SALT');
if (!$settings['hash_salt']) {
  throw new \RuntimeException('HASH_SALT is not set. Add HASH_SALT=<64 random hex characters> to .env (generate: php -r "echo bin2hex(random_bytes(32));").');
}
$settings['config_sync_directory'] = '../config/sync';
if ($public = tz_env('PUBLIC_FILES_PATH')) {
  $settings['file_public_path'] = $public;
}
$settings['file_private_path'] = tz_env('PRIVATE_FILES_PATH', dirname(DRUPAL_ROOT) . '/private');
$settings['update_free_access'] = FALSE;
$settings['entity_update_batch_size'] = 50;
$settings['file_scan_ignore_directories'] = ['node_modules', 'bower_components'];

// Only these host names are served (comma separated, e.g. trianoniszemle.hu,www.trianoniszemle.hu).
if ($hosts = tz_env('TRUSTED_HOSTS')) {
  $settings['trusted_host_patterns'] = array_map(fn($h) => '^' . preg_quote(trim($h), '/') . '$', explode(',', $hosts));
}

// Behind a load balancer / CDN: REVERSE_PROXY_ADDRESSES=ip1,ip2
if ($proxies = tz_env('REVERSE_PROXY_ADDRESSES')) {
  $settings['reverse_proxy'] = TRUE;
  $settings['reverse_proxy_addresses'] = array_map('trim', explode(',', $proxies));
}

// Legacy import (drush tz:migrate): where the old site's files are on this server.
$settings['tz_legacy_files_path'] = tz_env('LEGACY_FILES_PATH');
$settings['tz_legacy_private_path'] = tz_env('LEGACY_PRIVATE_PATH');
$settings['tz_legacy_fallback_files_path'] = tz_env('LEGACY_FALLBACK_FILES_PATH');

// Show errors only on a local/dev copy.
if (tz_env('APP_ENV', 'production') === 'local') {
  $config['system.logging']['error_level'] = 'verbose';
}

// Optional, git-ignored overrides for one machine.
if (file_exists(__DIR__ . '/settings.local.php')) {
  include __DIR__ . '/settings.local.php';
}
