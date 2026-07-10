<?php

declare(strict_types=1);

namespace Drupal\Tests\hear_me\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the runtime TTS endpoint access and response headers.
 */
#[Group('hear_me')]
#[RunTestsInSeparateProcesses]
class HearMeTtsRouteTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['hear_me', 'hear_me_test'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests permission, CSRF, and successful no-store runtime responses.
   */
  public function testTtsEndpointRequiresPermissionAndCsrf(): void {
    $this->config('hear_me.settings')
      ->set('provider', 'test')
      ->set('cache_enabled', FALSE)
      ->save();

    $client = $this->getHttpClient();
    $url = Url::fromRoute('hear_me.tts')->setAbsolute(TRUE)->toString();
    $request_options = [
      'headers' => [
        'Accept' => 'audio/*',
        'Content-Type' => 'application/json',
      ],
      'body' => json_encode([
        'text' => 'Hello from HearMe tests.',
        'lang' => 'en',
        'source' => 'adhoc',
      ], JSON_THROW_ON_ERROR),
      'http_errors' => FALSE,
    ];

    $response = $client->post($url, $request_options);
    $this->assertSame(403, $response->getStatusCode());

    $no_permission_user = $this->drupalCreateUser();
    $this->drupalLogin($no_permission_user);
    $request_options['cookies'] = $this->getSessionCookies();
    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    $response = $client->post($url, $request_options);
    $this->assertSame(403, $response->getStatusCode());

    $permitted_user = $this->drupalCreateUser(['use tts playback']);
    $this->drupalLogin($permitted_user);
    $request_options['cookies'] = $this->getSessionCookies();
    unset($request_options['headers']['X-CSRF-Token']);
    $response = $client->post($url, $request_options);
    $this->assertSame(403, $response->getStatusCode());

    $request_options['headers']['X-CSRF-Token'] = $this->drupalGet('session/token');
    $response = $client->post($url, $request_options);

    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('audio/wav', $response->getHeaderLine('Content-Type'));
    $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
    $this->assertSame('no-cache', $response->getHeaderLine('Pragma'));
    $this->assertSame('0', $response->getHeaderLine('Expires'));
    $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
  }

}
