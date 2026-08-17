<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderManager;
use Drupal\hear_me\Service\HearMeInputValidator;
use Drupal\hear_me\Service\TtsProviderResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Characterizes provider-language validation behavior.
 */
#[CoversClass(HearMeInputValidator::class)]
#[Group('hear_me')]
class HearMeInputValidatorTest extends TestCase {

  /**
   * Tests that an empty language uses the current provider default.
   */
  public function testEmptyLanguageUsesCurrentProviderDefault(): void {
    $result = $this->createValidator('uk', ['en', 'uk'], TRUE)
      ->validateRequestBody('{"text":"Valid text","lang":""}');

    $this->assertTrue($result->isValid());
    $this->assertSame('uk', $result->lang);
    $this->assertSame('test_provider', $result->providerId);
  }

  /**
   * Tests exact provider-language matching.
   */
  public function testExactLanguageMatch(): void {
    $result = $this->createValidator('en', ['en', 'uk'])
      ->validateRequestBody('{"text":"Valid text","lang":"uk"}');

    $this->assertTrue($result->isValid());
    $this->assertSame('uk', $result->lang);
  }

  /**
   * Tests normalized matching preserves the provider's configured value.
   */
  public function testCaseAndUnderscoreNormalizationPreservesProviderValue(): void {
    $result = $this->createValidator('en', ['EN_us', 'uk'])
      ->validateRequestBody('{"text":"Valid text","lang":" en-US "}');

    $this->assertTrue($result->isValid());
    $this->assertSame('EN_us', $result->lang);
  }

  /**
   * Tests regional languages fall back to a supported two-letter code.
   */
  public function testTwoLetterLanguageFallback(): void {
    $result = $this->createValidator('uk', ['en', 'uk'])
      ->validateRequestBody('{"text":"Valid text","lang":"en-GB"}');

    $this->assertTrue($result->isValid());
    $this->assertSame('en', $result->lang);
  }

  /**
   * Tests rejection when no provider language matches.
   */
  public function testUnsupportedLanguage(): void {
    $result = $this->createValidator('en', ['en', 'uk'])
      ->validateRequestBody('{"text":"Valid text","lang":"fr-CA"}');

    $this->assertFalse($result->isValid());
    $this->assertSame('Unsupported language', $result->errorMessage);
    $this->assertNull($result->providerId);
  }

  /**
   * Tests normalization of the provider default before supported lookup.
   */
  public function testProviderDefaultIsNormalizedForSupportedLookup(): void {
    $result = $this->createValidator(' EN_us ', ['en-US'], TRUE)
      ->validateRequestBody('{"text":"Valid text"}');

    $this->assertTrue($result->isValid());
    $this->assertSame('en-US', $result->lang);
  }

  /**
   * Creates a validator with valid request-limit configuration.
   */
  private function createValidator(string $defaultLang, array $supportedLangs, bool $expectsDefault = FALSE): HearMeInputValidator {
    $settingsConfig = $this->createMock(ImmutableConfig::class);
    $settingsConfig->expects($this->exactly(3))
      ->method('get')
      ->willReturnCallback(static fn(string $key): int|string => match ($key) {
        'max_request_bytes' => HearMeInputValidator::DEFAULT_MAX_REQUEST_BYTES,
        'max_text_length' => HearMeInputValidator::DEFAULT_MAX_TEXT_LENGTH,
        'provider' => 'test_provider',
      });
    $providerConfig = $this->createMock(ImmutableConfig::class);
    $providerConfig->expects($this->exactly($expectsDefault ? 2 : 1))
      ->method('get')
      ->willReturnCallback(static fn(string $key = ''): array|string => match ($key) {
        '' => [],
        'default_lang' => $defaultLang,
      });

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->expects($this->exactly($expectsDefault ? 5 : 4))
      ->method('get')
      ->willReturnCallback(static fn(string $name): ImmutableConfig => match ($name) {
        'hear_me.settings' => $settingsConfig,
        'hear_me.provider.test_provider' => $providerConfig,
      });

    $provider = $this->createMock(TtsProviderInterface::class);
    $provider->expects($this->once())
      ->method('getSupportedLanguages')
      ->willReturn($supportedLangs);

    $providerManager = $this->createMock(TtsProviderManager::class);
    $providerManager->expects($this->once())
      ->method('hasDefinition')
      ->with('test_provider')
      ->willReturn(TRUE);
    $providerManager->expects($this->once())
      ->method('createInstance')
      ->with('test_provider', [])
      ->willReturn($provider);

    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->expects($this->once())
      ->method('get')
      ->with('hear_me')
      ->willReturn($this->createMock(LoggerChannelInterface::class));

    $resolver = new TtsProviderResolver($configFactory, $providerManager, $loggerFactory);
    return new HearMeInputValidator($configFactory, $resolver);
  }

}
