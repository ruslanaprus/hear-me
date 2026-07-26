<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Plugin\TtsProvider\PiperProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests Piper provider security-sensitive helpers.
 */
#[CoversClass(PiperProvider::class)]
#[Group('hear_me')]
class PiperProviderSecurityTest extends TestCase {

  /**
   * Tests endpoint element validation rejects unsafe endpoint URLs.
   */
  #[DataProvider('unsafeEndpointProvider')]
  public function testValidateEndpointElementRejectsUnsafeUrls(string $endpoint, bool $allowPrivateEndpointUrls, string $expectedError): void {
    $element = [
      '#value' => $endpoint,
      '#parents' => ['provider_settings', 'endpoint'],
    ];
    $completeForm = [];
    $formState = (new FormState())->setValue('provider_settings', [
      'allow_private_endpoint_urls' => $allowPrivateEndpointUrls,
    ]);

    PiperProvider::validateEndpointElement($element, $formState, $completeForm);

    $errors = $formState->getErrors();
    $this->assertCount(1, $errors);
    $error = reset($errors);
    $this->assertInstanceOf(TranslatableMarkup::class, $error);
    $this->assertSame($expectedError, $error->getUntranslatedString());
  }

  /**
   * Provides unsafe endpoint URLs and their validation errors.
   */
  public static function unsafeEndpointProvider(): iterable {
    yield 'relative URL' => [
      '/tts',
      FALSE,
      'The Piper-compatible endpoint must be an absolute URL, for example https://tts.example.com/tts.',
    ];
    yield 'malformed URL' => [
      'http://[::1',
      FALSE,
      'The Piper-compatible endpoint must be an absolute URL, for example https://tts.example.com/tts.',
    ];
    yield 'non-HTTP scheme' => [
      'ftp://tts.example.com/tts',
      FALSE,
      'The Piper-compatible endpoint must use HTTP or HTTPS.',
    ];
    yield 'credentials' => [
      'https://user:pass@tts.example.com/tts',
      FALSE,
      'Do not include usernames or passwords in the Piper-compatible endpoint URL.',
    ];
    yield 'fragment' => [
      'https://tts.example.com/tts#voice',
      FALSE,
      'Do not include URL fragments in the Piper-compatible endpoint URL.',
    ];
    yield 'localhost without opt-in' => [
      'http://localhost:5000/tts',
      FALSE,
      'Localhost endpoint URLs are blocked by default. Enable local/private provider endpoints only when the service is trusted.',
    ];
    yield 'metadata IPv4 with opt-in' => [
      'http://169.254.169.254/latest/meta-data',
      TRUE,
      'Metadata service endpoint URLs are blocked. Do not point the Piper-compatible endpoint at 169.254.169.254 or equivalent metadata services.',
    ];
    yield 'metadata IPv6 with opt-in' => [
      'http://[fd00:ec2::254]/latest/meta-data',
      TRUE,
      'Metadata service endpoint URLs are blocked. Do not point the Piper-compatible endpoint at 169.254.169.254 or equivalent metadata services.',
    ];
    yield 'private IPv6 without opt-in' => [
      'http://[fd12:3456::1]/tts',
      FALSE,
      'Loopback, private, link-local, multicast, and reserved IP endpoint URLs are blocked by default. Enable local/private provider endpoints only when the service is trusted.',
    ];
  }

  /**
   * Tests that provider logs do not expose endpoint credentials or query data.
   */
  public function testEndpointLoggingRedactsSensitiveUrlParts(): void {
    $method = new \ReflectionMethod(PiperProvider::class, 'getEndpointForLog');

    $this->assertSame(
      'https://tts.example.com:8443/tts?[redacted]',
      $method->invoke(NULL, 'https://user:pass@tts.example.com:8443/tts?voice=en&profile=admin#fragment'),
    );
    $this->assertSame(
      'http://[2001:db8::1]/tts?[redacted]',
      $method->invoke(NULL, 'http://[2001:db8::1]/tts?voice=en'),
    );
    $this->assertSame('[invalid endpoint]', $method->invoke(NULL, 'not-a-url'));
  }

}
