<?php

namespace Drupal\hear_me\Service;

/**
 * Provides shared file-path utilities for TTS audio caching.
 *
 * Centralises the URI scheme, directory, and hash algorithm so that every
 * part of the module (HearMeService, provider plugins, …) produces
 * identical URIs for the same (text, lang, provider) triplet.
 */
class TtsFileHelper {

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
   *
   * @return string
   *   A stream-wrapper URI such as 'public://tts/<hash>.wav'.
   */
  public function buildTtsUri(string $text, string $lang, string $providerKey): string {
    return self::TTS_URI_BASE . md5($text . $lang . $providerKey) . '.wav';
  }

}
