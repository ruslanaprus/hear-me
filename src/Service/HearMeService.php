<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\PrivateKey;
use Drupal\hear_me\Exception\PersistentSynthesisUnavailableException;
use Drupal\hear_me\TtsAudioResult;
use Drupal\hear_me\TtsSynthesisResult;
use Drupal\media\MediaInterface;

/**
 * Coordinates runtime and persistent TTS synthesis operations.
 */
class HearMeService {

  protected TtsProviderResolver $providerResolver;
  protected AudioMediaFactory $audioMediaFactory;
  protected TtsCacheManager $cacheManager;
  protected HearMeInputValidator $inputValidator;
  protected LockBackendInterface $lock;
  protected \Psr\Log\LoggerInterface $logger;
  protected PrivateKey $privateKey;

  public function __construct(
    TtsProviderResolver $providerResolver,
    AudioMediaFactory $audioMediaFactory,
    TtsCacheManager $cacheManager,
    HearMeInputValidator $inputValidator,
    LockBackendInterface $lock,
    LoggerChannelFactoryInterface $loggerFactory,
    PrivateKey $privateKey,
  ) {
    $this->providerResolver  = $providerResolver;
    $this->audioMediaFactory = $audioMediaFactory;
    $this->cacheManager      = $cacheManager;
    $this->inputValidator    = $inputValidator;
    $this->lock              = $lock;
    $this->logger            = $loggerFactory->get('hear_me');
    $this->privateKey        = $privateKey;
  }

  /**
   * Synthesizes persistent audio for pre-generation workflows.
   *
   * Runtime playback should use getAudio(). This method intentionally creates
   * or reuses a Media entity because queue-based pre-generation attaches audio
   * to content.
   *
   * @throws \Drupal\hear_me\Exception\PersistentSynthesisUnavailableException
   *   When provider discovery or persistent cache storage is temporarily
   *   unavailable.
   */
  public function synthesize(string $text, string $lang, ?string $providerId = NULL): ?MediaInterface {
    $audio = $this->generateAudio($text, $lang, 'entity', TRUE, $providerId);
    if ($audio === NULL) {
      return NULL;
    }
    if ($audio->uri === NULL) {
      throw new PersistentSynthesisUnavailableException('Synthesized audio could not be persisted.');
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
   * Builds an opaque cache token for a trusted runtime source.
   */
  public function buildCacheToken(string $text, string $lang, string $source, ?string $providerId = NULL): string {
    return hash_hmac('sha256', $this->buildCacheTokenPayload($text, $lang, $source, $providerId), $this->privateKey->get());
  }

  /**
   * Returns a trusted runtime source, downgrading invalid inline tokens.
   */
  public function getTrustedRuntimeSource(string $text, string $lang, string $source, ?string $cacheToken, ?string $providerId = NULL): string {
    $source = $this->cacheManager->normalizeSource($source);
    if ($source === 'inline' && !$this->validateCacheToken($text, $lang, $source, $cacheToken, $providerId)) {
      return 'adhoc';
    }

    return $source;
  }

  private function generateAudio(string $text, string $lang, string $source, bool $forcePersistent, ?string $providerId = NULL): ?TtsAudioResult {
    if (mb_strlen($text) > $this->inputValidator->getMaxTextLength()) {
      $this->logger->warning('HearMe: synthesis input exceeded the configured text length limit.');
      return NULL;
    }

    $providerKey = $providerId ?? $this->providerResolver->getActiveProviderId();
    $provider = $this->providerResolver->getProvider($providerKey);
    if ($provider === NULL) {
      if ($forcePersistent) {
        throw new PersistentSynthesisUnavailableException('The persistent synthesis provider is unavailable.');
      }
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
          if ($forcePersistent) {
            throw new PersistentSynthesisUnavailableException('Persistent synthesis is already in progress.');
          }
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
