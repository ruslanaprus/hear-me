<?php

namespace Drupal\hear_me\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a "Listen to this page" block.
 *
 * @Block(
 *   id = "hear_me_block",
 *   admin_label = @Translation("HearMe TTS Block")
 * )
 */
class HearMeBlock extends BlockBase {

  public function build() {
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => '🔊 ' . $this->t('Listen to this page'),
      '#attributes' => [
        'class' => ['hear-me-block'],
        'aria-label' => $this->t('Play text-to-speech for this page'),
        'tabindex' => '0',
        'data-action' => 'tts-page',
      ],
      '#attached' => [
        'library' => ['hear_me/frontend'],
      ],
    ];
  }
}
