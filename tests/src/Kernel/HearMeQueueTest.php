<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\hear_me\Plugin\QueueWorker\HearMeQueueWorker;
use Drupal\hear_me\Service\HearMeExistingContentQueue;
use Drupal\hear_me\Service\HearMeNodeAudioQueue;
use Drupal\hear_me\Service\HearMeService;
use Drupal\hear_me\Service\TtsProviderResolver;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
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
    $this->installConfig(['node', 'file', 'image', 'media', 'hear_me', 'content_moderation']);
  }

  /**
   * Tests stale queue items are ignored before synthesis starts.
   */
  public function testStaleQueueItemIsSkipped(): void {
    $this->config('hear_me.settings')->set('provider', 'piper')->save();

    $tts_service = $this->createMock(HearMeService::class);
    $tts_service->expects($this->never())->method('synthesize');
    $tts_service->expects($this->never())->method('attachMediaToNode');

    $node_audio_queue = $this->createMock(HearMeNodeAudioQueue::class);
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
      ->with(1, str_repeat('b', 64));

    $provider_resolver = $this->container->get('hear_me.provider_resolver');
    $this->assertInstanceOf(TtsProviderResolver::class, $provider_resolver);
    $worker = new HearMeQueueWorker([], 'hear_me_tts', [], $tts_service, $node_audio_queue, $provider_resolver);
    $worker->processItem([
      'nid' => 1,
      'text' => 'Old text',
      'lang' => 'en',
      'content_hash' => str_repeat('b', 64),
    ]);
  }

  /**
   * Tests one provider ID is used to rebuild and synthesize a queue item.
   */
  public function testQueueItemUsesCapturedProviderId(): void {
    $this->config('hear_me.settings')->set('provider', 'test')->save();

    $content_hash = str_repeat('a', 64);
    $tts_service = $this->createMock(HearMeService::class);
    $tts_service->expects($this->once())
      ->method('synthesize')
      ->with('Current text', 'en', 'test')
      ->willReturn(NULL);
    $tts_service->expects($this->never())->method('attachMediaToNode');

    $node_audio_queue = $this->createMock(HearMeNodeAudioQueue::class);
    $node_audio_queue->expects($this->once())
      ->method('buildCurrentQueueItem')
      ->with(1, 'test')
      ->willReturn([
        'text' => 'Current text',
        'lang' => 'en',
        'content_hash' => $content_hash,
      ]);
    $node_audio_queue->expects($this->once())
      ->method('clearQueuedHash')
      ->with(1, $content_hash);

    $provider_resolver = $this->container->get('hear_me.provider_resolver');
    $this->assertInstanceOf(TtsProviderResolver::class, $provider_resolver);
    $worker = new HearMeQueueWorker([], 'hear_me_tts', [], $tts_service, $node_audio_queue, $provider_resolver);
    $worker->processItem([
      'nid' => 1,
      'text' => 'Current text',
      'lang' => 'en',
      'content_hash' => $content_hash,
    ]);
  }

  /**
   * Tests a legacy item uses the default language before its stale check.
   */
  public function testLegacyQueueItemUsesDefaultLanguageBeforeStaleCheck(): void {
    $this->config('hear_me.settings')->set('provider', 'piper')->save();

    $tts_service = $this->createMock(HearMeService::class);
    $tts_service->expects($this->never())->method('synthesize');
    $tts_service->expects($this->never())->method('attachMediaToNode');

    $legacy_hash = str_repeat('c', 64);
    $current_hash = str_repeat('d', 64);
    $node_audio_queue = $this->createMock(HearMeNodeAudioQueue::class);
    $node_audio_queue->expects($this->once())
      ->method('buildContentHash')
      ->with('Legacy queue text', 'uk')
      ->willReturn($legacy_hash);
    $node_audio_queue->expects($this->once())
      ->method('buildCurrentQueueItem')
      ->with(1, 'piper')
      ->willReturn([
        'text' => 'Legacy queue text',
        'lang' => 'uk',
        'content_hash' => $current_hash,
      ]);
    $node_audio_queue->expects($this->once())
      ->method('clearQueuedHash')
      ->with(1, $legacy_hash);

    $this->config('hear_me.provider.piper')->set('default_lang', 'uk')->save();
    $provider_resolver = $this->container->get('hear_me.provider_resolver');
    $this->assertInstanceOf(TtsProviderResolver::class, $provider_resolver);
    $worker = new HearMeQueueWorker([], 'hear_me_tts', [], $tts_service, $node_audio_queue, $provider_resolver);
    $worker->processItem([
      'nid' => 1,
      'text' => 'Legacy queue text',
    ]);
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
    $this->assertSame('en', $item->data['lang']);
    $this->assertNotEmpty($item->data['content_hash']);
    $queue->deleteItem($item);
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
   * Tests queue source text can use title and configured text fields.
   */
  public function testQueueSourceFieldsAreConfigurable(): void {
    $this->createContentType('article', 'Article');
    $this->createSourceField('article', 'field_summary_source', 'text_with_summary', 'Summary source');
    $this->createSourceField('article', 'field_intro_source', 'string', 'Intro source');

    $node = Node::create([
      'type' => 'article',
      'title' => 'Readable title',
      'status' => 1,
      'field_summary_source' => [
        'value' => '<p>Body value</p>',
        'summary' => 'Body summary',
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
  public function testExplicitLanguageSourceChangeWithoutActiveProvider(): void {
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
    $this->assertSame(1, $queue->numberOfItems());
    $item = $queue->claimItem();
    $this->assertNotFalse($item);
    $this->assertSame('Updated title', $item->data['text']);
    $this->assertSame('en', $item->data['lang']);
    $queue->deleteItem($item);
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

    $manual_media = $this->createAudioMedia('public://manual/manual.wav', 'Manual audio');
    $generated_media = $this->createAudioMedia('public://tts/generated.wav', 'Generated audio');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Node with manual audio',
      'status' => 1,
      'field_tts_audio' => ['target_id' => $manual_media->id()],
    ]);
    $node->save();

    $this->container->get('hear_me.service')->attachMediaToNode((int) $node->id(), $generated_media);

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

    $this->container->get('hear_me.service')->attachMediaToNode((int) $node->id(), $media);

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
    $service = $this->container->get('hear_me.service');

    $service->attachMediaToNode(999999, $media);
    $service->attachMediaToNode((int) $node->id(), $media);

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

    $this->container->get('hear_me.service')->attachMediaToNode((int) $node->id(), $media);

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

    $this->container->get('hear_me.service')->attachMediaToNode((int) $node->id(), $media);

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
    $service = $this->container->get('hear_me.service');

    $settings->set('replace_existing_generated_audio', FALSE)->save();
    $service->attachMediaToNode((int) $disabled_node->id(), $replacement);
    $settings->set('replace_existing_generated_audio', TRUE)->save();
    $service->attachMediaToNode((int) $enabled_node->id(), $replacement);

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

    $this->container->get('hear_me.service')->attachMediaToNode((int) $node->id(), $generated_media);

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

    $this->container->get('hear_me.service')->attachMediaToNode((int) $node->id(), $media);

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

    $service = $this->container->get('hear_me.service');
    $service->attachMediaToNode((int) $mixed_node->id(), $replacement);
    $service->attachMediaToNode((int) $missing_node->id(), $replacement);

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
    $this->container->get('hear_me.service')->attachMediaToNode((int) $node->id(), $media);

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
    $service = $this->container->get('hear_me.service');
    $service->attachMediaToNode((int) $draft->id(), $draftMedia);
    $service->attachMediaToNode((int) $published->id(), $publishedMedia);

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
