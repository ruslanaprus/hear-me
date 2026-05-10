<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\Entity\File;
use Drupal\hear_me\Service\TtsFileHelper;
use Drupal\media\Entity\Media;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * TTS provider that synthesises audio via a self-hosted Piper service.
 */
class PiperProvider implements TtsProviderInterface {

  use StringTranslationTrait;

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected FileSystemInterface $fileSystem;
  protected TtsFileHelper $fileHelper;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected LanguageManagerInterface $languageManager;
  protected $logger;

  /**
   * Constructs a PiperProvider instance.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\hear_me\Service\TtsFileHelper $file_helper
   *   The TTS file URI helper.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(
    ClientInterface $http_client,
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    TtsFileHelper $file_helper,
    EntityTypeManagerInterface $entity_type_manager,
    LanguageManagerInterface $language_manager,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpClient        = $http_client;
    $this->configFactory     = $config_factory;
    $this->fileSystem        = $file_system;
    $this->fileHelper        = $file_helper;
    $this->entityTypeManager = $entity_type_manager;
    $this->languageManager   = $language_manager;
    $this->logger            = $logger_factory->get('hear_me');
  }

  public function getProviderKey(): string {
    return 'piper';
  }

  public function getLabel(): string {
    return 'Piper (self-hosted)';
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
    $rawLangs = $form_state->getValue(['provider_settings', 'supported_langs']) ?? '';
    $langs = array_values(array_filter(array_map('trim', explode(',', $rawLangs))));

    $this->configFactory->getEditable('hear_me.provider.piper')
      ->set('endpoint',        $form_state->getValue(['provider_settings', 'endpoint']))
      ->set('default_lang',    $form_state->getValue(['provider_settings', 'default_lang']))
      ->set('supported_langs', $langs)
      ->save();
  }

  /**
   * Calls the Piper TTS microservice, saves the returned audio to the managed
   * file system, and wraps it in a Media entity.
   *
   * Returns NULL on any failure; all failures are logged to the hear_me
   * channel so problems are visible in the Drupal watchdog without crashing
   * the calling code.
   */
  public function synthesize(string $text, string $lang): ?Media {
    $config   = $this->configFactory->get('hear_me.provider.piper');
    $endpoint = $config->get('endpoint');

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'json' => ['text' => $text, 'lang' => $lang],
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

    $audioContent = $response->getBody()->getContents();
    $uri          = $this->fileHelper->buildTtsUri($text, $lang, $this->getProviderKey());
    $savedUri = $this->fileSystem->saveData($audioContent, $uri, FileExists::Replace);

    if (!$savedUri) {
      $this->logger->error(
        'Piper TTS: failed to save audio data to @uri.',
        ['@uri' => $uri]
      );
      return NULL;
    }

    $fileStorage   = $this->entityTypeManager->getStorage('file');
    $existingFiles = $fileStorage->loadByProperties(['uri' => $savedUri]);

    if ($existingFiles) {
      $fileEntity = reset($existingFiles);
    }
    else {
      $fileEntity = File::create([
        'uri'    => $savedUri,
        'status' => 1,
      ]);
      $fileEntity->save();
    }

    $media = Media::create([
      'bundle'                 => 'audio',
      'name'                   => 'TTS-' . $lang . '-' . md5($text),
      'field_media_audio_file' => [
        'target_id' => $fileEntity->id(),
      ],
    ]);
    $media->save();

    return $media;
  }

}
