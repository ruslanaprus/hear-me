<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Unit;

use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Plugin\TtsProvider\PiperProvider;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests Piper provider security-sensitive helpers.
 */
#[CoversClass(PiperProvider::class)]
#[Group('hear_me')]
class PiperProviderSecurityTest extends TestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestablePiperProvider::$resolvedAddresses = ['93.184.216.34'];
  }

  /**
   * Tests snapshot configuration and the Piper HTTP request contract.
   */
  public function testConfiguredInstanceSendsExpectedRequestAndReturnsAudio(): void {
    $configuration = [
      'endpoint' => 'https://snapshot.example.com/tts',
      'allow_private_endpoint_urls' => FALSE,
      'default_lang' => 'uk',
      'supported_langs' => ['en', 'uk'],
    ];
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with('POST', 'https://snapshot.example.com/tts', [
        'json' => ['text' => 'Snapshot text', 'lang' => 'uk'],
        'connect_timeout' => 5,
        'timeout' => 30,
        'stream' => TRUE,
        'allow_redirects' => FALSE,
        'proxy' => '',
        'curl' => [
          CURLOPT_FRESH_CONNECT => TRUE,
          CURLOPT_FORBID_REUSE => TRUE,
          CURLOPT_RESOLVE => ['snapshot.example.com:443:93.184.216.34'],
        ],
        'headers' => ['Accept' => 'audio/wav'],
      ])
      ->willReturn(new Response(200, ['Content-Type' => 'audio/wav'], 'snapshot-audio'));
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('hear_me')->willReturn(new NullLogger());

    $provider = new TestablePiperProvider(
      $configuration,
      'piper',
      ['id' => 'piper', 'label' => new TranslatableMarkup('Piper (self-hosted)')],
      $client,
      $loggerFactory,
    );

    $this->assertSame('piper', $provider->getPluginId());
    $this->assertSame($configuration, $provider->getConfiguration());
    $this->assertSame(['en', 'uk'], $provider->getSupportedLanguages());
    $audio = $provider->synthesize('Snapshot text', 'uk');
    $this->assertNotNull($audio);
    $this->assertSame('snapshot-audio', $audio->bytes);
    $this->assertSame('audio/wav', $audio->mimeType);
    $this->assertSame('wav', $audio->extension);
  }

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
    yield 'container credentials IPv4 with opt-in' => [
      'http://169.254.170.2/credentials',
      TRUE,
      'Metadata service endpoint URLs are blocked. Do not point the Piper-compatible endpoint at 169.254.169.254 or equivalent metadata services.',
    ];
    yield 'link-local IPv6 with opt-in' => [
      'http://[fe80::1]/tts',
      TRUE,
      'Metadata service endpoint URLs are blocked. Do not point the Piper-compatible endpoint at 169.254.169.254 or equivalent metadata services.',
    ];
    yield 'NAT64 metadata form with opt-in' => [
      'http://[64:ff9b::a9fe:a9fe]/tts',
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
      'https://tts.example.com:8443',
      $method->invoke(NULL, 'https://user:pass@tts.example.com:8443/tts?voice=en&profile=admin#fragment'),
    );
    $this->assertSame(
      'http://[2001:db8::1]',
      $method->invoke(NULL, 'http://[2001:db8::1]/tts?voice=en'),
    );
    $this->assertSame('[invalid endpoint]', $method->invoke(NULL, 'not-a-url'));
  }

  /**
   * Tests transport exception messages cannot leak endpoint secrets.
   */
  public function testHttpExceptionLoggingDoesNotIncludeRawMessage(): void {
    $secret = 'sentinel-secret';
    $endpoint = 'https://tts.example.com/tts?token=' . $secret;
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willThrowException(new RequestException(
      'Request failed for ' . $endpoint,
      new Request('POST', $endpoint),
    ));
    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger->expects($this->once())
      ->method('error')
      ->with(
        $this->isType('string'),
        $this->callback(static fn(array $context): bool => !str_contains(serialize($context), $secret)),
      );
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn($logger);
    $provider = new TestablePiperProvider(
      [
        'endpoint' => $endpoint,
        'default_lang' => 'en',
        'supported_langs' => ['en'],
      ],
      'piper',
      ['id' => 'piper', 'label' => new TranslatableMarkup('Piper (self-hosted)')],
      $client,
      $loggerFactory,
    );

    $this->assertNull($provider->synthesize('Text', 'en'));
  }

  /**
   * Tests Piper rejects empty and non-WAV responses.
   */
  public function testRejectsInvalidAudioResponses(): void {
    foreach ([
      new Response(200, ['Content-Type' => 'audio/mpeg'], 'audio'),
      new Response(200, ['Content-Type' => 'audio/wav'], ''),
    ] as $response) {
      $client = $this->createMock(ClientInterface::class);
      $client->method('request')->willReturn($response);
      $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
      $loggerFactory->method('get')->willReturn(new NullLogger());
      $provider = new TestablePiperProvider(
        [
          'endpoint' => 'https://tts.example.com/tts',
          'default_lang' => 'en',
          'supported_langs' => ['en'],
        ],
        'piper',
        ['id' => 'piper', 'label' => new TranslatableMarkup('Piper (self-hosted)')],
        $client,
        $loggerFactory,
      );

      $this->assertNull($provider->synthesize('Text', 'en'));
    }
  }

  /**
   * Tests unresolved hostnames fail closed without sending a request.
   */
  public function testUnresolvedHostnameIsRejectedAtRuntime(): void {
    TestablePiperProvider::$resolvedAddresses = [];
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('request');
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn(new NullLogger());
    $provider = new TestablePiperProvider(
      [
        'endpoint' => 'https://unresolved.example.com/tts',
        'default_lang' => 'en',
        'supported_langs' => ['en'],
      ],
      'piper',
      ['id' => 'piper', 'label' => new TranslatableMarkup('Piper (self-hosted)')],
      $client,
      $loggerFactory,
    );

    $this->assertNull($provider->synthesize('Text', 'en'));
  }

  /**
   * Tests IPv6 hostname connections use the validated address and port.
   */
  public function testIpv6HostnameIsPinnedForTheRequest(): void {
    TestablePiperProvider::$resolvedAddresses = ['2001:4860:4860::8888'];
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://voice.example.com:8443/tts',
        $this->callback(static fn(array $options): bool => ($options['curl'][CURLOPT_RESOLVE] ?? []) === [
          'voice.example.com:8443:[2001:4860:4860::8888]',
        ]),
      )
      ->willReturn(new Response(200, ['Content-Type' => 'audio/wav'], 'audio'));
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn(new NullLogger());
    $provider = new TestablePiperProvider(
      [
        'endpoint' => 'https://voice.example.com:8443/tts',
        'default_lang' => 'en',
        'supported_langs' => ['en'],
      ],
      'piper',
      ['id' => 'piper', 'label' => new TranslatableMarkup('Piper (self-hosted)')],
      $client,
      $loggerFactory,
    );

    $this->assertNotNull($provider->synthesize('Text', 'en'));
  }

  /**
   * Tests all validated hostname addresses remain available to cURL.
   */
  public function testHostnamePinsAllValidatedAddresses(): void {
    TestablePiperProvider::$resolvedAddresses = [
      '2001:4860:4860::8888',
      '93.184.216.34',
    ];
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->once())
      ->method('request')
      ->with(
        'POST',
        'https://voice.example.com/tts',
        $this->callback(static fn(array $options): bool => ($options['curl'][CURLOPT_RESOLVE] ?? []) === [
          'voice.example.com:443:[2001:4860:4860::8888],93.184.216.34',
        ]),
      )
      ->willReturn(new Response(200, ['Content-Type' => 'audio/wav'], 'audio'));
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->willReturn(new NullLogger());
    $provider = new TestablePiperProvider(
      [
        'endpoint' => 'https://voice.example.com/tts',
        'default_lang' => 'en',
        'supported_langs' => ['en'],
      ],
      'piper',
      ['id' => 'piper', 'label' => new TranslatableMarkup('Piper (self-hosted)')],
      $client,
      $loggerFactory,
    );

    $this->assertNotNull($provider->synthesize('Text', 'en'));
  }

}

/**
 * Piper provider with deterministic DNS for unit tests.
 */
final class TestablePiperProvider extends PiperProvider {

  public static array $resolvedAddresses = [];

  /**
   * {@inheritdoc}
   */
  protected static function resolveHostAddresses(string $host): array {
    return self::$resolvedAddresses;
  }

}
