<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\hear_me\Plugin\TtsProvider\PiperProvider;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderManager;
use Drupal\hear_me\Plugin\QueueWorker\HearMeGeneratedAudioCleanupWorker;
use Drupal\hear_me\Plugin\QueueWorker\HearMeQueueWorker;
use Drupal\media\Entity\MediaType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests install-time release defaults and plugin discovery.
 */
#[Group('hear_me')]
#[RunTestsInSeparateProcesses]
class HearMeInstallTest extends KernelTestBase {

  use SchemaCheckTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system'];

  /**
   * Tests that HearMe installs with release-safe defaults.
   */
  public function testModuleInstallsWithReleaseSafeDefaults(): void {
    $this->container->get('module_installer')->install(['hear_me']);
    $this->container = \Drupal::getContainer();

    $this->assertTrue($this->container->get('module_handler')->moduleExists('hear_me'));
    $this->assertTrue($this->container->get('module_handler')->moduleExists('node'));
    $this->assertTrue($this->container->get('database')->schema()->tableExists('hear_me_audio_cache'));
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('media_type')->load('hear_me_audio'));

    $settings = $this->config('hear_me.settings');
    $this->assertSame('piper', $settings->get('provider'));
    $this->assertSame('private', $settings->get('runtime_cache_scheme'));
    $this->assertSame([], $settings->get('queue_bundles'));
    $this->assertSame([], $settings->get('queue_source_fields'));
    $this->assertTrue($settings->get('replace_existing_generated_audio'));
    $this->assertFalse($settings->get('overwrite_manual_audio'));

    $piper = $this->config('hear_me.provider.piper');
    $this->assertSame('', $piper->get('endpoint'));
    $this->assertFalse($piper->get('allow_private_endpoint_urls'));

    $typed_config = $this->container->get('config.typed');
    $this->assertConfigSchema($typed_config, 'hear_me.settings', $settings->get());
    $this->assertConfigSchema($typed_config, 'hear_me.provider.piper', $piper->get());
  }

  /**
   * Tests that HearMe can be uninstalled cleanly.
   */
  public function testModuleUninstallsCleanly(): void {
    $this->container->get('module_installer')->install(['hear_me']);
    $this->container = \Drupal::getContainer();
    $this->assertTrue($this->container->get('database')->schema()->tableExists('hear_me_audio_cache'));
    $this->container->get('queue')->get('hear_me_tts')->createItem([
      'nid' => 1,
      'content_hash' => str_repeat('a', 64),
    ]);
    $this->container->get('queue')->get('hear_me_generated_audio_cleanup')->createItem(['media_id' => 1]);
    $this->container->get('state')->set('hear_me.queued_hash.1.' . str_repeat('a', 64), TRUE);
    $this->container->get('keyvalue')->get('hear_me.generated_audio_cleanup')->set('1', TRUE);
    $file = $this->container->get('entity_type.manager')->getStorage('file')->create([
      'uri' => 'public://tts/reused-runtime.wav',
      'status' => 1,
    ]);
    $file->save();
    $this->container->get('file.usage')->add($file, 'system', 'test', 'reused-runtime');
    $now = $this->container->get('datetime.time')->getRequestTime();
    $this->container->get('database')->insert('hear_me_audio_cache')
      ->fields([
        'cid' => hash('sha256', 'reused-runtime'),
        'uri' => $file->getFileUri(),
        'fid' => $file->id(),
        'source' => 'page',
        'provider' => 'piper',
        'langcode' => 'en',
        'text_hash' => hash('sha256', 'runtime'),
        'config_hash' => hash('sha256', 'config'),
        'extension' => 'wav',
        'mime_type' => 'audio/wav',
        'filesize' => 1,
        'created' => $now,
        'changed' => $now,
        'last_accessed' => $now,
        'expires' => $now + 3600,
        'access_count' => 0,
      ])
      ->execute();

    $this->container->get('module_installer')->uninstall(['hear_me']);
    $this->container = \Drupal::getContainer();

    $this->assertFalse($this->container->get('module_handler')->moduleExists('hear_me'));
    $this->assertTrue($this->config('hear_me.settings')->isNew());
    $this->assertTrue($this->config('hear_me.provider.piper')->isNew());
    $this->assertFalse($this->container->get('database')->schema()->tableExists('hear_me_audio_cache'));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('media_type')->load('hear_me_audio'));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('field_storage_config')->load('media.field_hear_me_audio_file'));
    $this->assertSame(0, $this->container->get('queue')->get('hear_me_tts')->numberOfItems());
    $this->assertSame(0, $this->container->get('queue')->get('hear_me_generated_audio_cleanup')->numberOfItems());
    $this->assertNull($this->container->get('state')->get('hear_me.queued_hash.1.' . str_repeat('a', 64)));
    $this->assertFalse($this->container->get('keyvalue')->get('hear_me.generated_audio_cleanup')->has('1'));
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('file')->load($file->id()));
  }

  /**
   * Tests persistent audio content prevents destructive uninstall.
   */
  public function testPersistentAudioBlocksUninstall(): void {
    $this->container->get('module_installer')->install(['hear_me']);
    $this->container = \Drupal::getContainer();
    $file = $this->container->get('entity_type.manager')->getStorage('file')->create([
      'uri' => 'public://tts/preserved.wav',
      'status' => 1,
    ]);
    $file->save();
    $media = $this->container->get('entity_type.manager')->getStorage('media')->create([
      'bundle' => 'hear_me_audio',
      'name' => 'Preserved audio',
      'field_hear_me_audio_file' => ['target_id' => $file->id()],
    ]);
    $media->save();

    $reasons = $this->container->get('module_installer')->validateUninstall(['hear_me']);

    $this->assertArrayHasKey('hear_me', $reasons);
    $this->assertStringContainsString('Remove or migrate', (string) reset($reasons['hear_me']));
  }

  /**
   * Tests reused module-owned field storage prevents destructive uninstall.
   */
  public function testReusedAudioFileFieldStorageBlocksUninstall(): void {
    $this->container->get('module_installer')->install(['hear_me']);
    $this->container = \Drupal::getContainer();
    MediaType::create([
      'id' => 'shared_audio',
      'label' => 'Shared audio',
      'source' => 'audio_file',
      'source_configuration' => [
        'source_field' => 'field_hear_me_audio_file',
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_hear_me_audio_file',
      'entity_type' => 'media',
      'bundle' => 'shared_audio',
      'label' => 'Shared audio file',
    ])->save();

    $reasons = $this->container->get('module_installer')->validateUninstall(['hear_me']);

    $this->assertArrayHasKey('hear_me', $reasons);
    $this->assertStringContainsString('reused by other media types', (string) reset($reasons['hear_me']));
  }

  /**
   * Tests that the queue worker plugin is discoverable.
   */
  public function testQueueWorkerDiscovery(): void {
    $this->container->get('module_installer')->install(['hear_me']);
    $this->container = \Drupal::getContainer();

    $manager = $this->container->get('plugin.manager.queue_worker');
    $definition = $manager->getDefinition('hear_me_tts');

    $this->assertSame('HearMe TTS Queue Worker', (string) $definition['title']);
    $this->assertSame(30, $definition['cron']['time']);
    $this->assertInstanceOf(HearMeQueueWorker::class, $manager->createInstance('hear_me_tts'));
    $cleanupDefinition = $manager->getDefinition('hear_me_generated_audio_cleanup');
    $this->assertSame('HearMe Generated Audio Cleanup', (string) $cleanupDefinition['title']);
    $this->assertSame(30, $cleanupDefinition['cron']['time']);
    $this->assertInstanceOf(HearMeGeneratedAudioCleanupWorker::class, $manager->createInstance('hear_me_generated_audio_cleanup'));
  }

  /**
   * Tests provider manager installation, discovery, and cache rebuilding.
   */
  public function testProviderManagerDiscoveryAndCacheClear(): void {
    $this->container->get('module_installer')->install(['hear_me']);
    $this->container = \Drupal::getContainer();

    $manager = $this->container->get('plugin.manager.hear_me.tts_provider');
    $this->assertInstanceOf(TtsProviderManager::class, $manager);
    $definitions = $manager->getDefinitions();
    $this->assertSame(['piper'], array_keys($definitions));
    $this->assertSame('piper', $definitions['piper']['id']);
    $this->assertSame('Piper (self-hosted)', (string) $definitions['piper']['label']);
    $this->assertSame(PiperProvider::class, $definitions['piper']['class']);

    $manager->clearCachedDefinitions();
    $rebuiltDefinitions = $manager->getDefinitions();
    $this->assertSame(['piper'], array_keys($rebuiltDefinitions));
    $this->assertSame('Piper (self-hosted)', (string) $rebuiltDefinitions['piper']['label']);
    $this->assertSame(PiperProvider::class, $rebuiltDefinitions['piper']['class']);
    $this->assertInstanceOf(PiperProvider::class, $manager->createInstance('piper', []));
  }

}
