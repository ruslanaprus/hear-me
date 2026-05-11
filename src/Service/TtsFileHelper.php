<?php

namespace Drupal\hear_me\Service;

/**
 * Provides shared file-path utilities for TTS audio caching.
 *
 * Centralises the URI scheme, directory, and hash algorithm so that every
 * part of the module (HearMeService, provider plugins, …) produces
 * identical URIs for the same (text, lang, provider) triplet.
 */
class TtsFileHelper implements TtsFileHelperInterface {

  public function buildTtsUri(string $text, string $lang, string $providerKey): string {
    return self::TTS_URI_BASE . md5($text . $lang . $providerKey) . '.wav';
  }

}
