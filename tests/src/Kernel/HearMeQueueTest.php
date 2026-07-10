<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\hear_me\Plugin\QueueWorker\HearMeQueueWorker;
use Drupal\hear_me\Service\HearMeNodeAudioQueue;
use Drupal\hear_me\Service\HearMeService;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests queue and generated audio attachment behavior.
 */
#[Group('hear_me')]
#[RunTestsInSeparateProcesses]
class HearMeQueueTest extends EntityKernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('hear_me', ['hear_me_audio_cache']);
    $this->installConfig(['node', 'file', 'image', 'media', 'hear_me']);
  }

  /**
   * Tests stale queue items are ignored before synthesis starts.
   */
  public function testStaleQueueItemIsSkipped(): void {
    $tts_service = $this->createMock(HearMeService::class);
    $tts_service->expects($this->never())->method('synthesize');
    $tts_service->expects($this->never())->method('attachMediaToNode');

    $node_audio_queue = $this->createMock(HearMeNodeAudioQueue::class);
    $node_audio_queue->expects($this->once())
      ->method('buildCurrentQueueItem')
      ->with(1)
      ->willReturn([
        'text' => 'Current text',
        'lang' => 'en',
        'content_hash' => str_repeat('a', 64),
      ]);

    $worker = new HearMeQueueWorker([], 'hear_me_tts', [], $tts_service, $node_audio_queue);
    $worker->processItem([
      'nid' => 1,
      'text' => 'Old text',
      'lang' => 'en',
      'content_hash' => str_repeat('b', 64),
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
  protected function createAudioReferenceField(string $bundle, string $field_name = 'field_tts_audio'): void {
    if (!FieldStorageConfig::loadByName('node', $field_name)) {
      FieldStorageConfig::create([
        'field_name' => $field_name,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'media',
        ],
        'cardinality' => 1,
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
            'target_bundles' => [
              'hear_me_audio' => 'hear_me_audio',
            ],
          ],
        ],
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

}
