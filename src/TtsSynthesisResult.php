<?php

namespace Drupal\hear_me;

/**
 * Value object returned by TTS provider plugins after synthesis.
 *
 * Providers are responsible for synthesising audio bytes. HearMeService handles
 * URI generation, file persistence, and Drupal File/Media entity creation.
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
   */
  public function __construct(
    public readonly string $bytes,
  ) {}

}
