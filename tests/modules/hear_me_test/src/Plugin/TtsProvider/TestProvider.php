<?php

declare(strict_types=1);

namespace Drupal\hear_me_test\Plugin\TtsProvider;

use Drupal\hear_me\Plugin\TtsProvider\TtsProviderInterface;
use Drupal\hear_me\TtsSynthesisResult;

/**
 * Deterministic TTS provider used by automated tests.
 */
class TestProvider implements TtsProviderInterface {

  /**
   * {@inheritdoc}
   */
  public function synthesize(string $text, string $lang): ?TtsSynthesisResult {
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
  public function getLabel(): string {
    return 'Test provider';
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultMimeType(): string {
    return 'audio/wav';
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultExtension(): string {
    return 'wav';
  }

}
