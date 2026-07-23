<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Unit;

use Drupal\hear_me\Plugin\TtsProvider\PiperProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Tests Piper provider security-sensitive helpers.
 */
#[CoversClass(PiperProvider::class)]
#[Group('hear_me')]
class PiperProviderSecurityTest extends TestCase {

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
