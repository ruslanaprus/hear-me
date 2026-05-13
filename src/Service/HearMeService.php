<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\file\Entity\File;
use Drupal\hear_me\TtsAudioResult;
use Drupal\hear_me\TtsSynthesisResult;
use Drupal\media\Entity\Media;

class HearMeService {

  protected ConfigFactoryInterface $configFactory;
  protected iterable $providers;
  protected FileSystemInterface $fileSystem;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected TtsFileHelperInterface $fileHelper;
  protected \Psr\Log\LoggerInterface $logger;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    iterable $providers,
    FileSystemInterface $fileSystem,
    EntityTypeManagerInterface $entityTypeManager,
    TtsFileHelperInterface $fileHelper,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->configFactory     = $configFactory;
    $this->providers         = $providers;
    $this->fileSystem        = $fileSystem;
    $this->entityTypeManager = $entityTypeManager;
    $this->fileHelper        = $fileHelper;
    $this->logger            = $loggerFactory->get('hear_me');
  }

  /**
   * Resolves the active TTS provider key from configuration.
   *
   * @throws \RuntimeException
   *   Thrown when the 'provider' config value is absent or empty, which
   *   indicates a broken or missing module configuration. The caller should
   *   let this propagate so it is visible in logs rather than being swallowed
   *   by a silent fallback.
   *
   * @return string
   *   The provider key, e.g. 'piper'.
   */
  private function resolveProviderKey(): string {
    $key = $this->configFactory->get('hear_me.settings')->get('provider');
    if (!$key) {
      throw new \RuntimeException(
        'HearMe: the "provider" key is missing from hear_me.settings configuration. ' .
        'Re-install the module or set the value at /admin/config/media/hear-me.'
      );
    }
    return $key;
  }

  /**
   * Builds the canonical file URI for a TTS audio file.
   *
   * Delegates to the file helper so callers can reach this via the central
   * service without needing to inject the helper separately.
   *
   * @param string $text
   * @param string $lang
   * @param string $providerKey
   * @param string $extension
   *
   * @return string
   */
  public function buildTtsUri(string $text, string $lang, string $providerKey, string $extension): string {
    return $this->fileHelper->buildTtsUri($text, $lang, $providerKey, $extension);
  }

  /**
   * Returns the default language for the currently active provider.
   *
   * Reads from the active provider's own config namespace so that each
   * provider can independently define its default language.
   *
   * @throws \RuntimeException
   *   If the active provider key cannot be resolved from config.
   *
   * @return string
   *   Language code, e.g. 'en'.
   */
  public function getDefaultLang(): string {
    $providerKey = $this->resolveProviderKey();
    $lang = $this->configFactory->get('hear_me.provider.' . $providerKey)->get('default_lang');
    if (!$lang) {
      throw new \RuntimeException(
        sprintf(
          'HearMe: the "default_lang" key is missing from hear_me.provider.%s configuration.',
          $providerKey
        )
      );
    }
    return $lang;
  }

  /**
   * Returns the supported language codes for the currently active provider.
   *
   * Delegates to the active provider's getSupportedLanguages(). If the
   * configured provider key does not match any registered plugin, a warning
   * is logged and an array containing only the default language is returned
   * so that callers (e.g. the settings form language selector) degrade
   * gracefully.
   *
   * @throws \RuntimeException
   *   If the active provider key cannot be resolved from config.
   *
   * @return string[]
   *   Array of language codes, e.g. ['en', 'uk'].
   */
  public function getSupportedLanguages(): array {
    $providerKey = $this->resolveProviderKey();
    $providers   = $this->getProviders();

    if (isset($providers[$providerKey])) {
      return $providers[$providerKey]->getSupportedLanguages();
    }

    $this->logger->warning(
      'HearMe: provider "@key" is configured but not registered. ' .
      'The module that provides it may have been disabled. ' .
      'Update the provider at /admin/config/media/hear-me.',
      ['@key' => $providerKey]
    );

    return [$this->getDefaultLang()];
  }

  /**
   * Synthesizes text to speech using the configured provider.
   *
   * Checks the file-based cache before delegating to the provider. When the
   * provider returns a TtsSynthesisResult DTO, this method creates (or reuses)
   * the managed File entity and wraps it in a Media entity.
   *
   * Returns NULL (rather than throwing) on synthesis failure so that callers
   * such as the controller and the queue worker can handle the absence of
   * audio gracefully. Failures are logged as errors so they surface in the
   * Drupal watchdog.
   *
   * @param string $text
   *   The text to synthesize.
   * @param string $lang
   *   Language code (e.g., 'en', 'uk').
   *
   * @throws \RuntimeException
   *   If the active provider key cannot be resolved from config.
   *
   * @return \Drupal\media\Entity\Media|null
   *   Media entity containing the audio file, or NULL on failure.
   */
  public function synthesize(string $text, string $lang): ?Media {
    [$media] = $this->synthesizeWithBytes($text, $lang);
    return $media;
  }

  /**
   * Synthesizes and returns both the Media entity and raw audio bytes.
   *
   * This is the single internal code path for all synthesis. On a cache hit
   * the bytes are read from disk (the file already exists). On a cache miss
   * the bytes come directly from TtsSynthesisResult::$bytes, avoiding a
   * second disk read.
   *
   * @param string $text
   *   The text to synthesize.
   * @param string $lang
   *   Language code (e.g., 'en', 'uk').
   *
   * @throws \RuntimeException
   *   If the active provider key cannot be resolved from config.
   *
   * @return array{0: \Drupal\media\Entity\Media|null, 1: \Drupal\hear_me\TtsAudioResult|null}
   *   A two-element array: [Media|null, audio result|null].
   */
  private function synthesizeWithBytes(string $text, string $lang): array {
    $config      = $this->configFactory->get('hear_me.settings');
    $providerKey = $this->resolveProviderKey();

    $providers = $this->getProviders();
    if (!isset($providers[$providerKey])) {
      $this->logger->error(
        'HearMe: provider "@key" is configured but not registered; synthesis aborted. ' .
        'The module that provides it may have been disabled. ' .
        'Update the provider at /admin/config/media/hear-me.',
        ['@key' => $providerKey]
      );
      return [NULL, NULL];
    }

    $provider = $providers[$providerKey];

    if ($config->get('cache_enabled')) {
      $mimeType = $provider->getDefaultMimeType();
      $extension = $provider->getDefaultExtension();
      $uri      = $this->buildTtsUri($text, $lang, $providerKey, $extension);
      $realpath = $this->fileSystem->realpath($uri);

      if ($realpath && file_exists($realpath)) {
        $files = $this->entityTypeManager
          ->getStorage('file')
          ->loadByProperties(['uri' => $uri]);

        if ($files) {
          $file  = reset($files);
          $media = $this->entityTypeManager
            ->getStorage('media')
            ->loadByProperties(['field_media_audio_file' => $file->id()]);

          if ($media) {
            $bytes = file_get_contents($realpath) ?: NULL;
            return [reset($media), $bytes === NULL ? NULL : new TtsAudioResult($bytes, $mimeType, $extension)];
          }

          $bytes = file_get_contents($realpath) ?: NULL;
          return [
            $this->createMediaFromUri($uri, $lang, $text),
            $bytes === NULL ? NULL : new TtsAudioResult($bytes, $mimeType, $extension),
          ];
        }
      }
    }

    $result = $provider->synthesize($text, $lang);
    if (!$result instanceof TtsSynthesisResult) {
      return [NULL, NULL];
    }

    $uri = $this->buildTtsUri($text, $lang, $providerKey, $result->extension);
    $savedUri = $this->fileSystem->saveData($result->bytes, $uri, FileExists::Replace);
    if (!$savedUri) {
      $this->logger->error(
        'HearMe: failed to save synthesized audio data to @uri.',
        ['@uri' => $uri]
      );
      return [NULL, NULL];
    }

    $media = $this->createMediaFromUri($savedUri, $lang, $text);
    return [$media, new TtsAudioResult($result->bytes, $result->mimeType, $result->extension)];
  }

  /**
   * Creates or reuses a managed File entity and wraps it in a Media entity.
   *
   * Separated from synthesize() for readability. Reuses an existing File
   * entity if the URI is already tracked (cache-replace scenario) to avoid
   * duplicate managed file records.
   *
   * @param string $uri
   *   URI of the saved audio file.
   * @param string $lang
   *   Language code used in the Media entity name.
   * @param string $text
   *   Original text used in the Media entity name (MD5-hashed).
   *
   * @return \Drupal\media\Entity\Media|null
   *   The existing or saved Media entity, or NULL if entity creation fails.
   */
  private function createMediaFromUri(string $uri, string $lang, string $text): ?Media {
    $fileStorage   = $this->entityTypeManager->getStorage('file');
    $existingFiles = $fileStorage->loadByProperties(['uri' => $uri]);

    if ($existingFiles) {
      $fileEntity = reset($existingFiles);
    }
    else {
      $fileEntity = File::create([
        'uri'    => $uri,
        'status' => 1,
      ]);
      $fileEntity->save();
    }

    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $existingMedia = $mediaStorage->loadByProperties([
      'field_media_audio_file' => $fileEntity->id(),
    ]);
    if ($existingMedia) {
      return reset($existingMedia);
    }

    $media = Media::create([
      'bundle'                 => 'audio',
      'name'                   => 'TTS-' . $lang . '-' . md5($text),
      'field_media_audio_file' => [
        'target_id' => $fileEntity->id(),
      ],
    ]);
    $media->save();

    return $media;
  }

  /**
   * Returns audio bytes and format metadata for the given text and language.
   *
   * Synthesizes if necessary (cache miss) and returns audio bytes. On a
   * fresh synthesis the bytes come directly from TtsSynthesisResult::$bytes
   * so no second disk read is performed. On a cache hit the file already
   * exists on disk and is read once inside synthesizeWithBytes().
   *
   * @param string $text
   *   The text to synthesize.
   * @param string $lang
   *   Language code (e.g., 'en', 'uk').
   *
   * @return \Drupal\hear_me\TtsAudioResult|null
   *   Audio result, or NULL if synthesis failed.
   */
  public function getAudio(string $text, string $lang): ?TtsAudioResult {
    [, $audio] = $this->synthesizeWithBytes($text, $lang);
    return $audio;
  }

  /**
   * Returns the raw audio bytes for the given text and language.
   *
   * @return string|null
   *   Raw audio file contents, or NULL if synthesis failed.
   */
  public function getAudioBytes(string $text, string $lang): ?string {
    $audio = $this->getAudio($text, $lang);
    return $audio?->bytes;
  }

  /**
   * Attaches a synthesized media entity to a node field.
   *
   * Reads the target field name from configuration so this logic is
   * centralised and the queue worker stays free of config and entity
   * manager knowledge beyond what the service already owns.
   *
   * @param int $nid
   *   Node ID to attach the audio to.
   * @param \Drupal\media\Entity\Media $media
   *   The media entity to attach.
   */
  public function attachMediaToNode(int $nid, Media $media): void {
    $fieldName = $this->configFactory->get('hear_me.settings')->get('tts_audio_field')
      ?? 'field_tts_audio';

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node || !$node->hasField($fieldName)) {
      $this->logger->warning(
        'HearMe: cannot attach audio to node @nid — node not found or field "@field" does not exist.',
        ['@nid' => $nid, '@field' => $fieldName]
      );
      return;
    }

    $node->set($fieldName, $media->id());
    $node->save();
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
