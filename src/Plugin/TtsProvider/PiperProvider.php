<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
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

/**
 * TTS provider adapter for a Piper-compatible HTTP service.
 */
#[TtsProvider(
  id: 'piper',
  label: new TranslatableMarkup('Piper (self-hosted)'),
)]
class PiperProvider extends ConfigurablePluginBase implements TtsProviderInterface, ContainerFactoryPluginInterface, PluginFormInterface {

  use StringTranslationTrait;

  protected ClientInterface $httpClient;
  protected LanguageManagerInterface $languageManager;
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
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ClientInterface $http_client,
    LanguageManagerInterface $language_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient        = $http_client;
    $this->languageManager   = $language_manager;
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
      $container->get('language_manager'),
      $container->get('logger.factory'),
    );
  }

  public function getDefaultMimeType(): string {
    return 'audio/wav';
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
      '#description' => $this->t('Keep disabled unless the Piper-compatible service intentionally runs on a trusted loopback, private, link-local, or reserved IP address. Docker/DDEV service names such as piper-service are hostnames and do not require this option.'),
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
      '#description'   => $this->t('Comma-separated list of language codes this provider supports (e.g. <code>en, uk</code>). Must match the voice files installed on the Piper service.'),
      '#required'      => TRUE,
    ];

    $langOptions = [];
    $drupalLangs = $this->languageManager->getLanguages();
    foreach ($this->getSupportedLanguages() as $code) {
      $shortCode = strtolower(substr($code, 0, 2));
      $langOptions[$code] = isset($drupalLangs[$code])
        ? $drupalLangs[$code]->getName()
        : (isset($drupalLangs[$shortCode]) ? $drupalLangs[$shortCode]->getName() : strtoupper($code));
    }

    $form['default_lang'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Default Language'),
      '#options'       => $langOptions,
      '#default_value' => $this->configuration['default_lang'] ?? 'en',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state): void {
    $rawLangs = $form_state->getValue('supported_langs') ?? '';
    $langs = array_values(array_filter(array_map('trim', explode(',', $rawLangs))));
    $this->setConfiguration([
      'endpoint' => trim((string) ($form_state->getValue('endpoint') ?? '')),
      'allow_private_endpoint_urls' => (bool) ($form_state->getValue('allow_private_endpoint_urls') ?? FALSE),
      'default_lang' => $form_state->getValue('default_lang'),
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

  protected static function getEndpointValidationError(string $endpoint, bool $allowPrivateEndpointUrls = FALSE): ?TranslatableMarkup {
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
    if (static::isMetadataIpLiteral($host)) {
      return new TranslatableMarkup('Metadata service endpoint URLs are blocked. Do not point the Piper-compatible endpoint at 169.254.169.254 or equivalent metadata services.');
    }

    if (!$allowPrivateEndpointUrls && ($host === 'localhost' || str_ends_with($host, '.localhost'))) {
      return new TranslatableMarkup('Localhost endpoint URLs are blocked by default. Enable local/private provider endpoints only when the service is trusted.');
    }

    if (!$allowPrivateEndpointUrls && static::isNonPublicIpLiteral($host)) {
      return new TranslatableMarkup('Loopback, private, link-local, multicast, and reserved IP endpoint URLs are blocked by default. Enable local/private provider endpoints only when the service is trusted.');
    }

    return NULL;
  }

  protected static function isMetadataIpLiteral(string $host): bool {
    return $host === '169.254.169.254'
      || $host === 'fd00:ec2::254';
  }

  protected static function isNonPublicIpLiteral(string $host): bool {
    if (!filter_var($host, FILTER_VALIDATE_IP)) {
      return FALSE;
    }

    return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
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
    $validationError = $endpoint === ''
      ? new TranslatableMarkup('The Piper-compatible endpoint is empty.')
      : static::getEndpointValidationError($endpoint, $allowPrivateEndpointUrls);
    if ($validationError !== NULL) {
      $this->logger->error('Piper TTS endpoint is invalid: @message', ['@message' => (string) $validationError]);
      return NULL;
    }

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'json' => ['text' => $text, 'lang' => $lang],
        'connect_timeout' => 5,
        'timeout' => 30,
        'allow_redirects' => FALSE,
        'headers' => [
          'Accept' => 'audio/*',
        ],
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error(
        'Piper TTS HTTP request failed (endpoint: @endpoint, lang: @lang): @message',
        [
          '@endpoint' => self::getEndpointForLog($endpoint),
          '@lang'     => $lang,
          '@message'  => $e->getMessage(),
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

    $contentType = strtolower(trim($response->getHeaderLine('Content-Type')));
    if ($contentType === '' || !str_starts_with($contentType, 'audio/')) {
      $this->logger->warning(
        'Piper TTS returned non-audio Content-Type @type (endpoint: @endpoint, lang: @lang).',
        [
          '@type' => $contentType === '' ? 'none' : $contentType,
          '@endpoint' => self::getEndpointForLog($endpoint),
          '@lang' => $lang,
        ]
      );
      return NULL;
    }

    return new TtsSynthesisResult(
      $response->getBody()->getContents(),
      $this->getDefaultMimeType(),
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

    $path = (string) ($parts['path'] ?? '');
    $query = isset($parts['query']) ? '?[redacted]' : '';

    return $authority . $path . $query;
  }

}
