<?php

declare(strict_types=1);

namespace Drupal\hear_me\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a TTS provider plugin.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
class TtsProvider extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
  ) {}

}
