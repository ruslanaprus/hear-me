<?php

declare(strict_types=1);

namespace Drupal\hear_me\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderManager;

/**
 * Resolves configured TTS providers and their metadata.
 *
 * @internal
 */
final class TtsProviderResolver {

  private \Psr\Log\LoggerInterface $logger;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TtsProviderManager $providerManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('hear_me');
  }

  public function getActiveProviderId(): string {
    $providerId = $this->configFactory->get('hear_me.settings')->get('provider');
    if (!$providerId) {
      throw new \RuntimeException(
        'HearMe: the "provider" key is missing from hear_me.settings configuration. ' .
        'Re-install the module or set the value at /admin/config/media/hear-me.'
      );
    }

    return $providerId;
  }

  public function getProvider(string $providerId): ?TtsProviderInterface {
    if (!$this->providerManager->hasDefinition($providerId)) {
      $this->logger->warning(
        'HearMe: provider plugin "@id" is not discoverable. Update the provider at /admin/config/media/hear-me.',
        ['@id' => $providerId]
      );
      return NULL;
    }

    $configuration = $this->configFactory
      ->get('hear_me.provider.' . $providerId)
      ->get();

    return $this->providerManager->createInstance($providerId, $configuration);
  }

  public function getProviderDefinitions(): array {
    return $this->providerManager->getDefinitions();
  }

  public function getDefaultLanguage(string $providerId): string {
    $language = $this->configFactory
      ->get('hear_me.provider.' . $providerId)
      ->get('default_lang');
    if (!$language) {
      throw new \RuntimeException(
        sprintf(
          'HearMe: the "default_lang" key is missing from hear_me.provider.%s configuration.',
          $providerId
        )
      );
    }

    return $language;
  }

  public function getSupportedLanguages(string $providerId): array {
    $provider = $this->getProvider($providerId);
    if ($provider !== NULL) {
      return $provider->getSupportedLanguages();
    }

    return [$this->getDefaultLanguage($providerId)];
  }

  public function getProviderConfigurationHashInput(string $providerId): array {
    return $this->configFactory
      ->get('hear_me.provider.' . $providerId)
      ->getRawData();
  }

}
