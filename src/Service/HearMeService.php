<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class HearMeService {

  protected ConfigFactoryInterface $configFactory;
  protected array $providers;

  public function __construct(ConfigFactoryInterface $configFactory, iterable $providers) {
    $this->configFactory = $configFactory;
    $this->providers = $providers;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('hear_me.providers') // service tag collection
    );
  }

  public function synthesize(string $text, string $lang): ?Media {
    $config = $this->configFactory->get('hear_me.settings');
    $providerKey = $config->get('provider') ?? 'piper';

    if (!isset($this->providers[$providerKey])) {
      return NULL;
    }

    $provider = $this->providers[$providerKey];

        if ($config->get('cache_enabled')) {
          $hash = md5($text . $lang . $providerKey);
          $uri = 'public://tts/' . $hash . '.wav';
          if (file_exists(\Drupal::service('file_system')->realpath($uri))) {
            $files = \Drupal::entityTypeManager()->getStorage('file')->loadByProperties(['uri' => $uri]);
            if ($files) {
              $file = reset($files);
              $media = \Drupal::entityTypeManager()->getStorage('media')->loadByProperties([
                'field_media_audio_file' => $file->id(),
              ]);
              if ($media) {
                return reset($media);
              }
            }
          }
        }
        return $provider->synthesize($text, $lang);
    }
}
