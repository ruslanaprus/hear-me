<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\hear_me\TtsSynthesisResult;

interface TtsProviderInterface extends PluginInspectionInterface {

  /**
   * Synthesise text into speech.
   *
   * @param string $text
   *   The text to synthesise.
   * @param string $lang
   *   Language code (e.g. 'en', 'uk').
   *
   * @return \Drupal\hear_me\TtsSynthesisResult|null
   *   A DTO carrying the raw bytes and audio format, or NULL on failure.
   */
  public function synthesize(string $text, string $lang): ?TtsSynthesisResult;

  /**
   * Get supported languages for this provider.
   *
   * @return array
   *   Array of language codes supported, e.g. ['en', 'uk'].
   */
  public function getSupportedLanguages(): array;

  /**
   * Returns the provider's default audio file extension.
   *
   * @return string
   *   File extension without leading dot, e.g. 'wav'.
   */
  public function getDefaultExtension(): string;

}
