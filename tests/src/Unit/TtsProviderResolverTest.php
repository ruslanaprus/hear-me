<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderManager;
use Drupal\hear_me\Service\TtsProviderResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests missing TTS provider resolution.
 */
#[CoversClass(TtsProviderResolver::class)]
#[Group('hear_me')]
class TtsProviderResolverTest extends TestCase {

  /**
   * Tests that a missing provider is logged without loading configuration.
   */
  public function testGetProviderReturnsNullForMissingProvider(): void {
    $providerId = 'configured_missing';
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->never())->method('get');

    $providerManager = $this->createMock(TtsProviderManager::class);
    $providerManager->expects($this->once())
      ->method('hasDefinition')
      ->with($providerId)
      ->willReturn(FALSE);
    $providerManager->expects($this->never())->method('createInstance');

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'HearMe: provider plugin "@id" is not discoverable. Update the provider at /admin/config/media/hear-me.',
        ['@id' => $providerId],
      );
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->expects($this->once())
      ->method('get')
      ->with('hear_me')
      ->willReturn($logger);

    $resolver = new TtsProviderResolver($configFactory, $providerManager, $loggerFactory);

    $this->assertNull($resolver->getProvider($providerId));
  }

  /**
   * Tests the default-language fallback for a missing provider.
   */
  public function testGetSupportedLanguagesFallsBackToEffectiveDefault(): void {
    $providerId = 'configured_missing';
    $providerConfig = $this->createMock(ImmutableConfig::class);
    $providerConfig->expects($this->once())
      ->method('get')
      ->with('default_lang')
      ->willReturn('fr');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->once())
      ->method('get')
      ->with('hear_me.provider.' . $providerId)
      ->willReturn($providerConfig);

    $providerManager = $this->createMock(TtsProviderManager::class);
    $providerManager->expects($this->once())
      ->method('hasDefinition')
      ->with($providerId)
      ->willReturn(FALSE);
    $providerManager->expects($this->never())->method('createInstance');

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('warning')
      ->with(
        'HearMe: provider plugin "@id" is not discoverable. Update the provider at /admin/config/media/hear-me.',
        ['@id' => $providerId],
      );
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->expects($this->once())
      ->method('get')
      ->with('hear_me')
      ->willReturn($logger);

    $resolver = new TtsProviderResolver($configFactory, $providerManager, $loggerFactory);

    $this->assertSame(['fr'], $resolver->getSupportedLanguages($providerId));
  }

}
