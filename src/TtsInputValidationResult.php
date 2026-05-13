<?php

namespace Drupal\hear_me;

/**
 * Value object returned after validating a TTS endpoint request.
 */
final readonly class TtsInputValidationResult {

  private function __construct(
    public ?string $text,
    public ?string $lang,
    public ?string $errorMessage,
  ) {}

  public static function valid(string $text, string $lang): self {
    return new self($text, $lang, NULL);
  }

  public static function invalid(string $errorMessage): self {
    return new self(NULL, NULL, $errorMessage);
  }

  public function isValid(): bool {
    return $this->errorMessage === NULL;
  }

}
