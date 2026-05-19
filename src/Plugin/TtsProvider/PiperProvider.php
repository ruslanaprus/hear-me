<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\hear_me\TtsSynthesisResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * TTS provider that synthesises audio via a self-hosted Piper service.
 */
class PiperProvider implements TtsProviderInterface, TtsProviderConfigurableInterface {

  use StringTranslationTrait;

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected LanguageManagerInterface $languageManager;
  protected $logger;

  /**
   * Constructs a PiperProvider instance.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    LanguageManagerInterface $language_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient        = $http_client;
    $this->configFactory     = $config_factory;
    $this->languageManager   = $language_manager;
    $this->logger            = $logger_factory->get('hear_me');
  }

  public function getLabel(): string {
    return 'Piper (self-hosted)';
  }

  public function getDefaultMimeType(): string {
    return 'audio/wav';
  }

  public function getDefaultExtension(): string {
    return 'wav';
  }

  public function getSupportedLanguages(): array {
    $langs = $this->configFactory->get('hear_me.provider.piper')->get('supported_langs');
    return is_array($langs) && !empty($langs) ? $langs : ['en'];
  }

  public function buildConfigForm(array $form, array $config): array {
    $form['endpoint'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Piper Endpoint URL'),
      '#default_value' => $config['endpoint'] ?? 'http://piper-service:5000/tts',
      '#description'   => $this->t('Base URL of the Piper TTS microservice.'),
      '#required'      => TRUE,
    ];

    $form['supported_langs'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('Supported Language Codes'),
      '#default_value' => implode(', ', $config['supported_langs'] ?? ['en']),
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
      '#default_value' => $config['default_lang'] ?? 'en',
    ];

    return $form;
  }

  public function submitConfigForm(array &$form, FormStateInterface $form_state): void {
    $providerSettings = $form_state->getValue('provider_settings') ?? [];
    $rawLangs = $providerSettings['supported_langs'] ?? $form_state->getValue('supported_langs') ?? '';
    $langs = array_values(array_filter(array_map('trim', explode(',', $rawLangs))));

    $this->configFactory->getEditable('hear_me.provider.piper')
      ->set('endpoint',        $providerSettings['endpoint'] ?? $form_state->getValue('endpoint'))
      ->set('default_lang',    $providerSettings['default_lang'] ?? $form_state->getValue('default_lang'))
      ->set('supported_langs', $langs)
      ->save();
  }

  /**
   * Calls the Piper TTS microservice and returns the raw audio bytes.
   *
   * Returns NULL on any failure; all failures are logged so they surface in
   * watchdog without crashing the caller.
   */
  public function synthesize(string $text, string $lang): ?TtsSynthesisResult {
    $config   = $this->configFactory->get('hear_me.provider.piper');
    $endpoint = $config->get('endpoint');

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'json' => ['text' => $text, 'lang' => $lang],
        'connect_timeout' => 5,
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error(
        'Piper TTS HTTP request failed (endpoint: @endpoint, lang: @lang): @message',
        [
          '@endpoint' => $endpoint,
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
          '@endpoint' => $endpoint,
          '@lang'     => $lang,
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

}
