<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\hear_me\Plugin\QueueWorker\HearMeQueueWorker;
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
    $this->assertTrue($this->container->get('database')->schema()->tableExists('hear_me_audio_cache'));
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('media_type')->load('hear_me_audio'));

    $settings = $this->config('hear_me.settings');
    $this->assertSame('piper', $settings->get('provider'));
    $this->assertSame('private', $settings->get('runtime_cache_scheme'));
    $this->assertSame([], $settings->get('queue_bundles'));
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

    $this->container->get('module_installer')->uninstall(['hear_me']);
    $this->container = \Drupal::getContainer();

    $this->assertFalse($this->container->get('module_handler')->moduleExists('hear_me'));
    $this->assertTrue($this->config('hear_me.settings')->isNew());
    $this->assertTrue($this->config('hear_me.provider.piper')->isNew());
    $this->assertFalse($this->container->get('database')->schema()->tableExists('hear_me_audio_cache'));
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('media_type')->load('hear_me_audio'));
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
  }

}
