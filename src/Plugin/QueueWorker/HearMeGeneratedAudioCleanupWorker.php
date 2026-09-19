<?php

namespace Drupal\hear_me\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockAcquiringException;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Service\NodeAudioAttacher;
use Drupal\hear_me\Service\TtsCacheManager;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retries generated audio cleanup after lifecycle lock contention.
 */
#[QueueWorker(
  id: 'hear_me_generated_audio_cleanup',
  title: new TranslatableMarkup('HearMe Generated Audio Cleanup'),
  cron: ['time' => 30],
)]
final class HearMeGeneratedAudioCleanupWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly NodeAudioAttacher $nodeAudioAttacher,
    protected readonly TtsCacheManager $cacheManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
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
      $container->get('entity_type.manager'),
      $container->get('hear_me.node_audio_attacher'),
      $container->get('hear_me.cache_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $mediaId = is_array($data) ? (int) ($data['media_id'] ?? 0) : 0;
    $token = is_array($data) ? (string) ($data['token'] ?? '') : '';
    if ($mediaId <= 0 || $token === '') {
      return;
    }
    $rawFileIds = is_array($data['file_ids'] ?? NULL) ? $data['file_ids'] : [];
    $fileIds = array_values(array_unique(array_filter(
      array_map('intval', $rawFileIds),
      static fn(int $fileId): bool => $fileId > 0,
    )));

    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $mediaStorage->resetCache([$mediaId]);
    $media = $mediaStorage->load($mediaId);
    if ($media instanceof MediaInterface && !$this->nodeAudioAttacher->tryCleanupOrphanedGeneratedAudio($media)) {
      throw new DelayedRequeueException(60, 'HearMe generated media is busy; cleanup will be retried.');
    }

    foreach ($fileIds as $fileId) {
      if (!$this->cacheManager->deletePersistentGeneratedFileIfUnused($fileId)) {
        throw new DelayedRequeueException(60, 'HearMe generated file cleanup failed; cleanup will be retried.');
      }
    }

    try {
      $this->nodeAudioAttacher->completeOrphanedGeneratedAudioCleanup($mediaId, $token);
    }
    catch (LockAcquiringException $e) {
      throw new DelayedRequeueException(60, 'HearMe generated media cleanup state is busy; cleanup will be retried.', 0, $e);
    }
  }

}
