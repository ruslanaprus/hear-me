<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderManager;
use Drupal\hear_me\Service\HearMeAudioFieldValidator;
use Drupal\hear_me\Service\HearMeSetupStatus;
use Drupal\hear_me\Service\TtsProviderResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes provider resolution used by setup status.
 */
#[CoversClass(HearMeSetupStatus::class)]
#[Group('hear_me')]
class HearMeSetupStatusTest extends TestCase {

  /**
   * Tests the empty-config fallback and raw provider configuration hash.
   */
  public function testProviderFallbackAndConfigurationHash(): void {
    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settingsConfig->expects($this->once())
      ->method('get')
      ->with('provider')
      ->willReturn('');

    $hashInput = [
      'supported_langs' => ['en'],
      'endpoint' => 'https://example.com/tts',
      'default_lang' => 'en',
    ];
    $providerConfig = $this->createMock(ImmutableConfig::class);
    $providerConfig->expects($this->once())
      ->method('getRawData')
      ->willReturn($hashInput);

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->exactly(2))
      ->method('get')
      ->willReturnCallback(static fn(string $name): ImmutableConfig => match ($name) {
        'hear_me.settings' => $settingsConfig,
        'hear_me.provider.first' => $providerConfig,
      });

    $providerManager = $this->createMock(TtsProviderManager::class);
    $providerManager->expects($this->once())
      ->method('getDefinitions')
      ->willReturn([
        'first' => ['label' => 'First'],
        'second' => ['label' => 'Second'],
      ]);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')
      ->with('hear_me')
      ->willReturn($this->createMock(LoggerChannelInterface::class));
    $resolver = new TtsProviderResolver($configFactory, $providerManager, $loggerFactory);

    $status = new class(
      $configFactory,
      $this->createMock(EntityTypeManagerInterface::class),
      $this->createMock(FileSystemInterface::class),
      $this->createMock(StreamWrapperManagerInterface::class),
      $this->createMock(QueueWorkerManagerInterface::class),
      $this->createMock(StateInterface::class),
      $this->createMock(DateFormatterInterface::class),
      $this->createMock(TimeInterface::class),
      $resolver,
      $this->createMock(HearMeAudioFieldValidator::class),
    ) extends HearMeSetupStatus {

      public function configuredProviderKey(): string {
        return $this->getConfiguredProviderKey();
      }

      public function providerConfigHash(string $providerKey): string {
        return $this->getProviderConfigHash($providerKey);
      }

    };

    $this->assertSame('first', $status->configuredProviderKey());
    $this->assertSame(hash('sha256', serialize($hashInput)), $status->providerConfigHash('first'));
  }

}
