<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\Entity\Media;

class HearMeService {

  protected ConfigFactoryInterface $configFactory;
  protected iterable $providers;
  protected FileSystemInterface $fileSystem;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    iterable $providers,
    FileSystemInterface $fileSystem,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->configFactory     = $configFactory;
    $this->providers         = $providers;
    $this->fileSystem        = $fileSystem;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Returns the default language for the currently active provider.
   *
   * Reads from the active provider's own config namespace so that each
   * provider can independently define its default language.
   *
   * @return string
   *   Language code, e.g. 'en'.
   */
  public function getDefaultLang(): string {
    $providerKey = $this->configFactory->get('hear_me.settings')->get('provider') ?? 'piper';
    return $this->configFactory->get('hear_me.provider.' . $providerKey)
      ->get('default_lang') ?? 'en';
  }

  /**
   * Synthesizes text to speech using the configured provider.
   *
   * Checks the file-based cache before delegating to the provider.
   *
   * @param string $text
   *   The text to synthesize.
   * @param string $lang
   *   Language code (e.g., 'en', 'uk').
   *
   * @return \Drupal\media\Entity\Media|null
   *   Media entity containing the audio file, or NULL on failure.
   */
  public function synthesize(string $text, string $lang): ?Media {
    $config      = $this->configFactory->get('hear_me.settings');
    $providerKey = $config->get('provider') ?? 'piper';

    $providers = $this->getProviders();
    if (!isset($providers[$providerKey])) {
      return NULL;
    }

    $provider = $providers[$providerKey];

    if ($config->get('cache_enabled')) {
      $hash = md5($text . $lang . $providerKey);
      $uri  = 'public://tts/' . $hash . '.wav';

      if (file_exists($this->fileSystem->realpath($uri))) {
        $files = $this->entityTypeManager
          ->getStorage('file')
          ->loadByProperties(['uri' => $uri]);

        if ($files) {
          $file  = reset($files);
          $media = $this->entityTypeManager
            ->getStorage('media')
            ->loadByProperties(['field_media_audio_file' => $file->id()]);

          if ($media) {
            return reset($media);
          }
        }
      }
    }

    return $provider->synthesize($text, $lang);
  }

  /**
   * Returns all registered TTS provider plugins, keyed by provider_key.
   *
   * @return array<string, \Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface>
   */
  public function getProviders(): array {
    if ($this->providers instanceof \Traversable) {
      return iterator_to_array($this->providers);
    }
    return (array) $this->providers;
  }

}
