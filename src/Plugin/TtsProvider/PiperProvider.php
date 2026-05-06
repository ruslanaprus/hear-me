<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\hear_me\Service\TtsFileHelper;
use Drupal\media\Entity\Media;
use GuzzleHttp\ClientInterface;

class PiperProvider implements TtsProviderInterface {

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected FileSystemInterface $fileSystem;
  protected TtsFileHelper $fileHelper;

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    FileSystemInterface $fileSystem,
    TtsFileHelper $fileHelper,
  ) {
    $this->httpClient    = $httpClient;
    $this->configFactory = $configFactory;
    $this->fileSystem    = $fileSystem;
    $this->fileHelper    = $fileHelper;
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
      '#title'         => t('Piper Endpoint URL'),
      '#default_value' => $config['endpoint'] ?? 'http://piper-service:5000/tts',
      '#description'   => t('Base URL of the Piper TTS microservice.'),
      '#required'      => TRUE,
    ];

    $form['supported_langs'] = [
      '#type'          => 'textfield',
      '#title'         => t('Supported Language Codes'),
      '#default_value' => implode(', ', $config['supported_langs'] ?? ['en']),
      '#description'   => t('Comma-separated list of language codes this provider supports (e.g. <code>en, uk</code>). Must match the voice files installed on the Piper service.'),
      '#required'      => TRUE,
    ];

    $langOptions = [];
    $drupalLangs = \Drupal::languageManager()->getLanguages();
    foreach ($this->getSupportedLanguages() as $code) {
      $shortCode = strtolower(substr($code, 0, 2));
      $label = isset($drupalLangs[$code])
        ? $drupalLangs[$code]->getName()
        : (isset($drupalLangs[$shortCode]) ? $drupalLangs[$shortCode]->getName() : strtoupper($code));
      $langOptions[$code] = $label;
    }

    $form['default_lang'] = [
      '#type'          => 'select',
      '#title'         => t('Default Language'),
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

  public function synthesize(string $text, string $lang): ?Media {
    $config   = $this->configFactory->get('hear_me.provider.piper');
    $endpoint = $config->get('endpoint');

    try {
      $response = $this->httpClient->request('POST', $endpoint, [
        'json' => ['text' => $text, 'lang' => $lang],
      ]);
    }
    catch (\Exception $e) {
      return NULL;
    }

    if ($response->getStatusCode() !== 200) {
      return NULL;
    }

    $audioContent = $response->getBody()->getContents();
    $uri          = $this->fileHelper->buildTtsUri($text, $lang, $this->getProviderKey());

    $savedUri = $this->fileSystem->saveData($audioContent, $uri, FileExists::Replace);

    if (!$savedUri) {
      return NULL;
    }

    $existingFiles = \Drupal::entityTypeManager()
      ->getStorage('file')
      ->loadByProperties(['uri' => $savedUri]);

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
