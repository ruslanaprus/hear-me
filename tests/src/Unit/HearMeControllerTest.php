<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Unit;

use Drupal\hear_me\Controller\HearMeController;
use Drupal\hear_me\Service\HearMeInputValidator;
use Drupal\hear_me\Service\HearMeRateLimiter;
use Drupal\hear_me\Service\HearMeService;
use Drupal\hear_me\TtsAudioResult;
use Drupal\hear_me\TtsInputValidationResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the runtime endpoint provider identity contract.
 */
#[CoversClass(HearMeController::class)]
#[Group('hear_me')]
class HearMeControllerTest extends TestCase {

  /**
   * Tests that the validated provider ID is used throughout one operation.
   */
  public function testValidatedProviderIdIsUsedForFloodTokenAndSynthesis(): void {
    $calls = [];
    $validation = TtsInputValidationResult::valid(
      'Validated text',
      'en',
      'validated_provider',
      'inline',
      str_repeat('a', 64),
    );

    $inputValidator = $this->createMock(HearMeInputValidator::class);
    $inputValidator->expects($this->once())
      ->method('validateRequestBody')
      ->with('{"text":"request"}')
      ->willReturnCallback(function () use (&$calls, $validation): TtsInputValidationResult {
        $calls[] = 'validate';
        return $validation;
      });

    $rateLimiter = $this->createMock(HearMeRateLimiter::class);
    $rateLimiter->expects($this->once())
      ->method('check')
      ->with('validated_provider')
      ->willReturnCallback(function () use (&$calls): ?string {
        $calls[] = 'check';
        return NULL;
      });
    $rateLimiter->expects($this->once())
      ->method('register')
      ->with('validated_provider')
      ->willReturnCallback(function () use (&$calls): void {
        $calls[] = 'register';
      });

    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->once())
      ->method('getTrustedRuntimeSource')
      ->with('Validated text', 'en', 'inline', str_repeat('a', 64), 'validated_provider')
      ->willReturnCallback(function () use (&$calls): string {
        $calls[] = 'token';
        return 'inline';
      });
    $ttsService->expects($this->once())
      ->method('getAudio')
      ->with('Validated text', 'en', 'inline', 'validated_provider')
      ->willReturnCallback(function () use (&$calls): TtsAudioResult {
        $calls[] = 'synthesize';
        return new TtsAudioResult('audio', 'audio/wav', 'wav');
      });

    $controller = new HearMeController($ttsService, $inputValidator, $rateLimiter);
    $response = $controller->synthesize(Request::create('/hear-me/tts', 'POST', content: '{"text":"request"}'));

    $this->assertSame(['validate', 'check', 'register', 'token', 'synthesize'], $calls);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('audio', $response->getContent());
  }

}
