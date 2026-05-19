<?php

namespace Drupal\hear_me\Service;

/**
 * Provides shared file-path utilities for TTS audio caching.
 *
 * Centralises the URI scheme, directory, and hash algorithm so that every
   * part of the module produces identical URIs for the same synthesis inputs.
 */
class TtsFileHelper implements TtsFileHelperInterface {

  public function buildTtsUri(string $text, string $lang, string $providerKey, string $extension, string $cacheSalt = ''): string {
    $safeExtension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';
    $hash = hash('sha256', implode("\0", [$text, strtolower($lang), $providerKey, $safeExtension, $cacheSalt]));
    return self::TTS_URI_BASE . $hash . '.' . $safeExtension;
  }

}
