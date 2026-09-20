<?php

namespace Drupal\hear_me\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Lock\LockAcquiringException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Exception\PersistentSynthesisUnavailableException;
use Drupal\hear_me\Service\HearMeNodeAudioQueue;
use Drupal\hear_me\Service\HearMeService;
use Drupal\hear_me\Service\NodeAudioAttacher;
use Drupal\hear_me\Service\TtsProviderResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued TTS synthesis jobs.
 */
#[QueueWorker(
  id: 'hear_me_tts',
  title: new TranslatableMarkup('HearMe TTS Queue Worker'),
  cron: ['time' => 30],
)]
class HearMeQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  protected HearMeService $ttsService;

  protected NodeAudioAttacher $nodeAudioAttacher;

  protected HearMeNodeAudioQueue $nodeAudioQueue;

  protected TtsProviderResolver $providerResolver;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    HearMeService $ttsService,
    NodeAudioAttacher $nodeAudioAttacher,
    HearMeNodeAudioQueue $nodeAudioQueue,
    TtsProviderResolver $providerResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->ttsService = $ttsService;
    $this->nodeAudioAttacher = $nodeAudioAttacher;
    $this->nodeAudioQueue = $nodeAudioQueue;
    $this->providerResolver = $providerResolver;
  }

  public static function create(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
  ): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('hear_me.service'),
      $container->get('hear_me.node_audio_attacher'),
      $container->get('hear_me.node_audio_queue'),
      $container->get('hear_me.provider_resolver'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data)) {
      return;
    }

    $nid = (int) ($data['nid'] ?? 0);
    $queuedHash = (string) ($data['content_hash'] ?? '');
    $token = (string) ($data['token'] ?? '');
    if ($nid <= 0 || $queuedHash === '' || $token === '') {
      return;
    }

    if (!$this->nodeAudioQueue->isCurrentQueueItem($nid, $queuedHash, $token)) {
      return;
    }

    try {
      $providerId = $this->providerResolver->getActiveProviderId();
    }
    catch (\RuntimeException $e) {
      throw new DelayedRequeueException(60, 'HearMe provider discovery is unavailable; the queue item will be retried.', 0, $e);
    }

    try {
      $current = $this->nodeAudioQueue->buildCurrentQueueItem($nid, $providerId);
    }
    catch (PersistentSynthesisUnavailableException $e) {
      throw new DelayedRequeueException(60, 'HearMe provider discovery is unavailable; the queue item will be retried.', 0, $e);
    }
    if ($current === NULL) {
      $this->clearQueuedHash($nid, $queuedHash, $token);
      return;
    }

    if (!hash_equals($current['content_hash'], $queuedHash)) {
      $this->clearQueuedHash($nid, $queuedHash, $token);
      return;
    }

    try {
      $media = $this->ttsService->synthesize($current['text'], $current['lang'], $providerId);
    }
    catch (PersistentSynthesisUnavailableException $e) {
      throw new DelayedRequeueException(60, 'HearMe could not persist synthesized audio; the queue item will be retried.', 0, $e);
    }
    if (!$media) {
      $failures = $this->nodeAudioQueue->recordSynthesisFailure($nid, $queuedHash, $token);
      if ($failures === NULL) {
        throw new DelayedRequeueException(60, 'HearMe queue state is busy; the queue item will be retried.');
      }
      if ($failures === 0 || $failures >= HearMeNodeAudioQueue::MAX_SYNTHESIS_FAILURES) {
        return;
      }
      throw new DelayedRequeueException(60, 'HearMe synthesis failed; the queue item will be retried.');
    }

    try {
      $latest = $this->nodeAudioQueue->buildCurrentQueueItem($nid, $providerId);
    }
    catch (PersistentSynthesisUnavailableException $e) {
      throw new DelayedRequeueException(60, 'HearMe provider discovery is unavailable; the queue item will be retried.', 0, $e);
    }
    if ($latest !== NULL && hash_equals($latest['content_hash'], $queuedHash)) {
      try {
        if (!$this->nodeAudioAttacher->attach($nid, $media, $queuedHash, $token)) {
          $this->nodeAudioAttacher->cleanupOrphanedGeneratedAudio($media);
        }
      }
      catch (LockAcquiringException $e) {
        throw new DelayedRequeueException(60, 'HearMe generated media is busy; the queue item will be retried.', 0, $e);
      }
      catch (EntityStorageException $e) {
        throw new DelayedRequeueException(60, 'HearMe generated media changed; the queue item will be retried.', 0, $e);
      }
      return;
    }

    $this->nodeAudioAttacher->cleanupOrphanedGeneratedAudio($media);
    $this->clearQueuedHash($nid, $queuedHash, $token);
  }

  /**
   * Clears marker ownership without allowing lock contention to lose the job.
   */
  private function clearQueuedHash(int $nid, string $contentHash, string $token): void {
    try {
      $this->nodeAudioQueue->clearQueuedHash($nid, $contentHash, $token);
    }
    catch (LockAcquiringException $e) {
      throw new DelayedRequeueException(60, 'HearMe queue state is busy; the queue item will be retried.', 0, $e);
    }
  }

}
