<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\hear_me\Plugin\Filter\FilterTtsPlayback;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderManager;
use Drupal\hear_me\Service\HearMeService;
use Drupal\hear_me\Service\TtsProviderResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tests inline playback filter provider identity handling.
 */
#[CoversClass(FilterTtsPlayback::class)]
#[Group('hear_me')]
class FilterTtsPlaybackTest extends TestCase {

  protected function tearDown(): void {
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Tests token generation uses the provider ID captured for cache tags.
   */
  public function testCacheTokenUsesCapturedCacheTagProviderId(): void {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->expects($this->once())
      ->method('get')
      ->with('provider')
      ->willReturn('captured_provider');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->once())
      ->method('get')
      ->with('hear_me.settings')
      ->willReturn($settings);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->expects($this->once())
      ->method('get')
      ->with('hear_me')
      ->willReturn($this->createMock(LoggerChannelInterface::class));
    $resolver = new TtsProviderResolver(
      $configFactory,
      $this->createMock(TtsProviderManager::class),
      $loggerFactory,
    );

    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->once())
      ->method('buildCacheToken')
      ->with('Token text', 'en', 'inline', 'captured_provider')
      ->willReturn(str_repeat('b', 64));

    $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
    $urlGenerator->method('generateFromRoute')
      ->willReturnCallback(static fn(string $route): string => '/' . $route);
    $container = new ContainerBuilder();
    $container->set('url_generator', $urlGenerator);
    \Drupal::setContainer($container);

    $filter = new FilterTtsPlayback([], 'filter_tts_playback', ['provider' => 'hear_me'], $ttsService, $resolver);
    $result = $filter->process('<tts>Token text</tts>', 'en');

    $this->assertContains('config:hear_me.provider.captured_provider', $result->getCacheTags());
    $this->assertStringContainsString('data-cache-token="' . str_repeat('b', 64) . '"', $result->getProcessedText());
  }

}
