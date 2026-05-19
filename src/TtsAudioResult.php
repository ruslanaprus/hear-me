<?php

namespace Drupal\hear_me;

/**
 * Value object returned to playback callers after synthesis/cache lookup.
 */
final class TtsAudioResult {

  public readonly string $bytes;

  public readonly string $mimeType;

  public readonly string $extension;

  public readonly ?string $uri;

  public readonly ?int $fid;

  public function __construct(string $bytes, string $mimeType, string $extension, ?string $uri = NULL, ?int $fid = NULL) {
    $this->bytes = $bytes;
    $this->mimeType = $mimeType;
    $this->extension = preg_replace('/[^a-z0-9]/', '', strtolower($extension)) ?: 'bin';
    $this->uri = $uri;
    $this->fid = $fid;
  }

}
