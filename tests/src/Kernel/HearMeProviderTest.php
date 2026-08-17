<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Kernel;

use Drupal\hear_me\Plugin\TtsProvider\PiperProvider;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderManager;
use Drupal\hear_me\Service\TtsProviderResolver;
use Drupal\hear_me_test\Plugin\TtsProvider\TestProvider;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests TTS provider plugin discovery and active provider behavior.
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
   * Tests attribute providers are discovered with their plugin definitions.
   */
  public function testAttributeProviderDiscoveryAndConstruction(): void {
    $manager = $this->container->get('plugin.manager.hear_me.tts_provider');
    $this->assertInstanceOf(TtsProviderManager::class, $manager);
    $definitions = $manager->getDefinitions();

    $this->assertArrayHasKey('piper', $definitions);
    $this->assertArrayHasKey('test', $definitions);
    $this->assertSame('piper', $definitions['piper']['id']);
    $this->assertSame('Piper (self-hosted)', (string) $definitions['piper']['label']);
    $this->assertSame(PiperProvider::class, $definitions['piper']['class']);
    $this->assertSame('test', $definitions['test']['id']);
    $this->assertSame('Test provider', (string) $definitions['test']['label']);
    $this->assertSame(TestProvider::class, $definitions['test']['class']);
    $this->assertInstanceOf(PiperProvider::class, $manager->createInstance('piper', []));
    $this->assertInstanceOf(TestProvider::class, $manager->createInstance('test'));

    $resolver = $this->container->get('hear_me.provider_resolver');
    $this->assertInstanceOf(TtsProviderResolver::class, $resolver);
    $this->assertSame($definitions, $resolver->getProviderDefinitions());
  }

  /**
   * Tests instances retain snapshots while the service uses effective config.
   */
  public function testProviderConfigurationSnapshotsAndEffectiveConfig(): void {
    $manager = $this->container->get('plugin.manager.hear_me.tts_provider');
    $snapshot = $manager->createInstance('piper', [
      'endpoint' => 'https://snapshot.example.com/tts',
      'default_lang' => 'fr',
      'supported_langs' => ['fr'],
    ]);
    $this->assertInstanceOf(PiperProvider::class, $snapshot);
    $this->assertSame('piper', $snapshot->getPluginId());
    $this->assertSame('https://snapshot.example.com/tts', $snapshot->getConfiguration()['endpoint']);
    $this->assertSame(['fr'], $snapshot->getSupportedLanguages());

    $this->config('hear_me.provider.piper')
      ->set('endpoint', 'https://effective.example.com/tts')
      ->set('supported_langs', ['de'])
      ->save();

    $this->assertSame(['fr'], $snapshot->getSupportedLanguages());
    $resolver = $this->container->get('hear_me.provider_resolver');
    $provider = $resolver->getProvider('piper');
    $this->assertNotNull($provider);
    $this->assertNotSame($snapshot, $provider);
    $this->assertSame('en', $resolver->getDefaultLanguage('piper'));
    $this->assertSame('https://effective.example.com/tts', $provider->getConfiguration()['endpoint']);
    $this->assertSame(['de'], $provider->getSupportedLanguages());

    $this->config('hear_me.provider.piper')
      ->set('supported_langs', ['uk'])
      ->save();
    $this->assertSame(['uk'], $resolver->getSupportedLanguages('piper'));
    $this->assertSame(['fr'], $snapshot->getSupportedLanguages());
  }

  /**
   * Tests runtime plugin instances receive configuration overrides.
   */
  public function testRuntimeProviderUsesEffectiveConfigurationOverride(): void {
    $this->config('hear_me.provider.piper')
      ->set('supported_langs', ['en'])
      ->save();
    $service = $this->container->get('hear_me.service');
    $resolver = $this->container->get('hear_me.provider_resolver');
    $tokenBeforeOverride = $service->buildCacheToken('Override identity', 'en', 'inline');
    $this->assertSame(
      $tokenBeforeOverride,
      $service->buildCacheToken('Override identity', 'en', 'inline', 'piper'),
    );
    $storedHashInput = $this->config('hear_me.provider.piper')->getRawData();
    $effectiveConfig = $this->container
      ->get('config.factory')
      ->get('hear_me.provider.piper');
    $effectiveConfig->setSettingsOverride(['supported_langs' => ['es']]);

    $provider = $resolver->getProvider('piper');

    $this->assertNotNull($provider);
    $this->assertSame(['es'], $provider->getSupportedLanguages());
    $this->assertSame(['en'], $this->config('hear_me.provider.piper')->get('supported_langs'));
    $this->assertSame($storedHashInput, $resolver->getProviderConfigurationHashInput('piper'));
    $this->assertSame($tokenBeforeOverride, $service->buildCacheToken('Override identity', 'en', 'inline'));

    $unsortedHashInput = [
      'supported_langs' => ['en'],
      'endpoint' => 'https://effective.example.com/tts',
      'default_lang' => 'en',
      'allow_private_endpoint_urls' => FALSE,
    ];
    $this->config('hear_me.provider.piper')
      ->setData($unsortedHashInput)
      ->save();
    $storedHashInput = $this->config('hear_me.provider.piper')->getRawData();
    $this->assertSame(
      $storedHashInput,
      $resolver->getProviderConfigurationHashInput('piper')
    );
    $this->assertSame(
      ['endpoint', 'allow_private_endpoint_urls', 'default_lang', 'supported_langs'],
      array_keys($resolver->getProviderConfigurationHashInput('piper'))
    );

    $this->config('hear_me.provider.piper')
      ->set('supported_langs', ['fr'])
      ->save();
    $this->assertNotSame($tokenBeforeOverride, $service->buildCacheToken('Override identity', 'en', 'inline'));
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
    $resolver = $this->container->get('hear_me.provider_resolver');
    $this->assertSame('test', $resolver->getActiveProviderId());
    $this->assertSame(['en'], $resolver->getSupportedLanguages('test'));

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
    $this->expectExceptionMessage(
      'HearMe: the "provider" key is missing from hear_me.settings configuration. ' .
      'Re-install the module or set the value at /admin/config/media/hear-me.'
    );
    $this->container->get('hear_me.provider_resolver')->getActiveProviderId();
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
    $resolver = $this->container->get('hear_me.provider_resolver');
    $this->assertSame('unknown', $resolver->getActiveProviderId());
    $this->assertNull($resolver->getProvider('unknown'));
    $this->assertNull($service->getAudio('Characterized text', 'en'));

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage(
      'HearMe: the "default_lang" key is missing from hear_me.provider.unknown configuration.'
    );
    $resolver->getSupportedLanguages('unknown');
  }

}
