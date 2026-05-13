<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\hear_me\TtsSynthesisResult;

interface TtsProviderInterface {

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
   * Returns a human-readable label for this provider.
   *
   * Used to populate the provider selector in the settings form.
   *
   * @return string
   *   Provider label, e.g. 'Piper (self-hosted)'.
   */
  public function getLabel(): string;

  /**
   * Returns the provider's default audio MIME type.
   *
   * Used for cache lookup before synthesis occurs. Individual synthesis results
   * may still return a different MIME type if a provider supports that.
   *
   * @return string
   *   MIME type, e.g. 'audio/wav'.
   */
  public function getDefaultMimeType(): string;

  /**
   * Returns the provider's default audio file extension.
   *
   * @return string
   *   File extension without leading dot, e.g. 'wav'.
   */
  public function getDefaultExtension(): string;

}
