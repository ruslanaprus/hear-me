<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$module_root = dirname(__DIR__);
$drupal_root = realpath($module_root . '/../../..') ?: $module_root . '/../../..';
$project_loader = require $drupal_root . '/autoload.php';
$module_vendor = $module_root . '/vendor';

$module_loader = NULL;
if (is_file($module_vendor . '/composer/autoload_psr4.php')) {
  $module_loader = new ClassLoader($module_vendor);

  $include_paths = require $module_vendor . '/composer/include_paths.php';
  $include_paths[] = get_include_path();
  set_include_path(implode(PATH_SEPARATOR, $include_paths));

  foreach (require $module_vendor . '/composer/autoload_namespaces.php' as $prefix => $paths) {
    $module_loader->set($prefix, $paths);
  }

  foreach (require $module_vendor . '/composer/autoload_psr4.php' as $prefix => $paths) {
    if (str_starts_with($prefix, 'Drupal\\')) {
      continue;
    }
    $module_loader->setPsr4($prefix, $paths);
  }

  $class_map = require $module_vendor . '/composer/autoload_classmap.php';
  foreach (array_keys($class_map) as $class) {
    if ($class === 'Drupal' || $class === 'Composer\\InstalledVersions' || str_starts_with($class, 'Drupal\\')) {
      unset($class_map[$class]);
    }
  }
  $module_loader->addClassMap($class_map);
  $module_loader->register(FALSE);

  $module_drupal_bootstrap = realpath($module_vendor . '/drupal/core/includes/bootstrap.inc') ?: $module_vendor . '/drupal/core/includes/bootstrap.inc';
  $require_file = \Closure::bind(static function (string $identifier, string $file): void {
    if (empty($GLOBALS['__composer_autoload_files'][$identifier])) {
      $GLOBALS['__composer_autoload_files'][$identifier] = TRUE;
      require $file;
    }
  }, NULL, NULL);

  foreach (require $module_vendor . '/composer/autoload_files.php' as $identifier => $file) {
    $file_path = realpath($file) ?: $file;
    if ($file_path === $module_drupal_bootstrap) {
      continue;
    }
    $require_file($identifier, $file);
  }
}

foreach (spl_autoload_functions() as $autoload) {
  if (!is_array($autoload) || !$autoload[0] instanceof ClassLoader || $autoload[1] !== 'loadClass') {
    continue;
  }

  $loader = $autoload[0];
  if ($loader !== $module_loader) {
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

$drupal_tests = $drupal_root . '/core/tests';
$project_loader->add('Drupal\\BuildTests', $drupal_tests);
$project_loader->add('Drupal\\Tests', $drupal_tests);
$project_loader->add('Drupal\\TestSite', $drupal_tests);
$project_loader->add('Drupal\\KernelTests', $drupal_tests);
$project_loader->add('Drupal\\FunctionalTests', $drupal_tests);
$project_loader->add('Drupal\\FunctionalJavascriptTests', $drupal_tests);
$project_loader->add('Drupal\\TestTools', $drupal_tests);

$find_extension_directories = static function (string $scan_directory): array {
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
};

$roots = [
  $drupal_root . '/core/modules',
  $drupal_root . '/core/profiles',
  $drupal_root . '/core/themes',
  $drupal_root . '/modules',
  $drupal_root . '/profiles',
  $drupal_root . '/themes',
];
$directories = [];
foreach ($roots as $root) {
  $directories += $find_extension_directories($root);
}
foreach ($directories as $extension => $directory) {
  if (is_dir($directory . '/src')) {
    $project_loader->addPsr4('Drupal\\' . $extension . '\\', $directory . '/src');
  }
  if (is_dir($directory . '/tests/src')) {
    $project_loader->addPsr4('Drupal\\Tests\\' . $extension . '\\', $directory . '/tests/src');
  }
}

class_exists('Drupal\\KernelTests\\KernelTestBase');
class_exists('Drupal\\KernelTests\\Core\\Entity\\EntityKernelTestBase');
class_exists('Drupal\\Tests\\BrowserTestBase');
class_exists('Drupal\\TestTools\\Extension\\Dump\\DebugDump');
class_exists('Drupal\\TestTools\\Extension\\HtmlLogging\\HtmlOutputLogger');
class_exists('Drupal\\TestTools\\Comparator\\MarkupInterfaceComparator');
trait_exists('Drupal\\Tests\\SchemaCheckTestTrait');

return $project_loader;
