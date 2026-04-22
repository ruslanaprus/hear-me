<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\media\Entity\Media;

interface TtsProviderInterface {

  /**
   * Synthesize text into speech.
   *
   * @param string $text
   *   The text to synthesize.
   * @param string $lang
   *   Language code (e.g., 'en', 'uk').
   *
   * @return \Drupal\media\Entity\Media|null
   *   Media entity containing the audio file, or NULL on failure.
   */
  public function synthesize(string $text, string $lang): ?Media;

  /**
   * Get supported languages for this provider.
   *
   * @return array
   *   Array of language codes supported.
   */
  public function getSupportedLanguages(): array;
}
