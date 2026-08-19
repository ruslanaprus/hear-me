<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\PrivateKey;
use Drupal\hear_me\TtsAudioResult;
use Drupal\hear_me\TtsSynthesisResult;
use Drupal\media\MediaInterface;

class HearMeService {

  protected TtsProviderResolver $providerResolver;
  protected AudioMediaFactory $audioMediaFactory;
  protected TtsFileHelperInterface $fileHelper;
  protected TtsCacheManager $cacheManager;
  protected LockBackendInterface $lock;
  protected \Psr\Log\LoggerInterface $logger;
  protected PrivateKey $privateKey;

  public function __construct(
    TtsProviderResolver $providerResolver,
    AudioMediaFactory $audioMediaFactory,
    TtsFileHelperInterface $fileHelper,
    TtsCacheManager $cacheManager,
    LockBackendInterface $lock,
    LoggerChannelFactoryInterface $loggerFactory,
    PrivateKey $privateKey,
  ) {
    $this->providerResolver  = $providerResolver;
    $this->audioMediaFactory = $audioMediaFactory;
    $this->fileHelper        = $fileHelper;
    $this->cacheManager      = $cacheManager;
    $this->lock              = $lock;
    $this->logger            = $loggerFactory->get('hear_me');
    $this->privateKey        = $privateKey;
  }

  /**
   * Builds the canonical file URI for a TTS audio file.
   */
  public function buildTtsUri(string $text, string $lang, string $providerKey, string $extension): string {
    return $this->fileHelper->buildTtsUri($text, $lang, $providerKey, $extension);
  }

  /**
   * Synthesizes persistent audio for pre-generation workflows.
   *
   * Runtime playback should use getAudio(). This method intentionally creates
   * or reuses a Media entity because queue-based pre-generation attaches audio
   * to content.
   */
  public function synthesize(string $text, string $lang, ?string $providerId = NULL): ?MediaInterface {
    $audio = $this->generateAudio($text, $lang, 'entity', TRUE, $providerId);
    if ($audio === NULL || $audio->uri === NULL) {
      return NULL;
    }

    return $this->audioMediaFactory->createFromUri($audio->uri, $lang, $text);
  }

  /**
   * Returns audio bytes and format metadata for the given text and language.
   */
  public function getAudio(string $text, string $lang, string $source = 'adhoc', ?string $providerId = NULL): ?TtsAudioResult {
    return $this->generateAudio($text, $lang, $source, FALSE, $providerId);
  }

  /**
   * Returns the raw audio bytes for the given text and language.
   */
  public function getAudioBytes(string $text, string $lang, ?string $providerId = NULL): ?string {
    $audio = $this->getAudio($text, $lang, 'adhoc', $providerId);
    return $audio?->bytes;
  }

  public function buildCacheToken(string $text, string $lang, string $source, ?string $providerId = NULL): string {
    return hash_hmac('sha256', $this->buildCacheTokenPayload($text, $lang, $source, $providerId), $this->privateKey->get());
  }

  public function getTrustedRuntimeSource(string $text, string $lang, string $source, ?string $cacheToken, ?string $providerId = NULL): string {
    $source = $this->cacheManager->normalizeSource($source);
    if ($source === 'inline' && !$this->validateCacheToken($text, $lang, $source, $cacheToken, $providerId)) {
      return 'adhoc';
    }

    return $source;
  }

  private function generateAudio(string $text, string $lang, string $source, bool $forcePersistent, ?string $providerId = NULL): ?TtsAudioResult {
    $providerKey = $providerId ?? $this->providerResolver->getActiveProviderId();
    $provider = $this->providerResolver->getProvider($providerKey);
    if ($provider === NULL) {
      return NULL;
    }

    $source = $this->cacheManager->normalizeSource($source);
    $extension = $provider->getDefaultExtension();
    $providerConfigHash = $this->cacheManager->buildProviderConfigHash(
      $this->providerResolver->getProviderConfigurationHashInput($providerKey)
    );
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

  private function validateCacheToken(string $text, string $lang, string $source, ?string $cacheToken, ?string $providerId = NULL): bool {
    if (!is_string($cacheToken) || !preg_match('/^[a-f0-9]{64}$/', $cacheToken)) {
      return FALSE;
    }

    return hash_equals($this->buildCacheToken($text, $lang, $source, $providerId), $cacheToken);
  }

  private function buildCacheTokenPayload(string $text, string $lang, string $source, ?string $providerId = NULL): string {
    $providerKey = $providerId ?? $this->providerResolver->getActiveProviderId();
    $providerConfigHash = $this->cacheManager->buildProviderConfigHash(
      $this->providerResolver->getProviderConfigurationHashInput($providerKey)
    );
    return implode("\0", [
      'v1',
      $this->cacheManager->normalizeSource($source),
      hash('sha256', $this->normalizeTokenText($text)),
      strtolower($lang),
      $providerKey,
      $providerConfigHash,
    ]);
  }

  private function normalizeTokenText(string $text): string {
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/[ \t\r\n]+/u', ' ', $text) ?? $text;
    return trim($text);
  }

}
