<?php

namespace Drupal\hear_me\Message;

class TtsJobMessage {
  public function __construct(
    public readonly int $nid,
    public readonly string $text,
    public readonly string $lang
  ) {}
}
