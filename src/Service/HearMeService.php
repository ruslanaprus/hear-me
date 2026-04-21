<?php

namespace Drupal\hear_me\Service;

use GuzzleHttp\ClientInterface;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class HearMeService implements ContainerInjectionInterface {

  protected ClientInterface $httpClient;
  protected ?string $endpoint;

  public function __construct(ClientInterface $httpClient, ConfigFactoryInterface $configFactory) {
    $this->httpClient = $httpClient;
    $config = $configFactory->get('hear_me.settings');
    $this->endpoint = $config->get('endpoint');
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('http_client'),
      $container->get('config.factory')
    );
  }

  public function synthesize(string $text, string $lang = 'en'): ?Media {
    if (empty($this->endpoint)) {
      return NULL;
    }

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
      $file = $fileSystem->saveData($audioContent, 'public://tts/' . uniqid() . '.wav', $fileSystem::EXISTS_REPLACE);

      if (!$file) {
        return NULL;
      }

      $media = Media::create([
        'bundle' => 'audio',
        'name' => 'TTS: ' . substr($text, 0, 30),
        'field_media_audio_file' => [
          'target_id' => $file->id(),
        ],
      ]);
      $media->save();

      return $media;
    }

    return NULL;
  }
}
