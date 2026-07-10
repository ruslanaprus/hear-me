<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

require_once __DIR__ . '/autoload.php';
class_exists('Drupal\\Tests\\DocumentElement');
class_exists('Drupal\\TestTools\\Extension\\DeprecationBridge\\DeprecationHandler');

$module_root = dirname(__DIR__);
$drupal_root = realpath($module_root . '/../../..') ?: $module_root . '/../../..';

/**
 * Finds extension directories while skipping vendored dependencies.
 */
function hear_me_phpunit_find_extension_directories(string $scan_directory): array {
  if (!is_dir($scan_directory)) {
    return [];
  }

  $directory = new RecursiveDirectoryIterator($scan_directory, RecursiveDirectoryIterator::FOLLOW_SYMLINKS);
  $filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $current): bool {
    return !$current->isDir() || !in_array($current->getFilename(), ['vendor', 'node_modules'], TRUE);
  });
  $iterator = new RecursiveIteratorIterator($filter);
  $extensions = [];

  foreach ($iterator as $file) {
    if (!str_ends_with($file->getFilename(), '.info.yml')) {
      continue;
    }

    $extension = substr($file->getFilename(), 0, -9);
    $path = $file->getPathInfo()->getRealPath();
    if ($path !== FALSE) {
      $extensions[$extension] = $path;
    }
  }

  return $extensions;
}

/**
 * Builds Drupal and test namespaces without registering nested vendor modules.
 */
function hear_me_phpunit_get_extension_namespaces(string $drupal_root): array {
  $roots = [
    $drupal_root . '/core/modules',
    $drupal_root . '/core/profiles',
    $drupal_root . '/core/themes',
    $drupal_root . '/modules',
    $drupal_root . '/profiles',
    $drupal_root . '/themes',
  ];

  $sites_path = $drupal_root . '/sites';
  if (is_dir($sites_path)) {
    foreach (scandir($sites_path) ?: [] as $site) {
      if ($site[0] === '.' || $site === 'simpletest') {
        continue;
      }
      $path = $sites_path . '/' . $site;
      $roots[] = is_dir($path . '/modules') ? realpath($path . '/modules') : NULL;
      $roots[] = is_dir($path . '/profiles') ? realpath($path . '/profiles') : NULL;
      $roots[] = is_dir($path . '/themes') ? realpath($path . '/themes') : NULL;
    }
  }

  $directories = [];
  foreach (array_filter($roots) as $root) {
    $directories += hear_me_phpunit_find_extension_directories($root);
  }

  $namespaces = [];
  foreach ($directories as $extension => $directory) {
    if (is_dir($directory . '/src')) {
      $namespaces['Drupal\\' . $extension . '\\'][] = $directory . '/src';
    }
    if (is_dir($directory . '/tests/src')) {
      $namespaces['Drupal\\Tests\\' . $extension . '\\'][] = $directory . '/tests/src';
    }
  }

  return $namespaces;
}

$GLOBALS['namespaces'] = hear_me_phpunit_get_extension_namespaces($drupal_root);

foreach (spl_autoload_functions() as $autoload) {
  if (!is_array($autoload) || !$autoload[0] instanceof ClassLoader || $autoload[1] !== 'loadClass') {
    continue;
  }

  $loader = $autoload[0];
  $core_paths = array_map(
    static fn(string $path): string => realpath($path) ?: $path,
    $loader->getPrefixesPsr4()['Drupal\\Core\\'] ?? [],
  );
  $module_vendor_core = realpath($module_root . '/vendor/drupal/core/lib/Drupal/Core') ?: $module_root . '/vendor/drupal/core/lib/Drupal/Core';
  if (!in_array($module_vendor_core, $core_paths, TRUE)) {
    continue;
  }

  spl_autoload_unregister($autoload);
  spl_autoload_register(static function (string $class) use ($loader): void {
    if (str_starts_with($class, 'Drupal\\')) {
      return;
    }
    $loader->loadClass($class);
  }, TRUE, FALSE);
}

require $module_root . '/../../../core/tests/bootstrap.php';
