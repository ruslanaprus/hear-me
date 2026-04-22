<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

class PiperProvider implements TtsProviderInterface {

  protected ClientInterface $httpClient;
  protected string $endpoint;

  public function __construct(ClientInterface $httpClient, ConfigFactoryInterface $configFactory) {
    $this->httpClient = $httpClient;
    $config = $configFactory->get('hear_me.settings');
    $this->endpoint = $config->get('endpoint') ?? 'http://piper-service:5000/tts';
  }

  public function synthesize(string $text, string $lang): ?Media {
    try {
      $response = $this->httpClient->request('POST', $this->endpoint, [
        'json' => ['text' => $text, 'lang' => $lang],
      ]);
    } catch (\Exception $e) {
      return NULL;
    }

    if ($response->getStatusCode() === 200) {
      $audioContent = $response->getBody()->getContents();

      $fileSystem = \Drupal::service('file_system');
      $file = $fileSystem->saveData(
        $audioContent,
        'public://tts/' . md5($text . $lang . 'piper') . '.wav',
        $fileSystem::EXISTS_REPLACE
      );

      if (!$file) {
        return NULL;
      }

      $media = Media::create([
        'bundle' => 'audio',
        'name' => 'TTS (Piper): ' . substr($text, 0, 30),
        'field_media_audio_file' => [
          'target_id' => $file->id(),
        ],
      ]);
      $media->save();

      return $media;
    }

    return NULL;
  }

  public function getSupportedLanguages(): array {
    return ['en', 'uk'];
  }
}
