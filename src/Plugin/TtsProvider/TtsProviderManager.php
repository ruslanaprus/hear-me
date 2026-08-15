<?php

declare(strict_types=1);

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\hear_me\Attribute\TtsProvider;

/**
 * Manages TTS provider plugins.
 */
class TtsProviderManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/TtsProvider',
      $namespaces,
      $module_handler,
      TtsProviderInterface::class,
      TtsProvider::class,
    );

    $this->setCacheBackend($cache_backend, 'hear_me_tts_provider_plugins');
  }

}
