<?php

namespace Drupal\hear_me\Plugin\Filter;

use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\FilterProcessResult;

/**
 * Provides a filter to transform text into TTS playback buttons.
 *
 * @Filter(
 *   id = "filter_tts_playback",
 *   title = @Translation("TTS Playback Button"),
 *   description = @Translation("Transforms marked text into a TTS playback button."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE
 * )
 */
class FilterTtsPlayback extends FilterBase {

  public function process($text, $langcode) {
    $pattern = '/<tts>(.*?)<\/tts>/s';
    $replacement = '<button class="tts-play" data-text="$1" aria-label="Play text-to-speech" tabindex="0">🔊</button>';
    $newText = preg_replace($pattern, $replacement, $text);

    return new FilterProcessResult($newText);
  }
}
