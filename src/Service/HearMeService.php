<?php

namespace Drupal\hear_me\Service;

use GuzzleHttp\ClientInterface;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class HearMeService implements ContainerInjectionInterface {

  protected ClientInterface $httpClient;
  protected string $endpoint;

  public function __construct(ClientInterface $httpClient, string $endpoint) {
    $this->httpClient = $httpClient;
    $this->endpoint = $endpoint;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('http_client'),
      $container->getParameter('hear_me.endpoint')
    );
  }

  public function synthesize(string $text, string $lang = 'en'): ?Media {
    $response = $this->httpClient->post($this->endpoint, [
      'json' => ['text' => $text, 'lang' => $lang],
    ]);

    if ($response->getStatusCode() === 200) {
      $audioContent = $response->getBody()->getContents();

      $fileSystem = \Drupal::service('file_system');
      $file = $fileSystem->saveData($audioContent, 'public://tts/' . uniqid() . '.wav', $fileSystem::EXISTS_REPLACE);

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