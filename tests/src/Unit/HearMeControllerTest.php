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
      'request',
      'inline',
      str_repeat('a', 64),
    );

    $inputValidator = $this->createMock(HearMeInputValidator::class);
    $inputValidator->expects($this->once())
      ->method('getMaxRequestBytes')
      ->willReturn(HearMeInputValidator::DEFAULT_MAX_REQUEST_BYTES);
    $inputValidator->expects($this->once())
      ->method('validateRequestBody')
      ->with('{"text":"request"}')
      ->willReturnCallback(function () use (&$calls, $validation): TtsInputValidationResult {
        $calls[] = 'validate';
        return $validation;
      });

    $rateLimiter = $this->createMock(HearMeRateLimiter::class);
    $rateLimiter->expects($this->exactly(2))
      ->method('check')
      ->willReturnCallback(function (string $provider, bool $requestWide = FALSE) use (&$calls): ?string {
        $calls[] = 'check:' . ($requestWide ? 'request-wide:' : 'provider:') . $provider;
        return NULL;
      });
    $rateLimiter->expects($this->exactly(2))
      ->method('register')
      ->willReturnCallback(function (string $provider, bool $requestWide = FALSE) use (&$calls): void {
        $calls[] = 'register:' . ($requestWide ? 'request-wide:' : 'provider:') . $provider;
      });

    $ttsService = $this->createMock(HearMeService::class);
    $ttsService->expects($this->once())
      ->method('getTrustedRuntimeSource')
      ->with('Validated text', 'en', 'inline', str_repeat('a', 64), 'request')
      ->willReturnCallback(function () use (&$calls): string {
        $calls[] = 'token';
        return 'inline';
      });
    $ttsService->expects($this->once())
      ->method('getAudio')
      ->with('Validated text', 'en', 'inline', 'request')
      ->willReturnCallback(function () use (&$calls): TtsAudioResult {
        $calls[] = 'synthesize';
        return new TtsAudioResult('audio', 'audio/wav', 'wav');
      });

    $controller = new HearMeController($ttsService, $inputValidator, $rateLimiter);
    $request = Request::create('/hear-me/tts', 'POST', content: '{"text":"request"}');
    $request->headers->set('Content-Type', 'application/json');
    $response = $controller->synthesize($request);

    $this->assertSame([
      'check:request-wide:request',
      'register:request-wide:request',
      'validate',
      'check:provider:request',
      'register:provider:request',
      'token',
      'synthesize',
    ], $calls);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('audio', $response->getContent());
  }

  /**
   * Tests that unsupported media types are rejected before body parsing.
   */
  public function testRejectsNonJsonContentType(): void {
    $inputValidator = $this->createMock(HearMeInputValidator::class);
    $inputValidator->expects($this->never())->method('validateRequestBody');
    $controller = new HearMeController(
      $this->createMock(HearMeService::class),
      $inputValidator,
      $this->createMock(HearMeRateLimiter::class),
    );

    $response = $controller->synthesize(Request::create('/hear-me/tts', 'POST', content: '{}'));

    $this->assertSame(415, $response->getStatusCode());
    $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
  }

}
