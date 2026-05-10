<?php

namespace Drupal\hear_me\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides the "Listen to this page" TTS trigger block.
 *
 * The block renders a single button that the hear_me JavaScript library
 * uses to synthesise and play text-to-speech audio for the current page.
 */
#[Block(
  id: 'hear_me_block',
  admin_label: new TranslatableMarkup('HearMe TTS Block'),
  category: new TranslatableMarkup('Accessibility'),
)]
class HearMeBlock extends BlockBase {

  public function build(): array {
    return [
      'button' => [
        '#type'       => 'html_tag',
        '#tag'        => 'button',
        '#value'      => '🔊 ' . $this->t('Listen to this page'),
        '#attributes' => [
          'class'       => ['hear-me-block'],
          'aria-label'  => $this->t('Play text-to-speech for this page'),
          'data-action' => 'tts-page',
        ],
      ],
      '#cache' => [
        'contexts' => [],
        'tags'     => ['config:hear_me.settings'],
        'max-age'  => Cache::PERMANENT,
      ],
    ];
  }

}
