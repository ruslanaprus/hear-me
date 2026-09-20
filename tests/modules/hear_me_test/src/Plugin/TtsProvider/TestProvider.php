<?php

declare(strict_types=1);

namespace Drupal\hear_me_test\Plugin\TtsProvider;

use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hear_me\Attribute\TtsProvider;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface;
use Drupal\hear_me\TtsSynthesisResult;

/**
 * Deterministic TTS provider used by automated tests.
 */
#[TtsProvider(
  id: 'test',
  label: new TranslatableMarkup('Test provider'),
)]
class TestProvider extends PluginBase implements TtsProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function synthesize(string $text, string $lang): ?TtsSynthesisResult {
    if (\Drupal::state()->get('hear_me_test.provider_unavailable', FALSE)) {
      return NULL;
    }
    return new TtsSynthesisResult('test-audio:' . $lang . ':' . $text, 'audio/wav', 'wav');
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedLanguages(): array {
    return ['en'];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExtension(): string {
    return 'wav';
  }

}
