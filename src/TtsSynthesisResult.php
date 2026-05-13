<?php

namespace Drupal\hear_me;

/**
 * Value object returned by TTS provider plugins after synthesis.
 *
 * Providers are responsible for synthesising audio bytes and describing their
 * audio format. HearMeService handles URI generation, file persistence, and
 * Drupal File/Media entity creation.
 *
 * Both properties are immutable after construction; the object should be
 * treated as read-only by all consumers.
 */
final class TtsSynthesisResult {

  /**
   * Constructs a TtsSynthesisResult.
   *
   * @param string $bytes
   *   The raw audio file contents. Kept in the DTO so that HearMeService can
   *   stream them directly to the HTTP response without a second disk read
   *   when a cache hit has already loaded the file.
   * @param string $mimeType
   *   MIME type for the returned audio bytes, e.g. 'audio/wav'.
   * @param string $extension
   *   File extension without leading dot, e.g. 'wav'.
   */
  public readonly string $bytes;

  public readonly string $mimeType;

  public readonly string $extension;

  public function __construct(string $bytes, string $mimeType, string $extension) {
    $this->bytes = $bytes;
    $this->mimeType = $mimeType;
    $this->extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';
  }

}
