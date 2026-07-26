<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Kernel;

use Drupal\hear_me\Plugin\TtsProvider\PiperProvider;
use Drupal\hear_me_test\Plugin\TtsProvider\TestProvider;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests tagged TTS provider discovery and active provider behavior.
 */
#[Group('hear_me')]
#[RunTestsInSeparateProcesses]
class HearMeProviderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'hear_me',
    'hear_me_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installConfig(['hear_me']);
  }

  /**
   * Tests tagged providers are discovered and built by the container.
   */
  public function testTaggedProviderDiscoveryAndConstruction(): void {
    $providers = $this->container->get('hear_me.service')->getProviders();

    $this->assertCount(2, $providers);
    $this->assertArrayHasKey('piper', $providers);
    $this->assertInstanceOf(PiperProvider::class, $providers['piper']);
    $this->assertArrayHasKey('test', $providers);
    $this->assertInstanceOf(TestProvider::class, $providers['test']);

    $this->config('hear_me.provider.piper')
      ->set('supported_langs', ['fr', 'de'])
      ->save();
    $this->assertSame(['fr', 'de'], $providers['piper']->getSupportedLanguages());
  }

  /**
   * Tests active test provider resolution, synthesis, and audio metadata.
   */
  public function testActiveTestProviderResolutionAndSynthesis(): void {
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('cache_enabled', FALSE)
      ->save();

    $service = $this->container->get('hear_me.service');
    $this->assertSame('test', $service->getProviderKey());
    $this->assertSame(['en'], $service->getSupportedLanguages());

    $audio = $service->getAudio('Characterized text', 'en');
    $this->assertNotNull($audio);
    $this->assertSame('test-audio:en:Characterized text', $audio->bytes);
    $this->assertSame('audio/wav', $audio->mimeType);
    $this->assertSame('wav', $audio->extension);
    $this->assertNull($audio->uri);
    $this->assertNull($audio->fid);
  }

  /**
   * Tests a missing provider setting fails with the current explicit error.
   */
  public function testMissingProviderConfiguration(): void {
    $this->config('hear_me.settings')->clear('provider')->save();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('HearMe: the "provider" key is missing from hear_me.settings configuration.');
    $this->container->get('hear_me.service')->getProviderKey();
  }

  /**
   * Tests behavior when configuration names an unregistered provider.
   */
  public function testUnknownConfiguredProvider(): void {
    $this->config('hear_me.settings')
      ->set('provider', 'unknown')
      ->set('cache_enabled', FALSE)
      ->save();

    $service = $this->container->get('hear_me.service');
    $this->assertSame('unknown', $service->getProviderKey());
    $this->assertNull($service->getAudio('Characterized text', 'en'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('HearMe: the "default_lang" key is missing from hear_me.provider.unknown configuration.');
    $service->getSupportedLanguages();
  }

}
