<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\filter\Entity\FilterFormat;
use Drupal\hear_me\Plugin\QueueWorker\HearMeGeneratedAudioCleanupWorker;
use Drupal\hear_me\Plugin\QueueWorker\HearMeQueueWorker;
use Drupal\hear_me\Service\HearMeExistingContentQueue;
use Drupal\hear_me\Service\HearMeNodeAudioQueue;
use Drupal\hear_me\Service\HearMeService;
use Drupal\hear_me\Service\NodeAudioAttacher;
use Drupal\hear_me\Service\TtsProviderResolver;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeAccessRebuild;
use Drupal\Core\Lock\LockAcquiringException;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\hear_me\Exception\PersistentSynthesisUnavailableException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\user\Entity\Role;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests queue and generated audio attachment behavior.
 */
#[Group('hear_me')]
#[RunTestsInSeparateProcesses]
class HearMeQueueTest extends EntityKernelTestBase {

  use ContentModerationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'image',
    'language',
    'media',
    'node',
    'hear_me',
    'hear_me_test',
    'workflows',
    'content_moderation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installEntitySchema('content_moderation_state');
    $this->installSchema('node', ['node_access']);
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('hear_me', ['hear_me_audio_cache']);
    $this->installConfig(['user', 'node', 'file', 'image', 'media', 'hear_me', 'content_moderation']);
    $anonymousRole = Role::load(AccountInterface::ANONYMOUS_ROLE);
    $this->assertNotNull($anonymousRole);
    $anonymousRole->grantPermission('access content')->save();
    $nodeAccessRebuild = $this->container->get(NodeAccessRebuild::class);
    $this->assertInstanceOf(NodeAccessRebuild::class, $nodeAccessRebuild);
    $nodeAccessRebuild->rebuild(TRUE);
  }

  /**
   * Tests stale queue items are ignored before synthesis starts.
   */
  public function testStaleQueueItemIsSkipped(): void {
    $this->config('hear_me.settings')->set('provider', 'piper')->save();
    $token = 'stale-token';

    $tts_service = $this->createMock(HearMeService::class);
    $tts_service->expects($this->never())->method('synthesize');

    $node_audio_queue = $this->createMock(HearMeNodeAudioQueue::class);
    $node_audio_queue->expects($this->once())
      ->method('isCurrentQueueItem')
      ->with(1, str_repeat('b', 64), $token)
      ->willReturn(TRUE);
    $node_audio_queue->expects($this->once())
      ->method('buildCurrentQueueItem')
      ->with(1, 'piper')
      ->willReturn([
        'text' => 'Current text',
        'lang' => 'en',
        'content_hash' => str_repeat('a', 64),
      ]);
    $node_audio_queue->expects($this->once())
      ->method('clearQueuedHash')
      ->with(1, str_repeat('b', 64), $token);

    $provider_resolver = $this->container->get('hear_me.provider_resolver');
    $this->assertInstanceOf(TtsProviderResolver::class, $provider_resolver);
    $node_audio_attacher = $this->container->get('hear_me.node_audio_attacher');
    $this->assertInstanceOf(NodeAudioAttacher::class, $node_audio_attacher);
    $worker = new HearMeQueueWorker([], 'hear_me_tts', [], $tts_service, $node_audio_attacher, $node_audio_queue, $provider_resolver);
    $worker->processItem([
      'nid' => 1,
      'content_hash' => str_repeat('b', 64),
      'token' => $token,
    ]);
  }

  /**
   * Tests one provider ID is used to rebuild and synthesize a queue item.
   */
  public function testQueueItemUsesCapturedProviderId(): void {
    $this->config('hear_me.settings')->set('provider', 'test')->save();

    $content_hash = str_repeat('a', 64);
    $token = 'captured-provider-token';
    $tts_service = $this->createMock(HearMeService::class);
    $tts_service->expects($this->once())
      ->method('synthesize')
      ->with('Current text', 'en', 'test')
      ->willReturn(NULL);

    $node_audio_queue = $this->createMock(HearMeNodeAudioQueue::class);
    $node_audio_queue->expects($this->once())
      ->method('isCurrentQueueItem')
      ->with(1, $content_hash, $token)
      ->willReturn(TRUE);
    $node_audio_queue->expects($this->once())
      ->method('buildCurrentQueueItem')
      ->with(1, 'test')
      ->willReturn([
        'text' => 'Current text',
        'lang' => 'en',
        'content_hash' => $content_hash,
      ]);
    $node_audio_queue->expects($this->never())->method('clearQueuedHash');
    $node_audio_queue->expects($this->once())
      ->method('recordSynthesisFailure')
      ->with(1, $content_hash, $token)
      ->willReturn(1);

    $provider_resolver = $this->container->get('hear_me.provider_resolver');
    $this->assertInstanceOf(TtsProviderResolver::class, $provider_resolver);
    $node_audio_attacher = $this->container->get('hear_me.node_audio_attacher');
    $this->assertInstanceOf(NodeAudioAttacher::class, $node_audio_attacher);
    $worker = new HearMeQueueWorker([], 'hear_me_tts', [], $tts_service, $node_audio_attacher, $node_audio_queue, $provider_resolver);
    $this->expectException(DelayedRequeueException::class);
    $worker->processItem([
      'nid' => 1,
      'content_hash' => $content_hash,
      'token' => $token,
    ]);
  }

  /**
   * Tests permanent synthesis failures are discarded after bounded retries.
   */
  public function testSynthesisFailureRetriesAreBounded(): void {
    $this->config('hear_me.settings')->set('provider', 'test')->save();
    $contentHash = str_repeat('e', 64);
    $token = 'bounded-retry-token';
    $current = [
      'text' => 'Current text',
      'lang' => 'en',
      'content_hash' => $contentHash,
    ];
    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->exactly(3))
      ->method('synthesize')
      ->willReturn(NULL);
    $nodeAudioQueue = $this->createMock(HearMeNodeAudioQueue::class);
    $nodeAudioQueue->expects($this->exactly(3))
      ->method('isCurrentQueueItem')
      ->with(1, $contentHash, $token)
      ->willReturn(TRUE);
    $nodeAudioQueue->expects($this->exactly(3))
      ->method('buildCurrentQueueItem')
      ->willReturn($current);
    $nodeAudioQueue->expects($this->exactly(3))
      ->method('recordSynthesisFailure')
      ->with(1, $contentHash, $token)
      ->willReturnOnConsecutiveCalls(1, 2, 3);
    $nodeAudioQueue->expects($this->never())->method('clearQueuedHash');
    $worker = new HearMeQueueWorker(
      [],
      'hear_me_tts',
      [],
      $ttsService,
      $this->container->get('hear_me.node_audio_attacher'),
      $nodeAudioQueue,
      $this->container->get('hear_me.provider_resolver'),
    );
    $item = [
      'nid' => 1,
      'content_hash' => $contentHash,
      'token' => $token,
    ];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
      try {
        $worker->processItem($item);
        $this->assertSame(3, $attempt);
      }
      catch (DelayedRequeueException) {
        $this->assertLessThan(3, $attempt);
      }
    }
  }

  /**
   * Tests failure-state lock contention delays instead of losing the item.
   */
  public function testFailureMarkerLockContentionRequeuesAfterSynthesis(): void {
    $this->config('hear_me.settings')->set('provider', 'test')->save();
    $contentHash = str_repeat('9', 64);
    $token = 'contended-attempt-token';
    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->once())->method('synthesize')->willReturn(NULL);
    $nodeAudioQueue = $this->createMock(HearMeNodeAudioQueue::class);
    $nodeAudioQueue->expects($this->once())
      ->method('isCurrentQueueItem')
      ->with(1, $contentHash, $token)
      ->willReturn(TRUE);
    $nodeAudioQueue->expects($this->once())
      ->method('buildCurrentQueueItem')
      ->with(1, 'test')
      ->willReturn([
        'text' => 'Current text',
        'lang' => 'en',
        'content_hash' => $contentHash,
      ]);
    $nodeAudioQueue->expects($this->once())
      ->method('recordSynthesisFailure')
      ->with(1, $contentHash, $token)
      ->willReturn(NULL);
    $nodeAudioQueue->expects($this->never())->method('clearQueuedHash');
    $worker = new HearMeQueueWorker(
      [],
      'hear_me_tts',
      [],
      $ttsService,
      $this->container->get('hear_me.node_audio_attacher'),
      $nodeAudioQueue,
      $this->container->get('hear_me.provider_resolver'),
    );

    $this->expectException(DelayedRequeueException::class);
    $worker->processItem([
      'nid' => 1,
      'content_hash' => $contentHash,
      'token' => $token,
    ]);
  }

  /**
   * Tests local persistence failures do not consume the synthesis budget.
   */
  public function testPersistentSynthesisFailureDoesNotConsumeFailureBudget(): void {
    $this->config('hear_me.settings')->set('provider', 'test')->save();
    $contentHash = str_repeat('7', 64);
    $token = 'completion-contention-token';
    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->once())
      ->method('synthesize')
      ->willThrowException(new PersistentSynthesisUnavailableException('Cache storage is unavailable.'));
    $nodeAudioQueue = $this->createMock(HearMeNodeAudioQueue::class);
    $nodeAudioQueue->expects($this->once())
      ->method('isCurrentQueueItem')
      ->willReturn(TRUE);
    $nodeAudioQueue->expects($this->once())
      ->method('buildCurrentQueueItem')
      ->willReturn([
        'text' => 'Current text',
        'lang' => 'en',
        'content_hash' => $contentHash,
      ]);
    $nodeAudioQueue->expects($this->never())->method('recordSynthesisFailure');
    $nodeAudioQueue->expects($this->never())->method('clearQueuedHash');
    $worker = new HearMeQueueWorker(
      [],
      'hear_me_tts',
      [],
      $ttsService,
      $this->container->get('hear_me.node_audio_attacher'),
      $nodeAudioQueue,
      $this->container->get('hear_me.provider_resolver'),
    );

    $this->expectException(DelayedRequeueException::class);
    $worker->processItem([
      'nid' => 1,
      'content_hash' => $contentHash,
      'token' => $token,
    ]);
  }

  /**
   * Tests provider discovery failure retains a real queued item for retry.
   */
  public function testProviderDiscoveryFailureRetainsQueuedItem(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Retry provider discovery',
      'status' => 1,
    ]);
    $node->save();
    $queue = \Drupal::queue('hear_me_tts');
    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('hear_me_tts');
    $this->config('hear_me.settings')->set('provider', 'unknown')->save();

    try {
      $worker->processItem($item->data);
      $this->fail('Provider discovery failure must delay the queue item.');
    }
    catch (DelayedRequeueException) {
      $this->addToAssertionCount(1);
    }

    $this->assertTrue($this->container->get('hear_me.node_audio_queue')->isCurrentQueueItem(
      (int) $item->data['nid'],
      (string) $item->data['content_hash'],
      (string) $item->data['token'],
    ));
    $this->config('hear_me.settings')->set('provider', 'test')->save();
    $worker->processItem($item->data);
    $queue->deleteItem($item);
    $this->assertFalse($this->reloadNode($node)->get('field_tts_audio')->isEmpty());
  }

  /**
   * Tests marker cleanup lock contention delays rather than losing the item.
   */
  public function testMarkerCleanupLockContentionRequeuesItem(): void {
    $contentHash = str_repeat('8', 64);
    $token = 'cleanup-contention-token';
    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->never())->method('synthesize');
    $nodeAudioQueue = $this->createMock(HearMeNodeAudioQueue::class);
    $nodeAudioQueue->expects($this->once())
      ->method('isCurrentQueueItem')
      ->with(1, $contentHash, $token)
      ->willReturn(TRUE);
    $nodeAudioQueue->expects($this->once())
      ->method('buildCurrentQueueItem')
      ->with(1, 'piper')
      ->willReturn(NULL);
    $nodeAudioQueue->expects($this->once())
      ->method('clearQueuedHash')
      ->with(1, $contentHash, $token)
      ->willThrowException(new LockAcquiringException('Queue state is busy.'));
    $worker = new HearMeQueueWorker(
      [],
      'hear_me_tts',
      [],
      $ttsService,
      $this->container->get('hear_me.node_audio_attacher'),
      $nodeAudioQueue,
      $this->container->get('hear_me.provider_resolver'),
    );

    $this->expectException(DelayedRequeueException::class);
    $worker->processItem([
      'nid' => 1,
      'content_hash' => $contentHash,
      'token' => $token,
    ]);
  }

  /**
   * Tests audio made stale during synthesis is not left publicly accessible.
   */
  public function testPostSynthesisStaleAudioIsDeletedWhenUnreferenced(): void {
    $this->config('hear_me.settings')->set('provider', 'test')->save();
    $contentHash = str_repeat('a', 64);
    $token = 'post-synthesis-token';
    $media = $this->createAudioMedia('public://tts/post-synthesis-stale.wav', 'Stale generated audio');
    $this->markMediaAsGenerated($media);
    $file = $media->get('field_hear_me_audio_file')->entity;
    $this->assertInstanceOf(File::class, $file);

    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->once())
      ->method('synthesize')
      ->with('Current text', 'en', 'test')
      ->willReturn($media);
    $nodeAudioQueue = $this->createMock(HearMeNodeAudioQueue::class);
    $nodeAudioQueue->expects($this->once())
      ->method('isCurrentQueueItem')
      ->with(1, $contentHash, $token)
      ->willReturn(TRUE);
    $nodeAudioQueue->expects($this->exactly(2))
      ->method('buildCurrentQueueItem')
      ->with(1, 'test')
      ->willReturn([
        'text' => 'Current text',
        'lang' => 'en',
        'content_hash' => $contentHash,
      ], NULL);
    $nodeAudioQueue->expects($this->once())
      ->method('clearQueuedHash')
      ->with(1, $contentHash, $token);

    $worker = new HearMeQueueWorker(
      [],
      'hear_me_tts',
      [],
      $ttsService,
      $this->container->get('hear_me.node_audio_attacher'),
      $nodeAudioQueue,
      $this->container->get('hear_me.provider_resolver'),
    );
    $worker->processItem([
      'nid' => 1,
      'content_hash' => $contentHash,
      'token' => $token,
    ]);

    $this->assertNull(Media::load($media->id()));
    $this->assertNull(File::load($file->id()));
  }

  /**
   * Tests disappearing generated Media leaves its queue item for retry.
   */
  public function testDeletedMediaDuringAttachmentRequeuesItem(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Media handoff race',
      'status' => 1,
    ]);
    $node->save();
    $revisionIds = $this->getNodeRevisionIds((int) $node->id());
    $queue = \Drupal::queue('hear_me_tts');
    $queuedItem = $queue->claimItem();
    $this->assertIsObject($queuedItem);
    $media = $this->createAudioMedia('public://tts/deleted-before-attach.wav', 'Deleted before attach');
    $this->markMediaAsGenerated($media, 'Media handoff race');
    $media->delete();
    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->once())
      ->method('synthesize')
      ->willReturn($media);
    $nodeAudioQueue = $this->container->get('hear_me.node_audio_queue');
    $worker = new HearMeQueueWorker(
      [],
      'hear_me_tts',
      [],
      $ttsService,
      $this->container->get('hear_me.node_audio_attacher'),
      $nodeAudioQueue,
      $this->container->get('hear_me.provider_resolver'),
    );

    try {
      $worker->processItem($queuedItem->data);
      $this->fail('A deleted generated Media entity must delay the queue item.');
    }
    catch (DelayedRequeueException) {
      $this->assertTrue($nodeAudioQueue->isCurrentQueueItem(
        (int) $queuedItem->data['nid'],
        (string) $queuedItem->data['content_hash'],
        (string) $queuedItem->data['token'],
      ));
    }

    $this->assertTrue($this->reloadNode($node)->get('field_tts_audio')->isEmpty());
    $this->assertSame($revisionIds, $this->getNodeRevisionIds((int) $node->id()));
  }

  /**
   * Tests generated Media lock contention leaves the queue item for retry.
   */
  public function testMediaLockContentionRequeuesItem(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Contended media handoff',
      'status' => 1,
    ]);
    $node->save();
    $queue = \Drupal::queue('hear_me_tts');
    $queuedItem = $queue->claimItem();
    $this->assertIsObject($queuedItem);
    $media = $this->createAudioMedia('public://tts/contended-attach.wav', 'Contended attachment');
    $this->markMediaAsGenerated($media, 'Contended media handoff');
    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->exactly(4))
      ->method('synthesize')
      ->willReturn($media);
    $nodeAudioQueue = $this->container->get('hear_me.node_audio_queue');
    $contendedLock = $this->createMock(LockBackendInterface::class);
    $contendedLock->expects($this->exactly(4))
      ->method('acquire')
      ->with('hear_me.generated_media.' . $media->id(), 300.0)
      ->willReturnOnConsecutiveCalls(FALSE, FALSE, FALSE, TRUE);
    $contendedLock->expects($this->once())
      ->method('release')
      ->with('hear_me.generated_media.' . $media->id());
    $contendedAttacher = new NodeAudioAttacher(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('database'),
      $contendedLock,
      $this->container->get('queue'),
      $this->container->get('keyvalue'),
      $this->container->get('datetime.time'),
      $nodeAudioQueue,
      $this->container->get('hear_me.cache_manager'),
      $this->container->get('logger.factory'),
    );
    $worker = new HearMeQueueWorker(
      [],
      'hear_me_tts',
      [],
      $ttsService,
      $contendedAttacher,
      $nodeAudioQueue,
      $this->container->get('hear_me.provider_resolver'),
    );

    for ($attempt = 1; $attempt <= 3; $attempt++) {
      try {
        $worker->processItem($queuedItem->data);
        $this->fail('A busy generated Media entity must delay the queue item.');
      }
      catch (DelayedRequeueException) {
        $this->assertTrue($nodeAudioQueue->isCurrentQueueItem(
          (int) $queuedItem->data['nid'],
          (string) $queuedItem->data['content_hash'],
          (string) $queuedItem->data['token'],
        ));
      }
    }
    $worker->processItem($queuedItem->data);

    $this->assertSame(
      (int) $media->id(),
      (int) $this->reloadNode($node)->get('field_tts_audio')->target_id,
    );
    $this->assertFalse($nodeAudioQueue->isCurrentQueueItem(
      (int) $queuedItem->data['nid'],
      (string) $queuedItem->data['content_hash'],
      (string) $queuedItem->data['token'],
    ));
    $this->assertNotNull(Media::load($media->id()));
  }

  /**
   * Tests attachment validates fresh source while holding the Media lock.
   */
  public function testAttachmentRejectsConcurrentSourceEdit(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Source before concurrent edit',
      'status' => 1,
    ]);
    $node->save();
    $queueItem = $this->container->get('hear_me.node_audio_queue')->buildQueueItem($node);
    $this->assertIsArray($queueItem);
    $media = $this->createAudioMedia('public://tts/concurrent-source-edit.wav', 'Concurrent source edit');
    $this->markMediaAsGenerated($media, 'Source before concurrent edit');
    $this->container->get('database')->update('node_field_data')
      ->fields(['title' => 'Source after concurrent edit'])
      ->condition('nid', $node->id())
      ->execute();

    $this->assertFalse($this->container->get('hear_me.node_audio_attacher')->attach(
      (int) $node->id(),
      $media,
      $queueItem['content_hash'],
    ));
    $this->assertTrue($this->reloadNode($node)->get('field_tts_audio')->isEmpty());
    $this->assertSame('Source after concurrent edit', $this->reloadNode($node)->label());
  }

  /**
   * Tests superseded queue ownership rolls back an attachment.
   */
  public function testSupersededOwnerCannotAttachOrClearNewerMarker(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Queue owner race',
      'status' => 1,
    ]);
    $node->save();
    $queuedItem = \Drupal::queue('hear_me_tts')->claimItem();
    $this->assertIsObject($queuedItem);
    $contentHash = (string) $queuedItem->data['content_hash'];
    $queueToken = (string) $queuedItem->data['token'];
    $newerToken = 'newer-queue-owner';
    $store = $this->container->get('keyvalue')->get(HearMeNodeAudioQueue::STORE_COLLECTION);
    $storeKey = $node->id() . '.' . $contentHash;
    $marker = $store->get($storeKey);
    $marker['token'] = $newerToken;
    $store->set($storeKey, $marker);
    $media = $this->createAudioMedia('public://tts/queue-owner-race.wav', 'Queue owner race');

    $this->assertFalse($this->container->get('hear_me.node_audio_attacher')->attach(
      (int) $node->id(),
      $media,
      $contentHash,
      $queueToken,
    ));

    $this->assertTrue($this->reloadNode($node)->get('field_tts_audio')->isEmpty());
    $this->assertSame($newerToken, $store->get($storeKey)['token']);
  }

  /**
   * Tests orphan cleanup lock contention creates a durable retry item.
   */
  public function testOrphanCleanupLockContentionQueuesRetry(): void {
    $media = $this->createAudioMedia('public://tts/cleanup-contention.wav', 'Cleanup contention');
    $this->markMediaAsGenerated($media);
    $this->container->get('keyvalue')->get('hear_me.generated_audio_cleanup')->deleteAll();
    $file = $media->get('field_hear_me_audio_file')->entity;
    $this->assertInstanceOf(File::class, $file);
    $cleanupQueue = $this->createMock(QueueInterface::class);
    $cleanupQueue->expects($this->once())
      ->method('createItem')
      ->with($this->callback(static fn(array $item): bool => $item['media_id'] === (int) $media->id()
        && $item['file_ids'] === [(int) $file->id()]
        && is_string($item['token'])
        && strlen($item['token']) === 32))
      ->willReturn(1);
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->expects($this->once())
      ->method('get')
      ->with('hear_me_generated_audio_cleanup')
      ->willReturn($cleanupQueue);
    $contendedLock = $this->createMock(LockBackendInterface::class);
    $contendedLock->expects($this->exactly(4))
      ->method('acquire')
      ->willReturnCallback(static fn(string $name, float $ttl): bool => match ($name) {
        'hear_me.generated_media.' . $media->id() => FALSE,
        'hear_me.generated_media_cleanup_queue.' . $media->id() => $ttl === 30.0,
        default => FALSE,
      });
    $contendedLock->expects($this->exactly(2))
      ->method('release')
      ->with('hear_me.generated_media_cleanup_queue.' . $media->id());
    $attacher = new NodeAudioAttacher(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('database'),
      $contendedLock,
      $queueFactory,
      $this->container->get('keyvalue'),
      $this->container->get('datetime.time'),
      $this->container->get('hear_me.node_audio_queue'),
      $this->container->get('hear_me.cache_manager'),
      $this->container->get('logger.factory'),
    );

    $attacher->cleanupOrphanedGeneratedAudio($media);
    $attacher->cleanupOrphanedGeneratedAudio($media);

    $this->assertNotNull(Media::load($media->id()));
  }

  /**
   * Tests cron republishes cleanup markers after queue backend failure.
   */
  public function testFailedCleanupPublicationRemainsDurable(): void {
    $media = $this->createAudioMedia('public://tts/cleanup-publication.wav', 'Cleanup publication');
    $this->markMediaAsGenerated($media);
    $file = $media->get('field_hear_me_audio_file')->entity;
    $this->assertInstanceOf(File::class, $file);
    $cleanupStore = $this->container->get('keyvalue')->get('hear_me.generated_audio_cleanup');
    $cleanupStore->deleteAll();
    $publishedItems = [];
    $cleanupQueue = $this->createMock(QueueInterface::class);
    $cleanupQueue->expects($this->exactly(2))
      ->method('createItem')
      ->willReturnCallback(static function (array $item) use (&$publishedItems): int|false {
        $publishedItems[] = $item;
        return count($publishedItems) === 1 ? FALSE : 1;
      });
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->expects($this->exactly(2))
      ->method('get')
      ->with('hear_me_generated_audio_cleanup')
      ->willReturn($cleanupQueue);
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(3))
      ->method('acquire')
      ->willReturnCallback(static fn(string $name): bool => $name !== 'hear_me.generated_media.' . $media->id());
    $lock->expects($this->exactly(2))
      ->method('release')
      ->with('hear_me.generated_media_cleanup_queue.' . $media->id());
    $attacher = new NodeAudioAttacher(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('database'),
      $lock,
      $queueFactory,
      $this->container->get('keyvalue'),
      $this->container->get('datetime.time'),
      $this->container->get('hear_me.node_audio_queue'),
      $this->container->get('hear_me.cache_manager'),
      $this->container->get('logger.factory'),
    );

    $attacher->cleanupOrphanedGeneratedAudio($media);
    $pending = $cleanupStore->get((string) $media->id());
    $this->assertIsArray($pending);
    $this->assertSame(0, $pending['published_at']);
    $attacher->publishPendingGeneratedAudioCleanup();

    $published = $cleanupStore->get((string) $media->id());
    $this->assertIsArray($published);
    $this->assertGreaterThan(0, $published['published_at']);
    $this->assertCount(2, $publishedItems);
    $this->assertSame($publishedItems[0], $publishedItems[1]);
    $this->assertSame([(int) $file->id()], $publishedItems[1]['file_ids']);
  }

  /**
   * Tests the cleanup worker removes generated entities and its owned marker.
   */
  public function testGeneratedAudioCleanupWorkerCompletesMarker(): void {
    $media = $this->createAudioMedia('public://tts/cleanup-worker.wav', 'Cleanup worker');
    $this->markMediaAsGenerated($media);
    $file = $media->get('field_hear_me_audio_file')->entity;
    $this->assertInstanceOf(File::class, $file);
    $cleanupStore = $this->container->get('keyvalue')->get('hear_me.generated_audio_cleanup');
    $cleanupStore->set((string) $media->id(), [
      'file_ids' => [(int) $file->id()],
      'published_at' => \Drupal::time()->getCurrentTime(),
      'token' => 'cleanup-worker-token',
    ]);
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('hear_me_generated_audio_cleanup');
    $this->assertInstanceOf(HearMeGeneratedAudioCleanupWorker::class, $worker);

    $worker->processItem([
      'media_id' => (int) $media->id(),
      'file_ids' => [(int) $file->id()],
      'token' => 'cleanup-worker-token',
    ]);

    $this->assertNull(Media::load($media->id()));
    $this->assertNull(File::load($file->id()));
    $this->assertFalse($cleanupStore->has((string) $media->id()));
  }

  /**
   * Tests tokenless pre-release queue items cannot alter a current marker.
   */
  public function testTokenlessQueueItemCannotAlterCurrentMarker(): void {
    $tts_service = $this->createMock(HearMeService::class);
    $tts_service->expects($this->never())->method('synthesize');

    $contentHash = str_repeat('c', 64);
    $store = $this->container->get('keyvalue')->get(HearMeNodeAudioQueue::STORE_COLLECTION);
    $storeKey = '1.' . $contentHash;
    $node_audio_queue = $this->container->get('hear_me.node_audio_queue');
    $this->assertSame(HearMeNodeAudioQueue::RESULT_QUEUED, $node_audio_queue->queueItem([
      'nid' => 1,
      'content_hash' => $contentHash,
    ]));
    $marker = $store->get($storeKey);

    $provider_resolver = $this->container->get('hear_me.provider_resolver');
    $this->assertInstanceOf(TtsProviderResolver::class, $provider_resolver);
    $node_audio_attacher = $this->container->get('hear_me.node_audio_attacher');
    $this->assertInstanceOf(NodeAudioAttacher::class, $node_audio_attacher);
    $worker = new HearMeQueueWorker([], 'hear_me_tts', [], $tts_service, $node_audio_attacher, $node_audio_queue, $provider_resolver);
    $worker->processItem([
      'nid' => 1,
      'content_hash' => $contentHash,
    ]);

    $this->assertSame($marker, $store->get($storeKey));
  }

  /**
   * Tests failed publication retains ownership for cron repair.
   */
  public function testQueuePublicationFailureIsRepaired(): void {
    $store = $this->container->get('keyvalue')->get(HearMeNodeAudioQueue::STORE_COLLECTION);
    $publishedItems = [];
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->exactly(4))
      ->method('createItem')
      ->willReturnCallback(function (array $item) use ($store, &$publishedItems): int|false {
        $storeKey = $item['nid'] . '.' . $item['content_hash'];
        $marker = $store->get($storeKey);
        $this->assertSame($item['token'], $marker['token'] ?? NULL);
        $this->assertSame(0, $marker['published_at'] ?? NULL);
        $this->assertSame(['nid', 'content_hash', 'token'], array_keys($item));
        $publishedItems[] = $item;
        if (count($publishedItems) === 1) {
          return FALSE;
        }
        if (count($publishedItems) === 2) {
          return 1;
        }
        if (count($publishedItems) === 3) {
          throw new \RuntimeException('Simulated queue backend failure.');
        }
        $marker['token'] = 'newer-owner-during-publication';
        $store->set($storeKey, $marker);
        return 2;
      });
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->expects($this->exactly(4))
      ->method('get')
      ->with('hear_me_tts')
      ->willReturn($queue);
    $nodeAudioQueue = new HearMeNodeAudioQueue(
      $this->container->get('config.factory'),
      $this->container->get('hear_me.provider_resolver'),
      $this->container->get('hear_me.input_validator'),
      $this->container->get('entity_type.manager'),
      $queueFactory,
      $this->container->get('keyvalue'),
      $this->container->get('lock'),
      $this->container->get('datetime.time'),
      $this->container->get('renderer'),
      $this->container->get('account_switcher'),
      $this->container->get('logger.factory'),
    );
    $contentHash = str_repeat('1', 64);
    $storeKey = '1.' . $contentHash;

    $this->assertSame(HearMeNodeAudioQueue::RESULT_FAILED, $nodeAudioQueue->queueItem([
      'nid' => 1,
      'content_hash' => $contentHash,
    ]));
    $pending = $store->get($storeKey);
    $this->assertIsArray($pending);
    $this->assertSame(0, $pending['published_at']);
    $this->assertSame(1, $nodeAudioQueue->repairPendingPublications());
    $this->assertSame($publishedItems[0], $publishedItems[1]);
    $this->assertGreaterThan(0, $store->get($storeKey)['published_at']);
    $this->assertSame(HearMeNodeAudioQueue::RESULT_DUPLICATE, $nodeAudioQueue->queueItem([
      'nid' => 1,
      'content_hash' => $contentHash,
    ]));
    $this->assertTrue($nodeAudioQueue->isCurrentQueueItem(1, $contentHash, $publishedItems[0]['token']));

    $exceptionHash = str_repeat('2', 64);
    $this->assertSame(HearMeNodeAudioQueue::RESULT_FAILED, $nodeAudioQueue->queueItem([
      'nid' => 2,
      'content_hash' => $exceptionHash,
    ]));
    $this->assertSame(0, $store->get('2.' . $exceptionHash)['published_at']);

    $supersededHash = str_repeat('5', 64);
    $this->assertSame(HearMeNodeAudioQueue::RESULT_FAILED, $nodeAudioQueue->queueItem([
      'nid' => 5,
      'content_hash' => $supersededHash,
    ]));
    $this->assertSame('newer-owner-during-publication', $store->get('5.' . $supersededHash)['token']);
  }

  /**
   * Tests marker lock contention reports a failed publication outcome.
   */
  public function testQueueMarkerLockContentionReportsFailure(): void {
    $contentHash = str_repeat('3', 64);
    $storeKey = '3.' . $contentHash;
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->expects($this->never())->method('get');
    $lock = $this->createMock(LockBackendInterface::class);
    $lock->expects($this->exactly(2))
      ->method('acquire')
      ->with('hear_me.queue_hash.' . hash('sha256', $storeKey), 30.0)
      ->willReturn(FALSE);
    $lock->expects($this->once())
      ->method('wait')
      ->with('hear_me.queue_hash.' . hash('sha256', $storeKey), 1);
    $nodeAudioQueue = new HearMeNodeAudioQueue(
      $this->container->get('config.factory'),
      $this->container->get('hear_me.provider_resolver'),
      $this->container->get('hear_me.input_validator'),
      $this->container->get('entity_type.manager'),
      $queueFactory,
      $this->container->get('keyvalue'),
      $lock,
      $this->container->get('datetime.time'),
      $this->container->get('renderer'),
      $this->container->get('account_switcher'),
      $this->container->get('logger.factory'),
    );

    $this->assertSame(HearMeNodeAudioQueue::RESULT_FAILED, $nodeAudioQueue->queueItem([
      'nid' => 3,
      'content_hash' => $contentHash,
    ]));
    $this->assertFalse($this->container->get('keyvalue')
      ->get(HearMeNodeAudioQueue::STORE_COLLECTION)
      ->has($storeKey));
  }

  /**
   * Tests automatic queue publication occurs only after a successful commit.
   */
  public function testAutomaticQueuePublicationWaitsForCommit(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $database = $this->container->get('database');
    $queue = \Drupal::queue('hear_me_tts');
    $store = $this->container->get('keyvalue')->get(HearMeNodeAudioQueue::STORE_COLLECTION);

    $transaction = $database->startTransaction();
    $committedNode = Node::create([
      'type' => 'article',
      'title' => 'Committed publication',
      'status' => 1,
    ]);
    $committedNode->save();
    $committedItem = $this->container->get('hear_me.node_audio_queue')->buildQueueItem($committedNode);
    $this->assertIsArray($committedItem);
    $committedStoreKey = $committedNode->id() . '.' . $committedItem['content_hash'];
    $this->assertSame(0, $queue->numberOfItems());
    $this->assertFalse($store->get($committedStoreKey, FALSE));

    unset($transaction);
    $this->assertSame(1, $queue->numberOfItems());
    $this->assertIsArray($store->get($committedStoreKey));

    $transaction = $database->startTransaction();
    $rolledBackNode = Node::create([
      'type' => 'article',
      'title' => 'Rolled back publication',
      'status' => 1,
    ]);
    $rolledBackNode->save();
    $rolledBackItem = $this->container->get('hear_me.node_audio_queue')->buildQueueItem($rolledBackNode);
    $this->assertIsArray($rolledBackItem);
    $rolledBackStoreKey = $rolledBackNode->id() . '.' . $rolledBackItem['content_hash'];
    $transaction->rollBack();
    unset($transaction);

    $this->assertSame(1, $queue->numberOfItems());
    $this->assertFalse($store->get($rolledBackStoreKey, FALSE));
  }

  /**
   * Tests post-commit backend failure leaves a cron-repairable marker.
   */
  public function testAutomaticQueuePublicationFailureIsRepairable(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $publishedItems = [];
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->exactly(2))
      ->method('createItem')
      ->willReturnCallback(static function (array $item) use (&$publishedItems): int|false {
        $publishedItems[] = $item;
        return count($publishedItems) === 1 ? FALSE : 1;
      });
    $queueFactory = $this->createMock(QueueFactory::class);
    $queueFactory->expects($this->exactly(2))
      ->method('get')
      ->with('hear_me_tts')
      ->willReturn($queue);
    $nodeAudioQueue = new HearMeNodeAudioQueue(
      $this->container->get('config.factory'),
      $this->container->get('hear_me.provider_resolver'),
      $this->container->get('hear_me.input_validator'),
      $this->container->get('entity_type.manager'),
      $queueFactory,
      $this->container->get('keyvalue'),
      $this->container->get('lock'),
      $this->container->get('datetime.time'),
      $this->container->get('renderer'),
      $this->container->get('account_switcher'),
      $this->container->get('logger.factory'),
    );
    $this->container->set('hear_me.node_audio_queue', $nodeAudioQueue);

    $node = Node::create([
      'type' => 'article',
      'title' => 'Committed before backend failure',
      'status' => 1,
    ]);
    $node->save();

    $this->assertNotNull(Node::load($node->id()));
    $this->assertCount(1, $publishedItems);
    $storeKey = $node->id() . '.' . $publishedItems[0]['content_hash'];
    $marker = $this->container->get('keyvalue')
      ->get(HearMeNodeAudioQueue::STORE_COLLECTION)
      ->get($storeKey);
    $this->assertIsArray($marker);
    $this->assertSame(0, $marker['published_at']);
    \hear_me_cron();
    $this->assertSame($publishedItems[0], $publishedItems[1]);
    $this->assertGreaterThan(0, $this->container->get('keyvalue')
      ->get(HearMeNodeAudioQueue::STORE_COLLECTION)
      ->get($storeKey)['published_at']);
  }

  /**
   * Tests existing-content backfill queues only matching configured nodes.
   */
  public function testExistingContentBackfillQueuesConfiguredNodes(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');

    $node = Node::create([
      'type' => 'article',
      'title' => 'Readable title',
      'status' => 1,
    ]);
    $node->save();
    $anonymous = new AnonymousUserSession();
    $this->assertTrue($node->access('view', $anonymous));
    $this->assertTrue($node->get('title')->access('view', $anonymous));

    $this->config('hear_me.settings')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $queue = \Drupal::queue('hear_me_tts');
    $this->assertSame(0, $queue->numberOfItems());

    $stats = $this->container->get('hear_me.existing_content_queue')->queueAll(['article'], TRUE, TRUE);

    $this->assertSame(1, $stats['scanned']);
    $this->assertSame(1, $stats['queued']);
    $this->assertSame(1, $queue->numberOfItems());

    $item = $queue->claimItem();
    $this->assertSame((int) $node->id(), (int) $item->data['nid']);
    $this->assertNotEmpty($item->data['content_hash']);
    $this->assertSame(['nid', 'content_hash', 'token'], array_keys($item->data));
    $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $item->data['token']);
    $queue->deleteItem($item);
  }

  /**
   * Tests unpublished content is never queued for public persistent audio.
   */
  public function testUnpublishedNodeIsNotQueued(): void {
    $this->createContentType('article', 'Article');
    $this->config('hear_me.settings')->set('queue_bundles', ['article'])->save();

    Node::create([
      'type' => 'article',
      'title' => 'Private draft',
      'status' => 0,
    ])->save();

    $this->assertSame(0, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests generated public audio is retracted when nodes become ineligible.
   */
  public function testUnpublishingNodesRetractsGeneratedAudioConservatively(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $generatedMedia = $this->createAudioMedia('public://tts/retracted-generated.wav', 'Generated audio');
    $this->markMediaAsGenerated($generatedMedia);
    $generatedFile = $generatedMedia->get('field_hear_me_audio_file')->entity;
    $this->assertInstanceOf(File::class, $generatedFile);
    $manualMedia = $this->createAudioMedia('public://manual/preserved.wav', 'Manual audio');

    $firstNode = Node::create([
      'type' => 'article',
      'title' => 'First public node',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $generatedMedia->id()],
    ]);
    $firstNode->save();
    $secondNode = Node::create([
      'type' => 'article',
      'title' => 'Second public node',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $generatedMedia->id()],
    ]);
    $secondNode->save();
    $manualNode = Node::create([
      'type' => 'article',
      'title' => 'Node with manual audio',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $manualMedia->id()],
    ]);
    $manualNode->save();

    $firstNode->setUnpublished()->save();

    $this->assertTrue($this->reloadNode($firstNode)->get('field_tts_audio')->isEmpty());
    $this->assertSame((int) $generatedMedia->id(), (int) $this->reloadNode($secondNode)->get('field_tts_audio')->target_id);
    $this->assertNotNull(Media::load($generatedMedia->id()));
    $this->assertNotNull(File::load($generatedFile->id()));

    $secondNode->setUnpublished()->save();
    $manualNode->setUnpublished()->save();

    $this->assertTrue($this->reloadNode($secondNode)->get('field_tts_audio')->isEmpty());
    $this->assertSame((int) $manualMedia->id(), (int) $this->reloadNode($manualNode)->get('field_tts_audio')->target_id);
    $this->assertNull(Media::load($generatedMedia->id()));
    $this->assertNull(File::load($generatedFile->id()));
    $this->assertFalse($this->container->get('hear_me.cache_manager')->isPersistentGeneratedFile((int) $generatedFile->id()));

    $deletedMedia = $this->createAudioMedia('public://tts/deleted-node.wav', 'Deleted node audio');
    $this->markMediaAsGenerated($deletedMedia);
    $deletedFile = $deletedMedia->get('field_hear_me_audio_file')->entity;
    $this->assertInstanceOf(File::class, $deletedFile);
    $deletedNode = Node::create([
      'type' => 'article',
      'title' => 'Deleted public node',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $deletedMedia->id()],
    ]);
    $deletedNode->save();
    $deletedNode->delete();

    $this->assertNull(Media::load($deletedMedia->id()));
    $this->assertNull(File::load($deletedFile->id()));
  }

  /**
   * Tests a same-revision node grant change retracts public generated audio.
   */
  public function testAnonymousAccessRevocationRetractsGeneratedAudio(): void {
    $this->container->get('state')->set('hear_me_test.node_access_enabled', TRUE);
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $media = $this->createAudioMedia('public://tts/access-revoked.wav', 'Access-revoked audio');
    $this->markMediaAsGenerated($media, 'Public grant title');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Public grant title',
      'status' => 1,
      'promote' => 1,
      'field_tts_audio' => ['target_id' => $media->id()],
    ]);
    $node->save();
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $anonymous = new AnonymousUserSession();
    $this->assertTrue($node->access('view', $anonymous));
    $revisionIds = $this->getNodeRevisionIds((int) $node->id());

    $node->set('promote', 0)->save();

    $reloaded = $this->reloadNode($node);
    $this->assertFalse($reloaded->access('view', $anonymous));
    $this->assertTrue($reloaded->get('field_tts_audio')->isEmpty());
    $this->assertNull(Media::load($media->id()));
    $this->assertSame($revisionIds, $this->getNodeRevisionIds((int) $node->id()));
    $this->assertSame(0, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests stale generated audio is retracted before changed text is queued.
   */
  public function testSourceChangeRetractsGeneratedAudioBeforeReplacement(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $media = $this->createAudioMedia('public://tts/old-source.wav', 'Old source audio');
    $this->markMediaAsGenerated($media, 'Original public title');
    $file = $media->get('field_hear_me_audio_file')->entity;
    $this->assertInstanceOf(File::class, $file);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Original public title',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $media->id()],
    ]);
    $node->save();
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $node->setTitle('Updated public title')->save();

    $this->assertTrue($this->reloadNode($node)->get('field_tts_audio')->isEmpty());
    $this->assertNull(Media::load($media->id()));
    $this->assertNull(File::load($file->id()));
    $this->assertSame(1, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests source updates preserve generated audio when replacement is disabled.
   */
  public function testSourceChangeRespectsDisabledGeneratedReplacement(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $media = $this->createAudioMedia('public://tts/preserved-source.wav', 'Preserved source audio');
    $this->markMediaAsGenerated($media, 'Original preserved title');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Original preserved title',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $media->id()],
    ]);
    $node->save();
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->set('replace_existing_generated_audio', FALSE)
      ->save();

    $node->setTitle('Updated preserved title')->save();

    $this->assertSame(
      (int) $media->id(),
      (int) $this->reloadNode($node)->get('field_tts_audio')->target_id,
    );
    $this->assertNotNull(Media::load($media->id()));
    $this->assertSame(0, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests provider readiness failures preserve unchanged generated audio.
   */
  public function testProviderReadinessFailuresPreserveGeneratedAudio(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $cases = [
      'missing' => ['provider' => NULL, 'uri' => 'public://tts/provider-missing.wav'],
      'unknown' => ['provider' => 'unknown', 'uri' => 'public://tts/provider-unknown.wav'],
      'invalid_endpoint' => ['provider' => 'piper', 'uri' => 'public://tts/provider-invalid-endpoint.wav'],
      'unavailable' => ['provider' => 'test', 'uri' => 'public://tts/provider-unavailable.wav'],
    ];
    $nodes = [];
    $media = [];
    foreach ($cases as $case => $values) {
      $title = 'Stable source ' . $case;
      $media[$case] = $this->createAudioMedia($values['uri'], 'Generated audio ' . $case);
      $this->markMediaAsGenerated($media[$case], $title);
      $nodes[$case] = Node::create([
        'type' => 'article',
        'title' => $title,
        'status' => 1,
        'field_tts_audio' => ['target_id' => $media[$case]->id()],
      ]);
      $nodes[$case]->save();
    }
    $settings = $this->config('hear_me.settings')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio');

    foreach ($cases as $case => $values) {
      if ($values['provider'] === NULL) {
        $settings->clear('provider')->save();
      }
      else {
        $settings->set('provider', $values['provider'])->save();
      }
      if ($case === 'invalid_endpoint') {
        $this->config('hear_me.provider.piper')->set('endpoint', 'invalid endpoint')->save();
      }
      $this->container->get('state')->set('hear_me_test.provider_unavailable', $case === 'unavailable');

      $nodes[$case]->setPromoted(TRUE)->save();

      $this->assertSame(
        (int) $media[$case]->id(),
        (int) $this->reloadNode($nodes[$case])->get('field_tts_audio')->target_id,
        "Provider readiness case $case must preserve valid generated audio.",
      );
      $this->assertNotNull(Media::load($media[$case]->id()));
    }
  }

  /**
   * Tests provider language aliases do not retract unchanged generated audio.
   */
  public function testProviderLanguageAliasPreservesGeneratedAudio(): void {
    ConfigurableLanguage::createFromLangcode('en-gb')->save();
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $media = $this->createAudioMedia('public://tts/provider-language-alias.wav', 'Alias audio');
    $this->markMediaAsGenerated($media, 'Alias source', 'en');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Alias source',
      'langcode' => 'en-gb',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $media->id()],
    ]);
    $node->save();
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $node->setPromoted(TRUE)->save();

    $this->assertSame((int) $media->id(), (int) $this->reloadNode($node)->get('field_tts_audio')->target_id);
    $this->assertSame(0, \Drupal::queue('hear_me_tts')->numberOfItems());

    $this->config('hear_me.settings')->clear('provider')->save();
    $node->setSticky(TRUE)->save();

    $this->assertSame((int) $media->id(), (int) $this->reloadNode($node)->get('field_tts_audio')->target_id);
    $this->assertSame(0, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests definitive source state changes retract without provider readiness.
   */
  public function testDefinitiveSourceChangesRetractWithoutProvider(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $textMedia = $this->createAudioMedia('public://tts/missing-provider-text.wav', 'Text change audio');
    $this->markMediaAsGenerated($textMedia, 'Original source');
    $textNode = Node::create([
      'type' => 'article',
      'title' => 'Original source',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $textMedia->id()],
    ]);
    $textNode->save();
    $languageMedia = $this->createAudioMedia('public://tts/missing-provider-language.wav', 'Language change audio');
    $this->markMediaAsGenerated($languageMedia, 'Stable source', 'en');
    $languageNode = Node::create([
      'type' => 'article',
      'title' => 'Stable source',
      'langcode' => 'en',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $languageMedia->id()],
    ]);
    $languageNode->save();
    $unpublishedMedia = $this->createAudioMedia('public://tts/missing-provider-unpublished.wav', 'Unpublished audio');
    $this->markMediaAsGenerated($unpublishedMedia, 'Published source');
    $unpublishedNode = Node::create([
      'type' => 'article',
      'title' => 'Published source',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $unpublishedMedia->id()],
    ]);
    $unpublishedNode->save();
    $this->config('hear_me.settings')
      ->clear('provider')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $textNode->setTitle('Changed source')->save();
    $languageNode->set('langcode', 'und')->save();
    $unpublishedNode->setUnpublished()->save();

    $this->assertTrue($this->reloadNode($textNode)->get('field_tts_audio')->isEmpty());
    $this->assertTrue($this->reloadNode($languageNode)->get('field_tts_audio')->isEmpty());
    $this->assertTrue($this->reloadNode($unpublishedNode)->get('field_tts_audio')->isEmpty());
  }

  /**
   * Tests source updates queue manual audio replacement when enabled.
   */
  public function testSourceChangeQueuesEnabledManualReplacement(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $manualMedia = $this->createAudioMedia('public://manual/source-update.wav', 'Manual source audio');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Original manual title',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $manualMedia->id()],
    ]);
    $node->save();
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->set('overwrite_manual_audio', TRUE)
      ->save();

    $node->setTitle('Updated manual title')->save();

    $this->assertSame(
      (int) $manualMedia->id(),
      (int) $this->reloadNode($node)->get('field_tts_audio')->target_id,
    );
    $this->assertSame(1, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests unrelated updates do not queue nodes with missing audio.
   */
  public function testUnrelatedUpdateDoesNotQueueMissingAudio(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Unchanged synthesis source',
      'status' => 1,
    ]);
    $node->save();
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $node->setPromoted(TRUE)->save();

    $this->assertSame(0, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests a language-only change retracts stale generated audio.
   */
  public function testLanguageChangeRetractsGeneratedAudioBeforeReplacement(): void {
    ConfigurableLanguage::createFromLangcode('uk')->save();
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $media = $this->createAudioMedia('public://tts/old-language.wav', 'Old language audio');
    $this->markMediaAsGenerated($media, 'Same public title', 'en');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Same public title',
      'langcode' => 'en',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $media->id()],
    ]);
    $node->save();
    $this->config('hear_me.settings')
      ->set('provider', 'piper')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $this->config('hear_me.provider.piper')
      ->set('supported_langs', ['en', 'uk'])
      ->save();

    $node->set('langcode', 'uk')->save();

    $this->assertTrue($this->reloadNode($node)->get('field_tts_audio')->isEmpty());
    $this->assertNull(Media::load($media->id()));
    $this->assertSame(1, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests oversized node source text is rejected before queue persistence.
   */
  public function testOversizedNodeSourceIsNotQueued(): void {
    $this->createContentType('article', 'Article');
    $this->config('hear_me.settings')
      ->set('queue_bundles', ['article'])
      ->set('max_text_length', 3)
      ->save();

    Node::create([
      'type' => 'article',
      'title' => 'Too long',
      'status' => 1,
    ])->save();

    $this->assertSame(0, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests every node in one backfill chunk uses its captured provider ID.
   */
  public function testBackfillChunkUsesCapturedProviderForEveryNode(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');

    Node::create([
      'type' => 'article',
      'title' => 'First node',
      'status' => 1,
      'langcode' => 'und',
    ])->save();
    Node::create([
      'type' => 'article',
      'title' => 'Second node',
      'status' => 1,
      'langcode' => 'und',
    ])->save();

    $this->config('hear_me.settings')
      ->set('provider', 'piper')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $backfill = new class(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('hear_me.node_audio_queue'),
      $this->container->get('hear_me.provider_resolver'),
      $this->container->get('hear_me.audio_field_validator'),
    ) extends HearMeExistingContentQueue {

      /**
       * Provider IDs received while queueing nodes.
       *
       * @var string[]
       */
      public array $providerIds = [];

      protected function queueNode(\Drupal\node\NodeInterface $node, bool $missingOnly, string $providerId): array {
        $this->providerIds[] = $providerId;
        $stats = parent::queueNode($node, $missingOnly, $providerId);
        if (count($this->providerIds) === 1) {
          $this->configFactory->getEditable('hear_me.settings')
            ->set('provider', 'test')
            ->save();
        }
        return $stats;
      }

    };

    $result = $backfill->queueNextBatch(['article'], TRUE, TRUE, 0, 2);

    $this->assertSame(2, $result['stats']['queued']);
    $this->assertSame(['piper', 'piper'], $backfill->providerIds);
  }

  /**
   * Tests synchronous backfill retains one effective provider across chunks.
   */
  public function testQueueAllKeepsCapturedEffectiveProviderBetweenChunks(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');

    Node::create(['type' => 'article', 'title' => 'First node', 'status' => 1])->save();
    Node::create(['type' => 'article', 'title' => 'Second node', 'status' => 1])->save();
    $this->config('hear_me.settings')
      ->set('provider', 'piper')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $this->container->get('config.factory')
      ->get('hear_me.settings')
      ->setSettingsOverride(['provider' => 'piper']);

    $backfill = new class(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $this->container->get('hear_me.node_audio_queue'),
      $this->container->get('hear_me.provider_resolver'),
      $this->container->get('hear_me.audio_field_validator'),
    ) extends HearMeExistingContentQueue {

      /**
       * Number of processed chunks.
       */
      private int $chunks = 0;

      /**
       * Provider IDs received while queueing chunks.
       *
       * @var string[]
       */
      public array $providerIds = [];

      public function queueNextBatch(
        array $bundles = [],
        bool $publishedOnly = TRUE,
        bool $missingOnly = TRUE,
        int $lastNid = 0,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        ?string $providerId = NULL,
      ): array {
        $this->providerIds[] = $providerId;
        $result = parent::queueNextBatch($bundles, $publishedOnly, $missingOnly, $lastNid, $batchSize, $providerId);
        $this->chunks++;
        if ($this->chunks === 1) {
          $storage = \Drupal::service('config.storage');
          $settings = $storage->read('hear_me.settings');
          $settings['provider'] = 'test';
          $storage->write('hear_me.settings', $settings);
        }
        return $result;
      }

    };

    $stats = $backfill->queueAll(['article'], TRUE, TRUE, 0, 1);

    $this->assertSame(2, $stats['queued']);
    $this->assertSame(['piper', 'piper', 'piper'], $backfill->providerIds);
    $this->assertSame('test', \Drupal::service('config.storage')->read('hear_me.settings')['provider']);
    $this->assertSame('piper', $this->container->get('hear_me.provider_resolver')->getActiveProviderId());
  }

  /**
   * Tests existing-content backfill skips identical pending queue jobs.
   */
  public function testExistingContentBackfillSkipsPendingDuplicateHashes(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');

    $node = Node::create([
      'type' => 'article',
      'title' => 'Readable title',
      'status' => 1,
    ]);
    $node->save();

    $this->config('hear_me.settings')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $queue = \Drupal::queue('hear_me_tts');
    $backfill = $this->container->get('hear_me.existing_content_queue');

    $first_stats = $backfill->queueAll(['article'], TRUE, TRUE);
    $second_stats = $backfill->queueAll(['article'], TRUE, TRUE);

    $this->assertSame(1, $first_stats['queued']);
    $this->assertSame(0, $first_stats['skipped_duplicate_queue']);
    $this->assertSame(0, $second_stats['queued']);
    $this->assertSame(1, $second_stats['skipped_duplicate_queue']);
    $this->assertSame(1, $queue->numberOfItems());
  }

  /**
   * Tests backfill distinguishes duplicate and failed publication outcomes.
   */
  public function testExistingContentBackfillReportsPublicationFailureSeparately(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Explicit publication outcomes',
      'status' => 1,
    ]);
    $node->save();

    $nodeAudioQueue = $this->createMock(HearMeNodeAudioQueue::class);
    $nodeAudioQueue->expects($this->exactly(2))
      ->method('resolveSupportedNodeLanguage')
      ->with($node, 'test')
      ->willReturn('en');
    $queueItem = [
      'nid' => (int) $node->id(),
      'content_hash' => str_repeat('4', 64),
    ];
    $nodeAudioQueue->expects($this->exactly(2))
      ->method('buildQueueItem')
      ->with($node, 'test')
      ->willReturn($queueItem);
    $nodeAudioQueue->expects($this->exactly(2))
      ->method('queueItem')
      ->with($queueItem)
      ->willReturnOnConsecutiveCalls(
        HearMeNodeAudioQueue::RESULT_FAILED,
        HearMeNodeAudioQueue::RESULT_DUPLICATE,
      );
    $backfill = new class(
      $this->container->get('config.factory'),
      $this->container->get('entity_type.manager'),
      $nodeAudioQueue,
      $this->container->get('hear_me.provider_resolver'),
      $this->container->get('hear_me.audio_field_validator'),
    ) extends HearMeExistingContentQueue {

      public function queueOne(Node $node, string $providerId): array {
        return parent::queueNode($node, TRUE, $providerId);
      }

    };

    $failed = $backfill->queueOne($node, 'test');
    $duplicate = $backfill->queueOne($node, 'test');

    $this->assertSame(1, $failed['failed_queue_publication']);
    $this->assertSame(0, $failed['skipped_duplicate_queue']);
    $this->assertSame(0, $duplicate['failed_queue_publication']);
    $this->assertSame(1, $duplicate['skipped_duplicate_queue']);
  }

  /**
   * Tests backfill reports unsupported languages separately from empty source.
   */
  public function testExistingContentBackfillReportsUnsupportedLanguage(): void {
    ConfigurableLanguage::createFromLangcode('uk')->save();
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('provider', 'piper')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $this->config('hear_me.provider.piper')
      ->set('supported_langs', ['en'])
      ->save();
    Node::create([
      'type' => 'article',
      'title' => 'Unsupported Ukrainian source',
      'langcode' => 'uk',
      'status' => 1,
    ])->save();

    $stats = $this->container->get('hear_me.existing_content_queue')->queueAll(['article'], TRUE, TRUE);

    $this->assertSame(1, $stats['skipped_unsupported_language']);
    $this->assertSame(0, $stats['skipped_source_empty']);
    $this->assertSame(0, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests marker supersession and the confirmed-failure budget.
   */
  public function testQueueMarkerSupersessionAndFailureBudget(): void {
    $contentHash = str_repeat('f', 64);
    $item = ['nid' => 123, 'content_hash' => $contentHash];
    $storeKey = '123.' . $contentHash;
    $store = $this->container->get('keyvalue')->get(HearMeNodeAudioQueue::STORE_COLLECTION);
    $nodeAudioQueue = $this->container->get('hear_me.node_audio_queue');

    $this->assertSame(HearMeNodeAudioQueue::RESULT_QUEUED, $nodeAudioQueue->queueItem($item));
    $this->assertSame(HearMeNodeAudioQueue::RESULT_DUPLICATE, $nodeAudioQueue->queueItem($item));
    $firstToken = $store->get($storeKey)['token'];
    $this->assertSame(1, $nodeAudioQueue->recordSynthesisFailure(123, $contentHash, $firstToken));
    $expiredMarker = $store->get($storeKey);
    $expiredMarker['queued_at'] = \Drupal::time()->getRequestTime() - 86401;
    $store->set($storeKey, $expiredMarker);

    $this->assertSame(HearMeNodeAudioQueue::RESULT_QUEUED, $nodeAudioQueue->queueItem($item));
    $secondToken = $store->get($storeKey)['token'];
    $this->assertNotSame($firstToken, $secondToken);
    $this->assertFalse($nodeAudioQueue->isCurrentQueueItem(123, $contentHash, $firstToken));
    $this->assertTrue($nodeAudioQueue->isCurrentQueueItem(123, $contentHash, $secondToken));
    $this->assertSame(0, $nodeAudioQueue->recordSynthesisFailure(123, $contentHash, $firstToken));
    $nodeAudioQueue->clearQueuedHash(123, $contentHash, $firstToken);
    $this->assertTrue($nodeAudioQueue->isCurrentQueueItem(123, $contentHash, $secondToken));
    $this->assertSame(1, $nodeAudioQueue->recordSynthesisFailure(123, $contentHash, $secondToken));
    $this->assertSame(2, $nodeAudioQueue->recordSynthesisFailure(123, $contentHash, $secondToken));
    $this->assertSame(3, $nodeAudioQueue->recordSynthesisFailure(123, $contentHash, $secondToken));
    $this->assertFalse($store->has($storeKey));
    $this->assertSame(2, \Drupal::queue('hear_me_tts')->numberOfItems());
  }

  /**
   * Tests a stale owner cannot mutate a newer marker.
   */
  public function testStaleQueueOwnerCannotMutateNewerMarker(): void {
    $contentHash = str_repeat('c', 64);
    $storeKey = '456.' . $contentHash;
    $store = $this->container->get('keyvalue')->get(HearMeNodeAudioQueue::STORE_COLLECTION);
    $marker = [
      'content_hash' => $contentHash,
      'failures' => 1,
      'nid' => 456,
      'published_at' => \Drupal::time()->getCurrentTime(),
      'queued_at' => \Drupal::time()->getRequestTime(),
      'token' => 'new-owner-token',
    ];
    $store->set($storeKey, $marker);

    $nodeAudioQueue = $this->container->get('hear_me.node_audio_queue');
    $this->assertSame(0, $nodeAudioQueue->recordSynthesisFailure(456, $contentHash, 'old-owner-token'));
    $nodeAudioQueue->clearQueuedHash(456, $contentHash, 'old-owner-token');
    $this->assertSame($marker, $store->get($storeKey));
  }

  /**
   * Tests a reclaimed item cannot replace a later completed attachment.
   */
  public function testReclaimedQueueItemCannotReplaceLaterCompletion(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Reclaimed queue source',
      'status' => 1,
    ]);
    $node->save();
    $queuedItem = \Drupal::queue('hear_me_tts')->claimItem();
    $this->assertIsObject($queuedItem);

    $earlyMedia = $this->createAudioMedia('public://tts/reclaimed-early.wav', 'Early worker audio');
    $laterMedia = $this->createAudioMedia('public://tts/reclaimed-later.wav', 'Later worker audio');
    $this->markMediaAsGenerated($earlyMedia, 'Reclaimed queue source');
    $this->markMediaAsGenerated($laterMedia, 'Reclaimed queue source');
    $nodeAudioQueue = $this->container->get('hear_me.node_audio_queue');
    $worker = NULL;
    $calls = 0;
    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->exactly(2))
      ->method('synthesize')
      ->willReturnCallback(function () use (&$calls, &$worker, $queuedItem, $earlyMedia, $laterMedia): MediaInterface {
        $calls++;
        if ($calls === 1) {
          $worker->processItem($queuedItem->data);
          return $earlyMedia;
        }
        return $laterMedia;
      });
    $worker = new HearMeQueueWorker(
      [],
      'hear_me_tts',
      [],
      $ttsService,
      $this->container->get('hear_me.node_audio_attacher'),
      $nodeAudioQueue,
      $this->container->get('hear_me.provider_resolver'),
    );

    $worker->processItem($queuedItem->data);

    $this->assertSame((int) $laterMedia->id(), (int) $this->reloadNode($node)->get('field_tts_audio')->target_id);
    $this->assertNull(Media::load($earlyMedia->id()));
    $this->assertNotNull(Media::load($laterMedia->id()));
    $this->assertFalse($nodeAudioQueue->isCurrentQueueItem(
      (int) $queuedItem->data['nid'],
      (string) $queuedItem->data['content_hash'],
      (string) $queuedItem->data['token'],
    ));
  }

  /**
   * Tests anonymous field denial excludes source text and its hash input.
   */
  public function testQueueSourceExcludesAnonymousDeniedField(): void {
    $this->createContentType('article', 'Article');
    $this->createSourceField('article', 'field_public_source', 'string', 'Public source');
    $this->createSourceField('article', 'field_private_source', 'string', 'Private source');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Unused title',
      'status' => 1,
      'field_public_source' => 'Public source',
      'field_private_source' => 'DENIED SENTINEL',
    ]);
    $node->save();
    $this->config('hear_me.settings')
      ->set('queue_bundles', ['article'])
      ->set('queue_source_fields', [
        'article' => [
          'title' => FALSE,
          'fields' => ['field_public_source:value', 'field_private_source:value'],
        ],
      ])
      ->save();
    $this->container->get('state')->set('hear_me_test.denied_anonymous_field', 'field_private_source');

    $nodeAudioQueue = $this->container->get('hear_me.node_audio_queue');
    $item = $nodeAudioQueue->buildQueueItem($node);

    $this->assertIsArray($item);
    $this->assertSame('Public source', $item['text']);
    $this->assertStringNotContainsString('DENIED SENTINEL', $item['text']);
    $this->assertSame(
      $nodeAudioQueue->buildContentHash('Public source', 'en', $item['source_config_hash']),
      $item['content_hash'],
    );
  }

  /**
   * Tests queue source text can use title and configured text fields.
   */
  public function testQueueSourceFieldsAreConfigurable(): void {
    $this->createContentType('article', 'Article');
    $this->createSourceField('article', 'field_summary_source', 'text_with_summary', 'Summary source');
    $this->createSourceField('article', 'field_intro_source', 'string', 'Intro source');
    $this->createQueueSourceTestFormat('hear_me_source');

    $node = Node::create([
      'type' => 'article',
      'title' => 'Readable title',
      'status' => 1,
      'field_summary_source' => [
        'value' => '<p>Body value</p>',
        'summary' => 'Body summary',
        'format' => 'hear_me_source',
      ],
      'field_intro_source' => 'Intro value',
    ]);
    $node->save();

    $settings = $this->config('hear_me.settings')
      ->set('queue_bundles', ['article']);

    $settings->set('queue_source_fields', [
      'article' => [
        'title' => TRUE,
        'fields' => ['field_summary_source:value'],
      ],
    ])->save();
    $item = $this->container->get('hear_me.node_audio_queue')->buildQueueItem($node);
    $this->assertSame('Readable title Body value', $item['text']);

    $settings->set('queue_source_fields', [
      'article' => [
        'title' => TRUE,
        'fields' => ['field_summary_source:value', 'field_intro_source:value'],
      ],
    ])->save();
    $item = $this->container->get('hear_me.node_audio_queue')->buildQueueItem($node);
    $this->assertSame('Readable title Body value Intro value', $item['text']);

    $settings->set('queue_source_fields', [
      'article' => [
        'title' => FALSE,
        'fields' => ['field_summary_source:value', 'field_summary_source:summary', 'field_intro_source:value'],
      ],
    ])->save();
    $item = $this->container->get('hear_me.node_audio_queue')->buildQueueItem($node);
    $this->assertSame('Body value Body summary Intro value', $item['text']);
  }

  /**
   * Tests formatted queue sources use anonymous filtered output and hashes.
   */
  public function testFormattedQueueSourcesUseAnonymousFilteredOutput(): void {
    $this->createContentType('article', 'Article');
    $this->createSourceField('article', 'field_formatted_source', 'text_with_summary', 'Formatted source');
    $this->createSourceField('article', 'field_plain_source', 'string', 'Plain source');
    $this->createQueueSourceTestFormat('hear_me_filtered');

    $node = Node::create([
      'type' => 'article',
      'title' => 'Unused title',
      'status' => 1,
      'field_formatted_source' => [
        'value' => '<p>Public body</p><private>BODY SENTINEL</private>',
        'summary' => '<p>Public summary</p><private>SUMMARY SENTINEL</private>',
        'format' => 'hear_me_filtered',
      ],
      'field_plain_source' => 'Plain source',
    ]);
    $node->save();
    $this->config('hear_me.settings')
      ->set('queue_bundles', ['article'])
      ->set('queue_source_fields', [
        'article' => [
          'title' => FALSE,
          'fields' => [
            'field_formatted_source:value',
            'field_formatted_source:summary',
            'field_plain_source:value',
          ],
        ],
      ])
      ->save();

    $currentUser = $this->container->get('current_user');
    $currentUser->setAccount(new UserSession([
      'uid' => 1,
      'name' => 'queue administrator',
      'roles' => [AccountInterface::AUTHENTICATED_ROLE],
    ]));
    $format = FilterFormat::load('hear_me_filtered');
    $this->assertNotNull($format);
    $this->assertFalse($format->access('use', new AnonymousUserSession()));
    $nodeAudioQueue = $this->container->get('hear_me.node_audio_queue');

    $item = $nodeAudioQueue->buildQueueItem($node);

    $this->assertIsArray($item);
    $this->assertSame('Public body Public summary Plain source', $item['text']);
    $this->assertStringNotContainsString('SENTINEL', $item['text']);
    $this->assertSame(
      $nodeAudioQueue->buildContentHash($item['text'], 'en', $item['source_config_hash']),
      $item['content_hash'],
    );
    $this->assertNotSame(
      $nodeAudioQueue->buildContentHash('Public body BODY SENTINEL Public summary SUMMARY SENTINEL Plain source', 'en', $item['source_config_hash']),
      $item['content_hash'],
    );
    $this->assertSame(1, $currentUser->id());

    $node->set('field_formatted_source', [
      'value' => '<p>Public body</p><private>CHANGED BODY SENTINEL</private>',
      'summary' => '<p>Public summary</p><private>CHANGED SUMMARY SENTINEL</private>',
      'format' => 'hear_me_filtered',
    ]);
    $changedItem = $nodeAudioQueue->buildQueueItem($node);
    $this->assertIsArray($changedItem);
    $this->assertSame($item['text'], $changedItem['text']);
    $this->assertSame($item['content_hash'], $changedItem['content_hash']);
  }

  /**
   * Tests unavailable formatted sources never fall back to raw stored text.
   */
  public function testUnavailableFormattedQueueSourcesFailClosed(): void {
    $this->createContentType('article', 'Article');
    $this->createSourceField('article', 'field_missing_format', 'text_long', 'Missing format');
    $this->createSourceField('article', 'field_empty_format', 'text_long', 'Empty format');
    $this->createSourceField('article', 'field_disabled_format', 'text_long', 'Disabled format');
    $this->createSourceField('article', 'field_failed_filter', 'text_long', 'Failed filter');
    $this->createSourceField('article', 'field_safe_source', 'string_long', 'Safe source');
    $this->createQueueSourceTestFormat('hear_me_disabled', FALSE);
    $this->createQueueSourceTestFormat('hear_me_failed');

    $node = Node::create([
      'type' => 'article',
      'title' => 'Unused title',
      'status' => 1,
      'field_missing_format' => [
        'value' => 'MISSING FORMAT SENTINEL',
        'format' => 'hear_me_missing',
      ],
      'field_empty_format' => [
        'value' => 'EMPTY FORMAT SENTINEL',
        'format' => NULL,
      ],
      'field_disabled_format' => [
        'value' => 'DISABLED FORMAT SENTINEL',
        'format' => 'hear_me_disabled',
      ],
      'field_failed_filter' => [
        'value' => 'FAILED FILTER SENTINEL',
        'format' => 'hear_me_failed',
      ],
      'field_safe_source' => 'Safe plain source',
    ]);
    $node->save();
    $settings = $this->config('hear_me.settings')
      ->set('queue_bundles', ['article']);
    $this->container->get('state')->set('hear_me_test.throw_queue_source_filter', TRUE);
    $currentUser = $this->container->get('current_user');
    $currentUser->setAccount(new UserSession([
      'uid' => 1,
      'name' => 'queue administrator',
      'roles' => [AccountInterface::AUTHENTICATED_ROLE],
    ]));
    $nodeAudioQueue = $this->container->get('hear_me.node_audio_queue');

    $settings->set('queue_source_fields', [
      'article' => [
        'title' => FALSE,
        'fields' => [
          'field_missing_format:value',
          'field_empty_format:value',
          'field_disabled_format:value',
          'field_failed_filter:value',
          'field_safe_source:value',
        ],
      ],
    ])->save();
    $item = $nodeAudioQueue->buildQueueItem($node);
    $this->assertIsArray($item);
    $this->assertSame('Safe plain source', $item['text']);
    $this->assertStringNotContainsString('SENTINEL', $item['text']);
    $this->assertSame(1, $currentUser->id());

    $settings->set('queue_source_fields', [
      'article' => [
        'title' => FALSE,
        'fields' => [
          'field_missing_format:value',
          'field_empty_format:value',
          'field_disabled_format:value',
          'field_failed_filter:value',
        ],
      ],
    ])->save();
    $this->assertNull($nodeAudioQueue->buildQueueItem($node));
    $this->assertSame(1, $currentUser->id());
  }

  /**
   * Tests source configuration changes affect queue content hashes.
   */
  public function testQueueSourceConfigurationChangesContentHash(): void {
    $this->createContentType('article', 'Article');
    $this->createSourceField('article', 'field_intro_source', 'string', 'Intro source');

    $node = Node::create([
      'type' => 'article',
      'title' => 'Same source',
      'status' => 1,
      'field_intro_source' => 'Same source',
    ]);
    $node->save();

    $settings = $this->config('hear_me.settings')
      ->set('queue_bundles', ['article']);

    $settings->set('queue_source_fields', [
      'article' => [
        'title' => TRUE,
        'fields' => [],
      ],
    ])->save();
    $titleOnly = $this->container->get('hear_me.node_audio_queue')->buildQueueItem($node);

    $settings->set('queue_source_fields', [
      'article' => [
        'title' => FALSE,
        'fields' => ['field_intro_source:value'],
      ],
    ])->save();
    $introOnly = $this->container->get('hear_me.node_audio_queue')->buildQueueItem($node);

    $this->assertSame($titleOnly['text'], $introOnly['text']);
    $this->assertNotSame($titleOnly['content_hash'], $introOnly['content_hash']);
  }

  /**
   * Tests explicit-language source comparison does not require a provider.
   */
  public function testExplicitLanguageSourceChangeWithoutActiveProviderIsNotQueued(): void {
    $this->createContentType('article', 'Article');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Original title',
      'langcode' => 'en',
    ]);
    $node->save();

    $this->config('hear_me.settings')
      ->set('queue_bundles', ['article'])
      ->clear('provider')
      ->save();

    $node->setTitle('Updated title');
    $node->save();

    $queue = \Drupal::queue('hear_me_tts');
    $this->assertSame(0, $queue->numberOfItems());
  }

  /**
   * Tests manually selected audio is not overwritten by default.
   */
  public function testManualAudioIsNotOverwrittenByDefault(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('tts_audio_field', 'field_tts_audio')
      ->set('overwrite_manual_audio', FALSE)
      ->save();

    $manual_media = $this->createAudioMedia('public://tts/editor-upload.wav', 'Manual audio');
    $generated_media = $this->createAudioMedia('public://tts/generated.wav', 'Generated audio');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Node with manual audio',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $manual_media->id()],
    ]);
    $node->save();

    $this->container->get('hear_me.node_audio_attacher')->attach((int) $node->id(), $generated_media);

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());

    $this->assertSame((int) $manual_media->id(), (int) $reloaded->get('field_tts_audio')->target_id);
  }

  /**
   * Tests unsaved media is not attached to a node.
   */
  public function testUnsavedMediaIsNotAttached(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Node without audio',
    ]);
    $node->save();
    $revision_ids = $this->getNodeRevisionIds((int) $node->id());
    $media = Media::create([
      'bundle' => 'hear_me_audio',
      'name' => 'Unsaved audio',
    ]);

    $this->container->get('hear_me.node_audio_attacher')->attach((int) $node->id(), $media);

    $this->assertTrue($this->reloadNode($node)->get('field_tts_audio')->isEmpty());
    $this->assertSame($revision_ids, $this->getNodeRevisionIds((int) $node->id()));
  }

  /**
   * Tests missing nodes and configured fields are ignored.
   */
  public function testMissingNodeAndFieldAreIgnored(): void {
    $this->createContentType('article', 'Article');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Node without configured field',
    ]);
    $node->save();
    $revision_ids = $this->getNodeRevisionIds((int) $node->id());
    $media = $this->createAudioMedia('public://tts/missing-target.wav', 'Missing target audio');
    $attacher = $this->container->get('hear_me.node_audio_attacher');

    $attacher->attach(999999, $media);
    $attacher->attach((int) $node->id(), $media);

    $this->assertSame('Node without configured field', $this->reloadNode($node)->label());
    $this->assertSame($revision_ids, $this->getNodeRevisionIds((int) $node->id()));
  }

  /**
   * Tests an entity reference with the wrong target type is ignored.
   */
  public function testWrongFieldTargetTypeIsIgnored(): void {
    $this->createContentType('article', 'Article');
    FieldStorageConfig::create([
      'field_name' => 'field_tts_audio',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'file'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_tts_audio',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Wrong audio target',
    ])->save();
    $node = Node::create([
      'type' => 'article',
      'title' => 'Node with file reference',
    ]);
    $node->save();
    $revision_ids = $this->getNodeRevisionIds((int) $node->id());
    $media = $this->createAudioMedia('public://tts/wrong-target.wav', 'Wrong target audio');

    $this->container->get('hear_me.node_audio_attacher')->attach((int) $node->id(), $media);

    $this->assertTrue($this->reloadNode($node)->get('field_tts_audio')->isEmpty());
    $this->assertSame($revision_ids, $this->getNodeRevisionIds((int) $node->id()));
  }

  /**
   * Tests attaching the same media leaves the existing reference unchanged.
   */
  public function testSameMediaAlreadyAttachedIsUnchanged(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $media = $this->createAudioMedia('public://tts/already-attached.wav', 'Already attached audio');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Node with generated audio',
      'field_tts_audio' => ['target_id' => $media->id()],
    ]);
    $node->save();
    $revision_ids = $this->getNodeRevisionIds((int) $node->id());

    $this->container->get('hear_me.node_audio_attacher')->attach((int) $node->id(), $media);

    $reloaded = $this->reloadNode($node);
    $this->assertSame((int) $media->id(), (int) $reloaded->get('field_tts_audio')->target_id);
    $this->assertSame($revision_ids, $this->getNodeRevisionIds((int) $node->id()));
  }

  /**
   * Tests generated audio replacement honors its configuration setting.
   */
  public function testGeneratedAudioReplacementRespectsConfiguration(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $existing = $this->createAudioMedia('public://tts/existing-generated.wav', 'Existing generated audio');
    $this->markMediaAsGenerated($existing);
    $replacement = $this->createAudioMedia('public://tts/replacement-generated.wav', 'Replacement generated audio');
    $disabled_node = Node::create([
      'type' => 'article',
      'title' => 'Generated replacement disabled',
      'field_tts_audio' => ['target_id' => $existing->id()],
    ]);
    $disabled_node->save();
    $disabled_revision_ids = $this->getNodeRevisionIds((int) $disabled_node->id());
    $enabled_node = Node::create([
      'type' => 'article',
      'title' => 'Generated replacement enabled',
      'field_tts_audio' => ['target_id' => $existing->id()],
    ]);
    $enabled_node->save();
    $settings = $this->config('hear_me.settings');
    $attacher = $this->container->get('hear_me.node_audio_attacher');

    $settings->set('replace_existing_generated_audio', FALSE)->save();
    $attacher->attach((int) $disabled_node->id(), $replacement);
    $settings->set('replace_existing_generated_audio', TRUE)->save();
    $attacher->attach((int) $enabled_node->id(), $replacement);

    $this->assertSame((int) $existing->id(), (int) $this->reloadNode($disabled_node)->get('field_tts_audio')->target_id);
    $this->assertSame($disabled_revision_ids, $this->getNodeRevisionIds((int) $disabled_node->id()));
    $this->assertSame((int) $replacement->id(), (int) $this->reloadNode($enabled_node)->get('field_tts_audio')->target_id);
  }

  /**
   * Tests manually selected audio can be overwritten when enabled.
   */
  public function testManualAudioCanBeOverwritten(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('overwrite_manual_audio', TRUE)
      ->save();
    $manual_media = $this->createAudioMedia('public://manual/overwrite.wav', 'Manual audio');
    $generated_media = $this->createAudioMedia('public://tts/manual-replacement.wav', 'Generated replacement');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Node with replaceable manual audio',
      'field_tts_audio' => ['target_id' => $manual_media->id()],
    ]);
    $node->save();

    $this->container->get('hear_me.node_audio_attacher')->attach((int) $node->id(), $generated_media);

    $this->assertSame((int) $generated_media->id(), (int) $this->reloadNode($node)->get('field_tts_audio')->target_id);
  }

  /**
   * Tests a field validation failure does not persist the new reference.
   */
  public function testFieldValidationFailureDoesNotPersistReference(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article', 'field_tts_audio', 1, [
      'unsupported_bundle' => 'unsupported_bundle',
    ]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Node with restricted audio field',
    ]);
    $node->save();
    $revision_ids = $this->getNodeRevisionIds((int) $node->id());
    $media = $this->createAudioMedia('public://tts/invalid-reference.wav', 'Invalid reference audio');

    $this->container->get('hear_me.node_audio_attacher')->attach((int) $node->id(), $media);

    $this->assertTrue($this->reloadNode($node)->get('field_tts_audio')->isEmpty());
    $this->assertSame($revision_ids, $this->getNodeRevisionIds((int) $node->id()));
  }

  /**
   * Tests mixed and missing existing references are treated as manual audio.
   */
  public function testMixedAndMissingExistingMediaReferencesArePreserved(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article', 'field_tts_audio', -1);
    $generated = $this->createAudioMedia('public://tts/existing-mixed.wav', 'Existing generated audio');
    $this->markMediaAsGenerated($generated);
    $manual = $this->createAudioMedia('public://manual/existing-mixed.wav', 'Existing manual audio');
    $replacement = $this->createAudioMedia('public://tts/mixed-replacement.wav', 'Generated replacement');
    $mixed_node = Node::create([
      'type' => 'article',
      'title' => 'Mixed references',
      'field_tts_audio' => [
        ['target_id' => $generated->id()],
        ['target_id' => $manual->id()],
      ],
    ]);
    $mixed_node->save();
    $missing_node = Node::create([
      'type' => 'article',
      'title' => 'Missing reference',
      'field_tts_audio' => [
        ['target_id' => $generated->id()],
        ['target_id' => 999999],
      ],
    ]);
    $missing_node->save();

    $attacher = $this->container->get('hear_me.node_audio_attacher');
    $attacher->attach((int) $mixed_node->id(), $replacement);
    $attacher->attach((int) $missing_node->id(), $replacement);

    $this->assertSame(
      [(int) $generated->id(), (int) $manual->id()],
      $this->getReferencedMediaIds($this->reloadNode($mixed_node)),
    );
    $this->assertSame(
      [(int) $generated->id(), 999999],
      $this->getReferencedMediaIds($this->reloadNode($missing_node)),
    );
  }

  /**
   * Tests worker audio attachment does not enqueue another identical job.
   */
  public function testQueueWorkerAttachmentDoesNotRequeueAudioFieldUpdate(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('queue_bundles', ['article'])
      ->set('queue_source_fields', [
        'article' => [
          'title' => TRUE,
          'fields' => [],
        ],
      ])
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Queued title',
      'status' => 1,
    ]);
    $node->save();

    $queue = \Drupal::queue('hear_me_tts');
    $this->assertSame(1, $queue->numberOfItems());

    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $this->container->get('plugin.manager.queue_worker')
      ->createInstance('hear_me_tts')
      ->processItem($item->data);
    $queue->deleteItem($item);

    $this->assertSame(0, $queue->numberOfItems());

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());
    $this->assertFalse($reloaded->get('field_tts_audio')->isEmpty());
  }

  /**
   * Tests generated audio attachment does not request a new node revision.
   */
  public function testGeneratedAudioAttachmentDoesNotRequestNewRevision(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $this->config('hear_me.settings')
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Revision-safe node',
      'status' => 1,
    ]);
    $node->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $revisionIdsBefore = $this->getNodeRevisionIds((int) $node->id());

    $media = $this->createAudioMedia('public://tts/revision-safe.wav', 'Revision-safe audio');
    $this->container->get('hear_me.node_audio_attacher')->attach((int) $node->id(), $media);

    $storage->resetCache([$node->id()]);
    $reloaded = $storage->load($node->id());

    $this->assertSame($revisionIdsBefore, $this->getNodeRevisionIds((int) $reloaded->id()));
    $this->assertSame((int) $media->id(), (int) $reloaded->get('field_tts_audio')->target_id);
  }

  /**
   * Tests generated audio attachment preserves moderation state and status.
   */
  public function testGeneratedAudioAttachmentPreservesModerationState(): void {
    $this->createContentType('article', 'Article');
    $this->createAudioReferenceField('article');
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'article');
    $this->config('hear_me.settings')
      ->set('tts_audio_field', 'field_tts_audio')
      ->save();

    $draft = Node::create([
      'type' => 'article',
      'title' => 'Moderated draft',
      'moderation_state' => 'draft',
    ]);
    $draft->save();
    $published = Node::create([
      'type' => 'article',
      'title' => 'Moderated published',
      'moderation_state' => 'published',
    ]);
    $published->save();

    $draftMedia = $this->createAudioMedia('public://tts/moderated-draft.wav', 'Draft audio');
    $publishedMedia = $this->createAudioMedia('public://tts/moderated-published.wav', 'Published audio');
    $attacher = $this->container->get('hear_me.node_audio_attacher');
    $attacher->attach((int) $draft->id(), $draftMedia);
    $attacher->attach((int) $published->id(), $publishedMedia);

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$draft->id(), $published->id()]);
    $reloadedDraft = $storage->load($draft->id());
    $reloadedPublished = $storage->load($published->id());

    $this->assertSame('draft', $reloadedDraft->get('moderation_state')->value);
    $this->assertFalse($reloadedDraft->isPublished());
    $this->assertSame((int) $draftMedia->id(), (int) $reloadedDraft->get('field_tts_audio')->target_id);
    $this->assertSame('published', $reloadedPublished->get('moderation_state')->value);
    $this->assertTrue($reloadedPublished->isPublished());
    $this->assertSame((int) $publishedMedia->id(), (int) $reloadedPublished->get('field_tts_audio')->target_id);
  }

  /**
   * Creates a content type for node tests.
   */
  protected function createContentType(string $type, string $label): void {
    NodeType::create([
      'type' => $type,
      'name' => $label,
    ])->save();
  }

  /**
   * Creates the node media reference field used by queue-generated audio.
   */
  protected function createAudioReferenceField(
    string $bundle,
    string $field_name = 'field_tts_audio',
    int $cardinality = 1,
    array $target_bundles = ['hear_me_audio' => 'hear_me_audio'],
  ): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'media',
        ],
        'cardinality' => $cardinality,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'HearMe audio',
        'settings' => [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => $target_bundles,
          ],
        ],
      ])->save();
    }
  }

  /**
   * Creates a source field used by queue source text tests.
   */
  protected function createSourceField(string $bundle, string $field_name, string $type, string $label): void {
    $storage = FieldStorageConfig::loadByName('node', $field_name);
    if ($storage) {
      $this->assertSame($type, $storage->getType(), "The $field_name field already exists with an unexpected type.");
    }
    else {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => $type,
      ])->save();
    }

    if (!FieldConfig::loadByName('node', $bundle, $field_name)) {
      FieldConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => $label,
      ])->save();
    }
  }

  /**
   * Creates the formatted-text filter used by queue source tests.
   */
  protected function createQueueSourceTestFormat(string $id, bool $status = TRUE): void {
    FilterFormat::create([
      'format' => $id,
      'name' => $id,
      'status' => $status,
      'filters' => [
        'hear_me_test_queue_source' => [
          'status' => TRUE,
          'weight' => 0,
          'settings' => [],
        ],
      ],
    ])->save();
  }

  /**
   * Creates a HearMe Audio media entity backed by a file entity.
   */
  protected function createAudioMedia(string $uri, string $name): MediaInterface {
    $file = File::create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->save();

    $media = Media::create([
      'bundle' => 'hear_me_audio',
      'name' => $name,
      'field_hear_me_audio_file' => ['target_id' => $file->id()],
    ]);
    $media->save();

    return $media;
  }

  /**
   * Records persistent HearMe provenance for a generated Media entity.
   */
  protected function markMediaAsGenerated(MediaInterface $media, string $text = 'generated', string $lang = 'en'): void {
    $file = $media->get('field_hear_me_audio_file')->entity;
    $this->assertInstanceOf(File::class, $file);
    $now = \Drupal::time()->getRequestTime();
    $this->container->get('database')->insert('hear_me_audio_cache')
      ->fields([
        'cid' => hash('sha256', (string) $file->id()),
        'uri' => $file->getFileUri(),
        'fid' => $file->id(),
        'source' => 'entity',
        'provider' => 'test',
        'langcode' => $lang,
        'text_hash' => hash('sha256', $text),
        'config_hash' => hash('sha256', 'config'),
        'extension' => 'wav',
        'mime_type' => 'audio/wav',
        'filesize' => 1,
        'created' => $now,
        'changed' => $now,
        'last_accessed' => $now,
        'expires' => 0,
        'access_count' => 0,
      ])
      ->execute();
  }

  /**
   * Reloads a node from persistent storage.
   */
  protected function reloadNode(Node $node): Node {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $storage->resetCache([$node->id()]);
    return $storage->load($node->id());
  }

  /**
   * Returns the target IDs from the configured audio field.
   *
   * @return int[]
   *   Referenced Media IDs.
   */
  protected function getReferencedMediaIds(Node $node): array {
    return array_map(
      static fn(array $item): int => (int) $item['target_id'],
      $node->get('field_tts_audio')->getValue(),
    );
  }

  /**
   * Returns node revision IDs in ascending order.
   *
   * @return int[]
   *   Revision IDs.
   */
  protected function getNodeRevisionIds(int $nid): array {
    $ids = $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->getQuery()
      ->allRevisions()
      ->accessCheck(FALSE)
      ->condition('nid', $nid)
      ->sort('vid')
      ->execute();

    return array_values(array_map('intval', $ids));
  }

}
