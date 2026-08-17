<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\hear_me\Plugin\Block\HearMeBlock;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderManager;
use Drupal\hear_me\Service\TtsProviderResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests block provider identity and language fallback metadata.
 */
#[CoversClass(HearMeBlock::class)]
#[Group('hear_me')]
class HearMeBlockTest extends TestCase {

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests one captured provider ID drives fallback settings and cacheability.
   */
  public function testBuildUsesCapturedProviderForFallbackAndCacheability(): void {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->expects($this->once())
      ->method('get')
      ->with('provider')
      ->willReturn('captured_provider');

    $providerConfiguration = [
      'supported_langs' => ['en'],
      'default_lang' => 'uk',
    ];
    $providerConfigKeys = [];
    $providerConfig = $this->createMock(ImmutableConfig::class);
    $providerConfig->expects($this->exactly(2))
      ->method('get')
      ->willReturnCallback(function (string $key = '') use (&$providerConfigKeys, $providerConfiguration): mixed {
        $providerConfigKeys[] = $key;
        return $key === 'default_lang' ? 'uk' : $providerConfiguration;
      });

    $configNames = [];
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->exactly(3))
      ->method('get')
      ->willReturnCallback(function (string $name) use (&$configNames, $settings, $providerConfig): ImmutableConfig {
        $configNames[] = $name;
        return $name === 'hear_me.settings' ? $settings : $providerConfig;
      });

    $provider = $this->createMock(TtsProviderInterface::class);
    $provider->expects($this->once())
      ->method('getSupportedLanguages')
      ->willReturn(['en']);
    $providerManager = $this->createMock(TtsProviderManager::class);
    $providerManager->expects($this->once())
      ->method('hasDefinition')
      ->with('captured_provider')
      ->willReturn(TRUE);
    $providerManager->expects($this->once())
      ->method('createInstance')
      ->with('captured_provider', $providerConfiguration)
      ->willReturn($provider);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->expects($this->once())
      ->method('get')
      ->with('hear_me')
      ->willReturn($this->createMock(LoggerChannelInterface::class));
    $resolver = new TtsProviderResolver($configFactory, $providerManager, $loggerFactory);

    $language = $this->createMock(LanguageInterface::class);
    $language->expects($this->once())
      ->method('getId')
      ->willReturn('fr-CA');
    $languageManager = $this->createMock(LanguageManagerInterface::class);
    $languageManager->expects($this->once())
      ->method('getCurrentLanguage')
      ->willReturn($language);

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')
      ->willReturnCallback(static fn(string $route): string => '/' . $route);
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->createMock(TranslationInterface::class));
    $container->set('url_generator', $urlGenerator);
    \Drupal::setContainer($container);

    $block = new HearMeBlock(
      [],
      'hear_me_block',
      ['provider' => 'hear_me'],
      $resolver,
      $languageManager,
    );
    $build = $block->build();

    $this->assertSame(['', 'default_lang'], $providerConfigKeys);
    $this->assertSame([
      'hear_me.settings',
      'hear_me.provider.captured_provider',
      'hear_me.provider.captured_provider',
    ], $configNames);
    $this->assertSame([
      'default_lang' => 'uk',
      'tts_url' => '/hear_me.tts',
      'csrf_token_url' => '/system.csrftoken',
    ], $build['#attached']['drupalSettings']['hear_me']);
    $this->assertSame(['languages:language_interface'], $build['#cache']['contexts']);
    $this->assertSame([
      'config:hear_me.settings',
      'config:hear_me.provider.captured_provider',
    ], $build['#cache']['tags']);
    $this->assertSame(Cache::PERMANENT, $build['#cache']['max-age']);
  }

}
