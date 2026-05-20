<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\PrivateKey;
use Drupal\hear_me\TtsAudioResult;
use Drupal\hear_me\TtsSynthesisResult;
use Drupal\media\Entity\Media;

class HearMeService {

  protected ConfigFactoryInterface $configFactory;
  protected iterable $providers;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected TtsFileHelperInterface $fileHelper;
  protected TtsCacheManager $cacheManager;
  protected LockBackendInterface $lock;
  protected \Psr\Log\LoggerInterface $logger;
  protected PrivateKey $privateKey;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    iterable $providers,
    EntityTypeManagerInterface $entityTypeManager,
    TtsFileHelperInterface $fileHelper,
    TtsCacheManager $cacheManager,
    LockBackendInterface $lock,
    LoggerChannelFactoryInterface $loggerFactory,
    PrivateKey $privateKey,
  ) {
    $this->configFactory     = $configFactory;
    $this->providers         = $providers;
    $this->entityTypeManager = $entityTypeManager;
    $this->fileHelper        = $fileHelper;
    $this->cacheManager      = $cacheManager;
    $this->lock              = $lock;
    $this->logger            = $loggerFactory->get('hear_me');
    $this->privateKey        = $privateKey;
  }

  /**
   * Resolves the active TTS provider key from configuration.
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

  public function getProviderKey(): string {
    return $this->resolveProviderKey();
  }

  /**
   * Builds the canonical file URI for a TTS audio file.
   */
  public function buildTtsUri(string $text, string $lang, string $providerKey, string $extension): string {
    return $this->fileHelper->buildTtsUri($text, $lang, $providerKey, $extension);
  }

  /**
   * Returns the default language for the currently active provider.
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
   * Synthesizes persistent audio for pre-generation workflows.
   *
   * Runtime playback should use getAudio(). This method intentionally creates
   * or reuses a Media entity because queue-based pre-generation attaches audio
   * to content.
   */
  public function synthesize(string $text, string $lang): ?Media {
    $audio = $this->generateAudio($text, $lang, 'entity', TRUE);
    if ($audio === NULL || $audio->uri === NULL) {
      return NULL;
    }

    return $this->createMediaFromUri($audio->uri, $lang, $text);
  }

  /**
   * Returns audio bytes and format metadata for the given text and language.
   */
  public function getAudio(string $text, string $lang, string $source = 'adhoc'): ?TtsAudioResult {
    return $this->generateAudio($text, $lang, $source, FALSE);
  }

  /**
   * Returns the raw audio bytes for the given text and language.
   */
  public function getAudioBytes(string $text, string $lang): ?string {
    $audio = $this->getAudio($text, $lang);
    return $audio?->bytes;
  }

  public function buildCacheToken(string $text, string $lang, string $source): string {
    return hash_hmac('sha256', $this->buildCacheTokenPayload($text, $lang, $source), $this->privateKey->get());
  }

  public function getTrustedRuntimeSource(string $text, string $lang, string $source, ?string $cacheToken): string {
    $source = $this->cacheManager->normalizeSource($source);
    if ($source === 'inline' && !$this->validateCacheToken($text, $lang, $source, $cacheToken)) {
      return 'adhoc';
    }

    return $source;
  }

  private function generateAudio(string $text, string $lang, string $source, bool $forcePersistent): ?TtsAudioResult {
    $providerKey = $this->resolveProviderKey();
    $providers = $this->getProviders();
    if (!isset($providers[$providerKey])) {
      $this->logger->error(
        'HearMe: provider "@key" is configured but not registered; synthesis aborted. ' .
        'The module that provides it may have been disabled. ' .
        'Update the provider at /admin/config/media/hear-me.',
        ['@key' => $providerKey]
      );
      return NULL;
    }

    $provider = $providers[$providerKey];
    $source = $this->cacheManager->normalizeSource($source);
    $extension = $provider->getDefaultExtension();
    $providerConfigHash = $this->getProviderConfigHash($providerKey);
    $cid = $this->cacheManager->buildCacheId($text, $lang, $providerKey, $extension, $source, $providerConfigHash);
    $uri = $this->cacheManager->buildUri($cid, $extension, $source);
    $ttl = $forcePersistent ? 0 : $this->cacheManager->getTtlForSource($source);
    $shouldPersist = $forcePersistent || $this->cacheManager->isRuntimeCacheEnabled($source);
    $lockName = 'hear_me_tts:' . $cid;
    $lockAcquired = FALSE;

    if ($shouldPersist) {
      $cached = $this->cacheManager->getCachedAudio($cid, $ttl);
      if ($cached !== NULL) {
        return $cached;
      }

      $lockAcquired = $this->lock->acquire($lockName, 60.0);
      if (!$lockAcquired) {
        $this->lock->wait($lockName, 30);
        $cached = $this->cacheManager->getCachedAudio($cid, $ttl);
        if ($cached !== NULL) {
          return $cached;
        }

        $lockAcquired = $this->lock->acquire($lockName, 60.0);
        if (!$lockAcquired) {
          $this->logger->warning('HearMe: synthesis for cache item @cid is already in progress.', ['@cid' => $cid]);
          return NULL;
        }
      }

      $cached = $this->cacheManager->getCachedAudio($cid, $ttl);
      if ($cached !== NULL) {
        $this->lock->release($lockName);
        return $cached;
      }
    }

    try {
      $result = $provider->synthesize($text, $lang);
      if (!$result instanceof TtsSynthesisResult) {
        return NULL;
      }

      if (!$shouldPersist) {
        return new TtsAudioResult($result->bytes, $result->mimeType, $result->extension);
      }

      return $this->cacheManager->saveAudio(
        $cid,
        $uri,
        $source,
        $providerKey,
        $lang,
        $text,
        $providerConfigHash,
        $result,
        $ttl,
      );
    }
    finally {
      if ($lockAcquired) {
        $this->lock->release($lockName);
      }
    }
  }

  private function getProviderConfigHash(string $providerKey): string {
    $providerConfig = $this->configFactory->get('hear_me.provider.' . $providerKey)->getRawData();
    return $this->cacheManager->buildProviderConfigHash($providerConfig);
  }

  private function validateCacheToken(string $text, string $lang, string $source, ?string $cacheToken): bool {
    if (!is_string($cacheToken) || !preg_match('/^[a-f0-9]{64}$/', $cacheToken)) {
      return FALSE;
    }

    return hash_equals($this->buildCacheToken($text, $lang, $source), $cacheToken);
  }

  private function buildCacheTokenPayload(string $text, string $lang, string $source): string {
    $providerKey = $this->resolveProviderKey();
    return implode("\0", [
      'v1',
      $this->cacheManager->normalizeSource($source),
      hash('sha256', $this->normalizeTokenText($text)),
      strtolower($lang),
      $providerKey,
      $this->getProviderConfigHash($providerKey),
    ]);
  }

  private function normalizeTokenText(string $text): string {
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/[ \t\r\n]+/u', ' ', $text) ?? $text;
    return trim($text);
  }

  /**
   * Creates or reuses a managed File entity and wraps it in a Media entity.
   */
  private function createMediaFromUri(string $uri, string $lang, string $text): ?Media {
    $fileStorage   = $this->entityTypeManager->getStorage('file');
    $existingFiles = $fileStorage->loadByProperties(['uri' => $uri]);

    if ($existingFiles) {
      $fileEntity = reset($existingFiles);
    }
    else {
      $fileEntity = $fileStorage->create([
        'uri'    => $uri,
        'status' => 1,
      ]);
      $fileEntity->save();
    }

    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $existingMedia = $mediaStorage->loadByProperties([
      'field_hear_me_audio_file' => $fileEntity->id(),
    ]);
    if ($existingMedia) {
      return reset($existingMedia);
    }

    $media = Media::create([
      'bundle' => 'hear_me_audio',
      'name' => 'TTS-' . $lang . '-' . md5($text),
      'field_hear_me_audio_file' => [
        'target_id' => $fileEntity->id(),
      ],
    ]);
    $media->save();

    return $media;
  }

  /**
   * Attaches a synthesized media entity to a node field.
   */
  public function attachMediaToNode(int $nid, Media $media): void {
    $fieldName = $this->configFactory->get('hear_me.settings')->get('tts_audio_field')
      ?? 'field_tts_audio';

    $node = $this->entityTypeManager->getStorage('node')->load($nid);
    if (!$node || !$node->hasField($fieldName)) {
      $this->logger->warning(
        'HearMe: cannot attach audio to node @nid; node not found or field "@field" does not exist.',
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
