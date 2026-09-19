<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ConfigurablePluginBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Attribute\TtsProvider;
use Drupal\hear_me\TtsSynthesisResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * TTS provider adapter for a Piper-compatible HTTP service.
 */
#[TtsProvider(
  id: 'piper',
  label: new TranslatableMarkup('Piper (self-hosted)'),
)]
class PiperProvider extends ConfigurablePluginBase implements TtsProviderInterface, ContainerFactoryPluginInterface, PluginFormInterface {

  use StringTranslationTrait;

  private const MAX_AUDIO_RESPONSE_BYTES = 16777216;

  private const BLOCKED_INFRASTRUCTURE_RANGES = [
    '169.254.0.0/16',
    '100.100.100.200/32',
    '168.63.129.16/32',
    'fe80::/10',
    'fd00:ec2::/32',
    'fd20:ce::/64',
    '::ffff:0:0/96',
    '64:ff9b::/96',
    '64:ff9b:1::/48',
  ];

  protected ClientInterface $httpClient;
  protected $logger;

  /**
   * Constructs a PiperProvider instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient        = $http_client;
    $this->logger            = $logger_factory->get('hear_me');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('logger.factory'),
    );
  }

  public function getDefaultExtension(): string {
    return 'wav';
  }

  public function getSupportedLanguages(): array {
    $langs = $this->configuration['supported_langs'] ?? NULL;
    return is_array($langs) && !empty($langs) ? $langs : ['en'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form['allow_private_endpoint_urls'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow local/private provider endpoints'),
      '#default_value' => $this->configuration['allow_private_endpoint_urls'] ?? FALSE,
      '#description' => $this->t('Keep disabled unless the Piper-compatible service intentionally runs on a trusted loopback, private, or reserved address. Docker/DDEV service names that resolve to private addresses require this option. Link-local infrastructure ranges remain blocked.'),
    ];

    $form['endpoint'] = [
      '#type'          => 'url',
      '#title'         => $this->t('Piper Endpoint URL'),
      '#default_value' => $this->configuration['endpoint'] ?? '',
      '#description'   => $this->t('Full URL of a Piper-compatible HTTP TTS endpoint. Drupal sends server-side HTTP POST requests to this URL, so use only endpoints you control or trust. Do not point it at user-supplied URLs or sensitive internal metadata services.'),
      '#required'      => TRUE,
      '#element_validate' => [[static::class, 'validateEndpointElement']],
    ];

    $form['supported_langs'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Supported Language Codes'),
      '#default_value' => implode(', ', $this->configuration['supported_langs'] ?? ['en']),
      '#description'   => $this->t('Comma-separated voice-registry keys this provider supports (e.g. <code>en, uk_UA</code>). Values are sent to Piper exactly as saved and must match its registry.'),
      '#required'      => TRUE,
    ];

    $form['default_lang'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Default Language'),
      '#default_value' => $this->configuration['default_lang'] ?? 'en',
      '#description'   => $this->t('Voice-registry key used when a request does not specify one. It must be present in Supported Language Codes.'),
      '#required'      => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $langs = self::normalizeLanguages((string) ($form_state->getValue('supported_langs') ?? ''));
    if (!$langs) {
      $form_state->setErrorByName('supported_langs', $this->t('Enter at least one supported language code.'));
      return;
    }
    if (count($langs) > 50) {
      $form_state->setErrorByName('supported_langs', $this->t('Enter no more than 50 supported language codes.'));
    }
    foreach ($langs as $lang) {
      if (!preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/', $lang)) {
        $form_state->setErrorByName('supported_langs', $this->t('The language code %lang is invalid.', ['%lang' => $lang]));
      }
    }

    $defaultLang = self::findSupportedLanguage((string) ($form_state->getValue('default_lang') ?? ''), $langs);
    if ($defaultLang === NULL) {
      $form_state->setErrorByName('default_lang', $this->t('The default language must be included in Supported Language Codes.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $langs = self::normalizeLanguages((string) ($form_state->getValue('supported_langs') ?? ''));
    $defaultLang = self::findSupportedLanguage((string) $form_state->getValue('default_lang'), $langs);
    $this->setConfiguration([
      'endpoint' => trim((string) ($form_state->getValue('endpoint') ?? '')),
      'allow_private_endpoint_urls' => (bool) ($form_state->getValue('allow_private_endpoint_urls') ?? FALSE),
      'default_lang' => $defaultLang ?? trim((string) $form_state->getValue('default_lang')),
      'supported_langs' => $langs,
    ]);
  }

  public static function validateEndpointElement(array &$element, FormStateInterface $form_state, array &$complete_form): void {
    $endpoint = trim((string) ($element['#value'] ?? ''));
    $form_state->setValueForElement($element, $endpoint);

    if ($endpoint === '') {
      return;
    }

    $providerSettings = $form_state->getValue('provider_settings') ?? [];
    $allowPrivateEndpointUrls = (bool) ($providerSettings['allow_private_endpoint_urls'] ?? FALSE);
    $validationError = static::getEndpointValidationError($endpoint, $allowPrivateEndpointUrls);
    if ($validationError !== NULL) {
      $form_state->setError($element, $validationError);
    }
  }

  protected static function getEndpointValidationError(string $endpoint, bool $allowPrivateEndpointUrls = FALSE, ?array &$resolvedAddresses = NULL): ?TranslatableMarkup {
    $resolvedAddresses = [];
    if (!UrlHelper::isValid($endpoint, TRUE)) {
      return new TranslatableMarkup('The Piper-compatible endpoint must be an absolute URL, for example https://tts.example.com/tts.');
    }

    $parts = parse_url($endpoint);
    if (!is_array($parts)) {
      return new TranslatableMarkup('The Piper-compatible endpoint URL could not be parsed.');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      return new TranslatableMarkup('The Piper-compatible endpoint must use HTTP or HTTPS.');
    }

    if (empty($parts['host'])) {
      return new TranslatableMarkup('The Piper-compatible endpoint must include a host name.');
    }

    if (isset($parts['user']) || isset($parts['pass'])) {
      return new TranslatableMarkup('Do not include usernames or passwords in the Piper-compatible endpoint URL.');
    }

    if (isset($parts['fragment'])) {
      return new TranslatableMarkup('Do not include URL fragments in the Piper-compatible endpoint URL.');
    }

    $host = strtolower(trim((string) $parts['host'], '[]'));
    $host = rtrim($host, '.');
    if (static::isBlockedInfrastructureAddress($host)) {
      return new TranslatableMarkup('Metadata service endpoint URLs are blocked. Do not point the Piper-compatible endpoint at 169.254.169.254 or equivalent metadata services.');
    }

    if (!$allowPrivateEndpointUrls && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
      return new TranslatableMarkup('Localhost endpoint URLs are blocked by default. Enable local/private provider endpoints only when the service is trusted.');
    }

    if (!$allowPrivateEndpointUrls && static::isNonPublicIpLiteral($host)) {
      return new TranslatableMarkup('Loopback, private, link-local, multicast, and reserved IP endpoint URLs are blocked by default. Enable local/private provider endpoints only when the service is trusted.');
    }

    if (!filter_var($host, FILTER_VALIDATE_IP)) {
      $resolvedAddresses = static::resolveHostAddresses($host);
      if (!$resolvedAddresses) {
        return new TranslatableMarkup('The Piper-compatible endpoint hostname could not be resolved to an IPv4 or IPv6 address.');
      }
      foreach ($resolvedAddresses as $address) {
        if (static::isBlockedInfrastructureAddress($address)) {
          return new TranslatableMarkup('Metadata service endpoint URLs are blocked. Do not point the Piper-compatible endpoint at 169.254.169.254 or equivalent metadata services.');
        }
        if (!$allowPrivateEndpointUrls && static::isNonPublicIpLiteral($address)) {
          return new TranslatableMarkup('The endpoint hostname resolves to a non-public IP address. Enable local/private provider endpoints only when the service is trusted.');
        }
      }
    }

    return NULL;
  }

  protected static function isBlockedInfrastructureAddress(string $host): bool {
    return filter_var($host, FILTER_VALIDATE_IP) !== FALSE
      && IpUtils::checkIp($host, self::BLOCKED_INFRASTRUCTURE_RANGES);
  }

  protected static function isNonPublicIpLiteral(string $host): bool {
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
      return FALSE;
    }

    return IpUtils::isPrivateIp($host)
      || !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
  }

  /**
   * Resolves currently advertised IPv4 and IPv6 addresses for a host.
   */
  protected static function resolveHostAddresses(string $host): array {
    $addresses = @gethostbynamel($host) ?: [];
    $records = @dns_get_record($host, DNS_AAAA);
    if (is_array($records)) {
      foreach ($records as $record) {
        if (!empty($record['ipv6'])) {
          $addresses[] = $record['ipv6'];
        }
      }
    }

    $normalized = [];
    foreach ($addresses as $address) {
      $packed = @inet_pton((string) $address);
      if ($packed !== FALSE) {
        $normalized[] = inet_ntop($packed);
      }
    }
    $normalized = array_values(array_unique($normalized));
    sort($normalized, SORT_STRING);
    return $normalized;
  }

  /**
   * Calls the Piper-compatible HTTP endpoint and returns the raw audio bytes.
   *
   * Returns NULL on any failure; all failures are logged so they surface in
   * watchdog without crashing the caller.
   */
  public function synthesize(string $text, string $lang): ?TtsSynthesisResult {
    $endpoint = trim((string) ($this->configuration['endpoint'] ?? ''));
    $allowPrivateEndpointUrls = (bool) ($this->configuration['allow_private_endpoint_urls'] ?? FALSE);
    $resolvedAddresses = [];
    $validationError = $endpoint === ''
      ? new TranslatableMarkup('The Piper-compatible endpoint is empty.')
      : static::getEndpointValidationError($endpoint, $allowPrivateEndpointUrls, $resolvedAddresses);
    if ($validationError !== NULL) {
      $this->logger->error('Piper TTS endpoint is invalid: @message', ['@message' => $validationError->getUntranslatedString()]);
      return NULL;
    }

    $requestOptions = [
      'json' => ['text' => $text, 'lang' => $lang],
      'connect_timeout' => 5,
      'timeout' => 30,
      'stream' => TRUE,
      'allow_redirects' => FALSE,
      'proxy' => '',
      'curl' => [
        CURLOPT_FRESH_CONNECT => TRUE,
        CURLOPT_FORBID_REUSE => TRUE,
      ],
      'headers' => [
        'Accept' => 'audio/wav',
      ],
    ];
    if ($resolvedAddresses) {
      $parts = parse_url($endpoint);
      $host = rtrim(strtolower(trim((string) ($parts['host'] ?? ''), '[]')), '.');
      $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));
      foreach ($resolvedAddresses as &$address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
          $address = '[' . $address . ']';
        }
      }
      unset($address);
      $requestOptions['curl'][CURLOPT_RESOLVE] = [sprintf('%s:%d:%s', $host, $port, implode(',', $resolvedAddresses))];
    }

    try {
      $response = $this->httpClient->request('POST', $endpoint, $requestOptions);
    }
    catch (GuzzleException $e) {
      $this->logger->error(
        'Piper TTS HTTP request failed (endpoint: @endpoint, lang: @lang, error: @error).',
        [
          '@endpoint' => self::getEndpointForLog($endpoint),
          '@lang'     => $lang,
          '@error'    => $e::class,
        ]
      );
      return NULL;
    }

    $statusCode = $response->getStatusCode();
    if ($statusCode !== 200) {
      $this->logger->warning(
        'Piper TTS returned unexpected HTTP @code (endpoint: @endpoint, lang: @lang).',
        [
          '@code'     => $statusCode,
          '@endpoint' => self::getEndpointForLog($endpoint),
          '@lang'     => $lang,
        ]
      );
      return NULL;
    }

    $contentType = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'), 2)[0]));
    if ($contentType !== 'audio/wav') {
      $this->logger->warning(
        'Piper TTS returned unsupported Content-Type @type (endpoint: @endpoint, lang: @lang).',
        [
          '@type' => $contentType === '' ? 'none' : $contentType,
          '@endpoint' => self::getEndpointForLog($endpoint),
          '@lang' => $lang,
        ]
      );
      return NULL;
    }

    $body = $response->getBody();
    $declaredSize = $body->getSize();
    if ($declaredSize !== NULL && $declaredSize > self::MAX_AUDIO_RESPONSE_BYTES) {
      $body->close();
      $this->logger->warning('Piper TTS response exceeded the maximum audio size (endpoint: @endpoint, lang: @lang).', [
        '@endpoint' => self::getEndpointForLog($endpoint),
        '@lang' => $lang,
      ]);
      return NULL;
    }

    $bytes = '';
    $complete = FALSE;
    try {
      while (!$body->eof() && strlen($bytes) <= self::MAX_AUDIO_RESPONSE_BYTES) {
        $chunk = $body->read(min(8192, self::MAX_AUDIO_RESPONSE_BYTES + 1 - strlen($bytes)));
        if ($chunk === '' && !$body->eof()) {
          throw new \RuntimeException('The Piper response stream stopped making progress.');
        }
        $bytes .= $chunk;
      }
      $complete = $body->eof();
    }
    catch (\RuntimeException) {
      $this->logger->warning('Piper TTS response could not be read (endpoint: @endpoint, lang: @lang).', [
        '@endpoint' => self::getEndpointForLog($endpoint),
        '@lang' => $lang,
      ]);
      return NULL;
    }
    finally {
      $body->close();
    }
    if ($bytes === '' || strlen($bytes) > self::MAX_AUDIO_RESPONSE_BYTES || !$complete) {
      $this->logger->warning('Piper TTS returned empty or oversized audio (endpoint: @endpoint, lang: @lang).', [
        '@endpoint' => self::getEndpointForLog($endpoint),
        '@lang' => $lang,
      ]);
      return NULL;
    }

    return new TtsSynthesisResult(
      $bytes,
      'audio/wav',
      $this->getDefaultExtension(),
    );
  }

  private static function getEndpointForLog(string $endpoint): string {
    $parts = parse_url($endpoint);
    if (!is_array($parts) || empty($parts['host'])) {
      return '[invalid endpoint]';
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = trim((string) $parts['host'], '[]');
    $authority = $scheme !== '' ? $scheme . '://' : '';
    $authority .= str_contains($host, ':') ? '[' . $host . ']' : $host;

    if (isset($parts['port'])) {
      $authority .= ':' . (int) $parts['port'];
    }

    return $authority;
  }

  /**
   * Normalizes comma-separated provider language codes.
   */
  private static function normalizeLanguages(string $rawLanguages): array {
    $languages = [];
    foreach (explode(',', $rawLanguages) as $language) {
      $language = trim($language);
      if ($language !== '') {
        $languages[strtolower(str_replace('_', '-', $language))] = $language;
      }
    }
    return array_values($languages);
  }

  /**
   * Finds the exact registry key matching a normalized language value.
   */
  private static function findSupportedLanguage(string $language, array $supportedLanguages): ?string {
    $normalized = strtolower(str_replace('_', '-', trim($language)));
    foreach ($supportedLanguages as $supportedLanguage) {
      if (strtolower(str_replace('_', '-', $supportedLanguage)) === $normalized) {
        return $supportedLanguage;
      }
    }
    return NULL;
  }

}
