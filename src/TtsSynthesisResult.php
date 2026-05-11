<?php

namespace Drupal\hear_me;

/**
 * Value object returned by TTS provider plugins after synthesis.
 *
 * Providers are responsible for synthesising audio and persisting the raw
 * bytes to the file system. They return this lightweight DTO so that callers
 * (i.e. HearMeService) can handle Drupal entity creation without providers
 * needing to depend on the Media module.
 *
 * Both properties are immutable after construction; the object should be
 * treated as read-only by all consumers.
 */
final class TtsSynthesisResult {

  /**
   * Constructs a TtsSynthesisResult.
   *
   * @param string $uri
   *   The Drupal stream-wrapper URI of the saved audio file
   *   (e.g. 'public://tts/abc123.wav'). Must be a URI that resolves via
   *   FileSystemInterface::realpath().
   * @param string $bytes
   *   The raw audio file contents. Kept in the DTO so that HearMeService can
   *   stream them directly to the HTTP response without a second disk read
   *   when a cache hit has already loaded the file.
   */
  public function __construct(
    public readonly string $uri,
    public readonly string $bytes,
  ) {}

}
