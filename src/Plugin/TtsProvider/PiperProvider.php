<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use GuzzleHttp\ClientInterface;

class PiperProvider implements TtsProviderInterface {

  protected ClientInterface $httpClient;
  protected ConfigFactoryInterface $configFactory;
  protected FileSystemInterface $fileSystem;

  public function __construct(
    ClientInterface $httpClient,
    ConfigFactoryInterface $configFactory,
    FileSystemInterface $fileSystem,
  ) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->fileSystem = $fileSystem;
  }

  public function getProviderKey(): string {
    return 'piper';
  }

  public function getLabel(): string {
    return 'Piper (self-hosted)';
  }

  public function getSupportedLanguages(): array {
    return ['en', 'uk'];
  }

  public function buildConfigForm(array $form, array $config): array {
    $form['endpoint'] = [
      '#type'          => 'textfield',
      '#title'         => t('Piper Endpoint URL'),
      '#default_value' => $config['endpoint'] ?? 'http://piper-service:5000/tts',
      '#description'   => t('Base URL of the Piper TTS microservice.'),
      '#required'      => TRUE,
    ];

    $langOptions = [];
    $langLabels  = ['en' => t('English'), 'uk' => t('Ukrainian')];
    foreach ($this->getSupportedLanguages() as $code) {
      $langOptions[$code] = $langLabels[$code] ?? strtoupper($code);
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
    $this->configFactory->getEditable('hear_me.provider.piper')
      ->set('endpoint',     $form_state->getValue(['provider_settings', 'endpoint']))
      ->set('default_lang', $form_state->getValue(['provider_settings', 'default_lang']))
      ->save();
  }

  public function synthesize(string $text, string $lang): ?Media {
    $config   = $this->configFactory->get('hear_me.provider.piper');
    $endpoint = $config->get('endpoint') ?? 'http://piper-service:5000/tts';

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
    $uri          = 'public://tts/' . md5($text . $lang . 'piper') . '.wav';

    $file = $this->fileSystem->saveData($audioContent, $uri, FileExists::Replace);

    if (!$file) {
      return NULL;
    }

    if ($file instanceof File) {
      $fileEntity = $file;
    }
    else {
      $fileEntity = File::create(['uri' => $uri]);
      $fileEntity->save();
    }

    $media = Media::create([
      'bundle'                  => 'audio',
      'name'                    => 'TTS (Piper): ' . substr($text, 0, 30),
      'field_media_audio_file'  => [
        'target_id' => $fileEntity->id(),
      ],
    ]);
    $media->save();

    return $media;
  }

}
