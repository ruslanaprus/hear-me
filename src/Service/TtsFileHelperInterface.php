<?php

namespace Drupal\hear_me\Service;

/**
 * Defines the contract for TTS file-path utilities.
 */
interface TtsFileHelperInterface {

  /**
   * Stream-wrapper base URI for all cached TTS audio files.
   */
  const TTS_URI_BASE = 'public://tts/';

  /**
   * Builds the canonical file URI for a TTS audio file.
   *
   * @param string $text
   *   The text that was synthesized.
   * @param string $lang
   *   The language code used for synthesis (e.g. 'en', 'uk').
   * @param string $providerKey
   *   The provider key (e.g. 'piper').
   * @param string $extension
   *   File extension without leading dot (e.g. 'wav').
   * @param string $cacheSalt
   *   Optional cache salt for provider configuration or source context.
   *
   * @return string
   *   A stream-wrapper URI such as 'public://tts/<hash>.wav'.
   */
  public function buildTtsUri(string $text, string $lang, string $providerKey, string $extension, string $cacheSalt = ''): string;

}
