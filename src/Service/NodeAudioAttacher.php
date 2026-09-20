<?php

namespace Drupal\hear_me\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockAcquiringException;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Attaches generated audio Media entities to nodes.
 *
 * @internal
 */
final class NodeAudioAttacher {

  private const CLEANUP_MARKER_TTL = 86400;

  private const MEDIA_LOCK_TTL = 300.0;

  private \Psr\Log\LoggerInterface $logger;

  private KeyValueStoreInterface $cleanupStore;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Connection $database,
    private readonly LockBackendInterface $lock,
    private readonly QueueFactory $queueFactory,
    KeyValueFactoryInterface $keyValueFactory,
    private readonly TimeInterface $time,
    private readonly HearMeNodeAudioQueue $nodeAudioQueue,
    private readonly TtsCacheManager $cacheManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('hear_me');
    $this->cleanupStore = $keyValueFactory->get('hear_me.generated_audio_cleanup');
  }

  /**
   * Attaches audio Media to a node when replacement policy permits it.
   */
  public function attach(
    int $nid,
    MediaInterface $media,
    ?string $expectedContentHash = NULL,
    ?string $queueToken = NULL,
  ): bool {
    $mediaId = (int) $media->id();
    if ($mediaId <= 0) {
      $this->logger->warning('HearMe: cannot attach unsaved audio media to node @nid.', ['@nid' => $nid]);
      return FALSE;
    }

    $lockName = 'hear_me.generated_media.' . $mediaId;
    if (!$this->lock->acquire($lockName, self::MEDIA_LOCK_TTL)) {
      throw new LockAcquiringException(sprintf('Generated audio media %d is busy.', $mediaId));
    }

    try {
      $mediaStorage = $this->entityTypeManager->getStorage('media');
      $mediaStorage->resetCache([$mediaId]);
      $storedMedia = $mediaStorage->load($mediaId);
      if (!$storedMedia instanceof MediaInterface) {
        throw new EntityStorageException(sprintf('Generated audio media %d no longer exists.', $mediaId));
      }

      if ($expectedContentHash !== NULL && $queueToken !== NULL) {
        $result = $this->nodeAudioQueue->processCurrentQueueItem(
          $nid,
          $expectedContentHash,
          $queueToken,
          fn(): bool => $this->attachInTransaction($nid, $storedMedia, $expectedContentHash, $queueToken),
        );
        if ($result === NULL) {
          throw new LockAcquiringException('HearMe queue ownership is busy.');
        }
        return $result;
      }

      return $this->attachInTransaction($nid, $storedMedia, $expectedContentHash, $queueToken);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Attaches stored Media in a node-row transaction.
   */
  private function attachInTransaction(
    int $nid,
    MediaInterface $media,
    ?string $expectedContentHash,
    ?string $queueToken,
  ): bool {
    $transaction = $this->database->startTransaction();
    try {
      $this->database->select('node', 'n')
        ->fields('n', ['nid'])
        ->condition('nid', $nid)
        ->forUpdate()
        ->execute()
        ->fetchField();
      return $this->attachLocked($nid, $media, $expectedContentHash, $queueToken);
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    finally {
      unset($transaction);
    }
  }

  /**
   * Attaches stored Media while holding its lifecycle lock.
   */
  private function attachLocked(
    int $nid,
    MediaInterface $media,
    ?string $expectedContentHash,
    ?string $queueToken,
  ): bool {
    $config = $this->configFactory->get('hear_me.settings');
    $fieldName = $config->get('tts_audio_field') ?? 'field_tts_audio';
    $replaceGenerated = $config->get('replace_existing_generated_audio') ?? TRUE;
    $overwriteManual = $config->get('overwrite_manual_audio') ?? FALSE;
    $mediaId = (int) $media->id();
    $nodeStorage = $this->entityTypeManager->getStorage('node');
    $nodeStorage->resetCache([$nid]);
    $node = $nodeStorage->load($nid);
    if (!$node instanceof NodeInterface || !$node->hasField($fieldName)) {
      $this->logger->warning(
        'HearMe: cannot attach audio to node @nid; node not found or field "@field" does not exist.',
        ['@nid' => $nid, '@field' => $fieldName]
      );
      return FALSE;
    }

    if ($expectedContentHash !== NULL && $queueToken !== NULL) {
      if (!$this->nodeAudioQueue->isCurrentQueueItem(
        $nid,
        $expectedContentHash,
        $queueToken,
      )) {
        return FALSE;
      }
    }

    if ($expectedContentHash !== NULL) {
      $this->entityTypeManager->getAccessControlHandler('node')->resetCache();
      $current = $this->nodeAudioQueue->buildQueueItem($node);
      if ($current === NULL || !hash_equals($current['content_hash'], $expectedContentHash)) {
        return FALSE;
      }
    }

    $field = $node->get($fieldName);
    $fieldDefinition = $field->getFieldDefinition();
    $fieldStorage = $fieldDefinition->getFieldStorageDefinition();
    if ($fieldDefinition->getType() !== 'entity_reference' || $fieldStorage->getSetting('target_type') !== 'media') {
      $this->logger->warning(
        'HearMe: cannot attach audio to node @nid; field "@field" is not a media reference field.',
        ['@nid' => $nid, '@field' => $fieldName]
      );
      return FALSE;
    }

    if ($this->getFieldTargetIds($field->getValue()) === [$mediaId]) {
      return TRUE;
    }

    if (!$field->isEmpty()) {
      $existingMediaIds = $this->getFieldTargetIds($field->getValue());
      $existingIsGenerated = $this->allMediaIdsAreHearMeGenerated($existingMediaIds);

      if ($existingIsGenerated && !$replaceGenerated) {
        $this->logger->notice(
          'HearMe: skipped replacing generated audio on node @nid because replacement of existing generated audio is disabled for field "@field".',
          ['@nid' => $nid, '@field' => $fieldName]
        );
        return FALSE;
      }

      if (!$existingIsGenerated && !$overwriteManual) {
        $this->logger->notice(
          'HearMe: skipped replacing audio on node @nid because field "@field" contains manually selected or unknown audio and manual overwrite is disabled.',
          ['@nid' => $nid, '@field' => $fieldName]
        );
        return FALSE;
      }
    }

    $node->set($fieldName, ['target_id' => $mediaId]);
    $violations = $node->get($fieldName)->validate();
    if ($violations->count() > 0) {
      $this->logger->error(
        'HearMe: skipped attaching audio to node @nid because field "@field" failed validation: @violations',
        [
          '@nid' => $nid,
          '@field' => $fieldName,
          '@violations' => $this->summarizeViolations($violations),
        ]
      );
      return FALSE;
    }

    if ($node instanceof RevisionableInterface) {
      $node->setNewRevision(FALSE);
    }
    $node->save();
    if ($expectedContentHash !== NULL && $queueToken !== NULL) {
      if (!$this->nodeAudioQueue->isCurrentQueueItem(
        $nid,
        $expectedContentHash,
        $queueToken,
      )) {
        throw new EntityStorageException('HearMe queue ownership changed during node attachment.');
      }
    }
    return TRUE;
  }

  /**
   * Removes only provenance-backed generated audio from an ineligible node.
   */
  public function removeGeneratedAudioReferences(NodeInterface $node): bool {
    $fieldName = $this->configFactory->get('hear_me.settings')->get('tts_audio_field') ?? 'field_tts_audio';
    if (!$this->isMediaReferenceField($node, $fieldName)) {
      return FALSE;
    }

    $retainedValues = [];
    foreach ($node->get($fieldName)->getValue() as $value) {
      $mediaId = (int) ($value['target_id'] ?? 0);
      $media = $mediaId > 0 ? $this->entityTypeManager->getStorage('media')->load($mediaId) : NULL;
      if (!$media instanceof MediaInterface || !$this->isHearMeGeneratedMedia($media)) {
        $retainedValues[] = $value;
      }
    }

    if ($retainedValues !== $node->get($fieldName)->getValue()) {
      $node->set($fieldName, $retainedValues);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks generated attachments against the current public-safe source text.
   */
  public function generatedAudioMatchesSource(NodeInterface $node, string $text, ?string $lang): bool {
    $expectedHash = hash('sha256', $text);
    $expectedLang = $lang === NULL ? NULL : strtolower($lang);
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    foreach ($this->getGeneratedMediaIds($node) as $mediaId) {
      $media = $mediaStorage->load($mediaId);
      if (!$media instanceof MediaInterface) {
        return FALSE;
      }
      foreach ($this->getFieldTargetIds($media->get('field_hear_me_audio_file')->getValue()) as $fileId) {
        $source = $this->cacheManager->getPersistentGeneratedFileSource($fileId);
        if ($source === NULL
          || $source['text_hash'] !== $expectedHash
          || ($expectedLang !== NULL && $source['langcode'] !== $expectedLang)) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * Checks whether attached generated audio must be retracted before a save.
   */
  public function shouldRetractGeneratedAudio(NodeInterface $node, ?array $publicSource): bool {
    if ($publicSource === NULL) {
      return TRUE;
    }
    if (!($this->configFactory->get('hear_me.settings')->get('replace_existing_generated_audio') ?? TRUE)) {
      return FALSE;
    }
    if ($publicSource['lang'] === NULL && ($publicSource['language_changed'] ?? FALSE)) {
      return TRUE;
    }
    return !$this->generatedAudioMatchesSource($node, $publicSource['text'], $publicSource['lang']);
  }

  /**
   * Queues a newly inserted node after its outer save transaction commits.
   */
  public function queueNodeAfterTransaction(int $nid): void {
    $queue = function (bool $success) use ($nid): void {
      if (!$success) {
        return;
      }

      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $nodeStorage->resetCache([$nid]);
      $node = $nodeStorage->load($nid);
      if ($node instanceof NodeInterface) {
        $this->entityTypeManager->getAccessControlHandler('node')->resetCache();
        if ($this->nodeAudioQueue->queueNode($node) === HearMeNodeAudioQueue::RESULT_FAILED) {
          $this->logger->warning('HearMe: automatic audio queue publication failed for node @nid. Cron will retry any retained pending marker.', [
            '@nid' => $nid,
          ]);
        }
      }
    };

    $transactionManager = $this->database->transactionManager();
    if ($transactionManager->inTransaction()) {
      $transactionManager->addPostTransactionCallback($queue);
    }
    else {
      $queue(TRUE);
    }
  }

  /**
   * Checks access again after node grants and the outer save are committed.
   */
  public function recheckEligibilityAfterTransaction(int $nid, bool $queueIfMissing = FALSE): void {
    $recheck = function (bool $success) use ($nid, $queueIfMissing): void {
      if (!$success) {
        return;
      }

      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $nodeStorage->resetCache([$nid]);
      $node = $nodeStorage->load($nid);
      if (!$node instanceof NodeInterface) {
        return;
      }

      $this->entityTypeManager->getAccessControlHandler('node')->resetCache();
      $publicSource = $this->nodeAudioQueue->buildPublicAudioSource($node);
      $generatedMediaIds = $this->getGeneratedMediaIds($node);
      if (!$generatedMediaIds) {
        $overwriteManual = $this->configFactory->get('hear_me.settings')->get('overwrite_manual_audio') ?? FALSE;
        $queueItem = $queueIfMissing ? $this->nodeAudioQueue->buildQueueItem($node) : NULL;
        if ($queueItem !== NULL && (!$this->getFieldTargetIdsForNode($node) || $overwriteManual)) {
          if ($this->nodeAudioQueue->queueItem($queueItem) === HearMeNodeAudioQueue::RESULT_FAILED) {
            $this->logger->warning('HearMe: updated audio queue publication failed for node @nid. Cron will retry any retained pending marker.', [
              '@nid' => $nid,
            ]);
          }
        }
        return;
      }
      if ($publicSource !== NULL && $this->generatedAudioMatchesSource($node, $publicSource['text'], $publicSource['lang'])) {
        return;
      }
      if ($publicSource !== NULL && !($this->configFactory->get('hear_me.settings')->get('replace_existing_generated_audio') ?? TRUE)) {
        return;
      }

      $mediaStorage = $this->entityTypeManager->getStorage('media');
      $generatedMedia = [];
      foreach ($generatedMediaIds as $mediaId) {
        $media = $mediaStorage->load($mediaId);
        if ($media instanceof MediaInterface) {
          $generatedMedia[] = $media;
        }
      }
      if ($this->removeGeneratedAudioReferences($node)) {
        if ($node instanceof RevisionableInterface) {
          $node->setNewRevision(FALSE);
        }
        $node->save();
        foreach ($generatedMedia as $media) {
          $this->cleanupOrphanedGeneratedAudio($media);
        }
      }
    };

    $transactionManager = $this->database->transactionManager();
    if ($transactionManager->inTransaction()) {
      $transactionManager->addPostTransactionCallback($recheck);
    }
    else {
      $recheck(TRUE);
    }
  }

  /**
   * Deletes generated media removed from a node when nothing else references it.
   */
  public function cleanupRemovedGeneratedAudio(NodeInterface $original, ?NodeInterface $current = NULL): bool {
    $originalIds = $this->getGeneratedMediaIds($original);
    $currentIds = $current === NULL ? [] : $this->getFieldTargetIdsForNode($current);
    $removedIds = array_values(array_diff($originalIds, $currentIds));
    if (!$removedIds) {
      return FALSE;
    }

    $cleanup = function (bool $success) use ($removedIds): void {
      if (!$success) {
        return;
      }
      $mediaStorage = $this->entityTypeManager->getStorage('media');
      foreach ($removedIds as $mediaId) {
        $media = $mediaStorage->load($mediaId);
        if ($media instanceof MediaInterface) {
          $this->cleanupOrphanedGeneratedAudio($media);
        }
      }
    };

    $transactionManager = $this->database->transactionManager();
    if ($transactionManager->inTransaction()) {
      $transactionManager->addPostTransactionCallback($cleanup);
    }
    else {
      $cleanup(TRUE);
    }

    return TRUE;
  }

  /**
   * Deletes provenance-backed generated media with no current references.
   */
  public function cleanupOrphanedGeneratedAudio(MediaInterface $media): void {
    if (!$this->tryCleanupOrphanedGeneratedAudio($media)) {
      $fileIds = $media->hasField('field_hear_me_audio_file')
        ? $this->getFieldTargetIds($media->get('field_hear_me_audio_file')->getValue())
        : [];
      $this->queueOrphanedGeneratedAudioCleanup((int) $media->id(), $fileIds);
    }
  }

  /**
   * Attempts orphan cleanup without publishing a replacement retry item.
   */
  public function tryCleanupOrphanedGeneratedAudio(MediaInterface $media): bool {
    $mediaId = (int) $media->id();
    if ($mediaId <= 0) {
      return TRUE;
    }

    $lockName = 'hear_me.generated_media.' . $mediaId;
    if (!$this->lock->acquire($lockName, self::MEDIA_LOCK_TTL)) {
      return FALSE;
    }

    try {
      $storedMedia = $this->entityTypeManager->getStorage('media')->load($mediaId);
      if (!$storedMedia instanceof MediaInterface || $this->isMediaReferenced($mediaId) || !$this->isHearMeGeneratedMedia($storedMedia)) {
        return TRUE;
      }

      $fileIds = $this->getFieldTargetIds($storedMedia->get('field_hear_me_audio_file')->getValue());
      $storedMedia->delete();
      $filesCleaned = TRUE;
      foreach ($fileIds as $fileId) {
        $filesCleaned = $this->cacheManager->deletePersistentGeneratedFileIfUnused($fileId) && $filesCleaned;
      }
      return $filesCleaned;
    }
    finally {
      $this->lock->release($lockName);
    }
    return TRUE;
  }

  /**
   * Durably retries orphan cleanup after Media lifecycle lock contention.
   */
  private function queueOrphanedGeneratedAudioCleanup(int $mediaId, array $fileIds): void {
    if ($mediaId <= 0) {
      return;
    }

    $stateKey = $this->getCleanupStateKey($mediaId);
    $fileIds = array_values(array_unique(array_filter(
      array_map('intval', $fileIds),
      static fn(int $fileId): bool => $fileId > 0,
    )));
    $this->cleanupStore->setIfNotExists($stateKey, [
      'file_ids' => $fileIds,
      'published_at' => 0,
      'token' => bin2hex(random_bytes(16)),
    ]);

    $lockName = 'hear_me.generated_media_cleanup_queue.' . $mediaId;
    if (!$this->lock->acquire($lockName, 30.0)) {
      $this->logger->warning('HearMe: cleanup publication is busy for generated audio media @media_id.', ['@media_id' => $mediaId]);
      return;
    }

    try {
      $marker = $this->cleanupStore->get($stateKey, []);
      if (!is_array($marker)) {
        return;
      }
      $marker['file_ids'] = array_values(array_unique(array_merge(
        is_array($marker['file_ids'] ?? NULL) ? array_map('intval', $marker['file_ids']) : [],
        $fileIds,
      )));
      $publishedAt = (int) ($marker['published_at'] ?? 0);
      if ($publishedAt > $this->time->getCurrentTime() - self::CLEANUP_MARKER_TTL) {
        $this->cleanupStore->set($stateKey, $marker);
        return;
      }
      $token = (string) ($marker['token'] ?? '');
      if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $marker['token'] = $token;
      }
      $this->cleanupStore->set($stateKey, $marker);
      $itemId = $this->queueFactory->get('hear_me_generated_audio_cleanup')->createItem([
        'media_id' => $mediaId,
        'file_ids' => $marker['file_ids'],
        'token' => $token,
      ]);
      if ($itemId === FALSE) {
        $this->logger->error('HearMe: could not queue cleanup for generated audio media @media_id.', ['@media_id' => $mediaId]);
        return;
      }
      $marker['published_at'] = $this->time->getCurrentTime();
      $this->cleanupStore->set($stateKey, $marker);
    }
    catch (\Throwable $e) {
      $this->logger->error('HearMe: could not queue cleanup for generated audio media @media_id: @error', [
        '@media_id' => $mediaId,
        '@error' => $e::class,
      ]);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Republishes cleanup markers left pending by queue backend failures.
   */
  public function publishPendingGeneratedAudioCleanup(): void {
    foreach ($this->cleanupStore->getAll() as $mediaId => $marker) {
      if (!is_array($marker)) {
        continue;
      }
      $publishedAt = (int) ($marker['published_at'] ?? 0);
      if ($publishedAt <= $this->time->getCurrentTime() - self::CLEANUP_MARKER_TTL) {
        $this->queueOrphanedGeneratedAudioCleanup(
          (int) $mediaId,
          is_array($marker['file_ids'] ?? NULL) ? $marker['file_ids'] : [],
        );
      }
    }
  }

  /**
   * Clears a completed cleanup marker without affecting a newer queued item.
   */
  public function completeOrphanedGeneratedAudioCleanup(int $mediaId, string $token): void {
    if ($mediaId <= 0 || $token === '') {
      return;
    }

    $lockName = 'hear_me.generated_media_cleanup_queue.' . $mediaId;
    if (!$this->lock->acquire($lockName, 30.0)) {
      throw new LockAcquiringException(sprintf('Generated media cleanup marker %d is busy.', $mediaId));
    }
    try {
      $this->clearCleanupStateIfOwned($mediaId, $token);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Clears one cleanup marker only while its queue item owns it.
   */
  private function clearCleanupStateIfOwned(int $mediaId, string $token): void {
    $stateKey = $this->getCleanupStateKey($mediaId);
    $marker = $this->cleanupStore->get($stateKey, []);
    if (is_array($marker) && hash_equals((string) ($marker['token'] ?? ''), $token)) {
      $this->cleanupStore->delete($stateKey);
    }
  }

  /**
   * Returns the durable cleanup marker key for generated Media.
   */
  private function getCleanupStateKey(int $mediaId): string {
    return (string) $mediaId;
  }

  /**
   * Returns a compact validation violation summary for logs.
   */
  private function summarizeViolations(\Traversable $violations): string {
    $messages = [];
    foreach ($violations as $violation) {
      $messages[] = trim($violation->getPropertyPath() . ': ' . $violation->getMessage());
    }

    return implode('; ', $messages);
  }

  /**
   * Extracts referenced entity IDs from an entity reference field value array.
   */
  private function getFieldTargetIds(array $fieldValues): array {
    $targetIds = [];
    foreach ($fieldValues as $fieldValue) {
      $targetId = (int) ($fieldValue['target_id'] ?? 0);
      if ($targetId > 0) {
        $targetIds[] = $targetId;
      }
    }

    return array_values(array_unique($targetIds));
  }

  /**
   * Returns target IDs from the configured node audio field.
   */
  private function getFieldTargetIdsForNode(NodeInterface $node): array {
    $fieldName = $this->configFactory->get('hear_me.settings')->get('tts_audio_field') ?? 'field_tts_audio';
    return $this->isMediaReferenceField($node, $fieldName)
      ? $this->getFieldTargetIds($node->get($fieldName)->getValue())
      : [];
  }

  /**
   * Returns provenance-backed generated Media IDs attached to a node.
   */
  private function getGeneratedMediaIds(NodeInterface $node): array {
    $generatedIds = [];
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    foreach ($this->getFieldTargetIdsForNode($node) as $mediaId) {
      $media = $mediaStorage->load($mediaId);
      if ($media instanceof MediaInterface && $this->isHearMeGeneratedMedia($media)) {
        $generatedIds[] = $mediaId;
      }
    }
    return $generatedIds;
  }

  /**
   * Checks whether the configured node field is a Media reference.
   */
  private function isMediaReferenceField(NodeInterface $node, string $fieldName): bool {
    if (!$node->hasField($fieldName)) {
      return FALSE;
    }

    $definition = $node->get($fieldName)->getFieldDefinition();
    return $definition->getType() === 'entity_reference'
      && $definition->getFieldStorageDefinition()->getSetting('target_type') === 'media';
  }

  /**
   * Checks all current entity-reference fields for a Media reference.
   */
  private function isMediaReferenced(int $mediaId): bool {
    foreach ($this->entityFieldManager->getFieldMapByFieldType('entity_reference') as $entityTypeId => $fields) {
      $storageDefinitions = $this->entityFieldManager->getFieldStorageDefinitions($entityTypeId);
      foreach (array_keys($fields) as $fieldName) {
        $storageDefinition = $storageDefinitions[$fieldName] ?? NULL;
        if ($storageDefinition === NULL || $storageDefinition->getSetting('target_type') !== 'media') {
          continue;
        }

        try {
          $references = $this->entityTypeManager->getStorage($entityTypeId)
            ->getQuery()
            ->accessCheck(FALSE)
            ->condition($fieldName . '.target_id', $mediaId)
            ->range(0, 1)
            ->execute();
          if ($references) {
            return TRUE;
          }
        }
        catch (\Throwable $e) {
          $this->logger->warning('HearMe: retained generated audio media @mid because references could not be checked: @error', [
            '@mid' => $mediaId,
            '@error' => $e::class,
          ]);
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * Checks whether all referenced media appear to be generated by HearMe.
   */
  private function allMediaIdsAreHearMeGenerated(array $mediaIds): bool {
    if (!$mediaIds) {
      return FALSE;
    }

    $mediaStorage = $this->entityTypeManager->getStorage('media');
    foreach ($mediaIds as $mediaId) {
      $media = $mediaStorage->load($mediaId);
      if (!$media instanceof MediaInterface || !$this->isHearMeGeneratedMedia($media)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Checks whether a media entity appears to contain HearMe-generated audio.
   */
  private function isHearMeGeneratedMedia(MediaInterface $media): bool {
    if ($media->bundle() !== 'hear_me_audio' || !$media->hasField('field_hear_me_audio_file') || $media->get('field_hear_me_audio_file')->isEmpty()) {
      return FALSE;
    }

    $fileStorage = $this->entityTypeManager->getStorage('file');
    foreach ($media->get('field_hear_me_audio_file')->getValue() as $fileValue) {
      $fid = (int) ($fileValue['target_id'] ?? 0);
      $file = $fileStorage->load($fid);
      if (!$file || !$this->cacheManager->isPersistentGeneratedFile($fid)) {
        return FALSE;
      }
    }

    return TRUE;
  }

}
