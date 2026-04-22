<?php

namespace Drupal\hear_me\Plugin\Field\Formatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;

/**
 * Plugin implementation of the 'tts_audio_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "tts_audio_formatter",
 *   label = @Translation("TTS Audio Player"),
 *   field_types = {
 *     "file"
 *   }
 * )
 */
class TtsAudioFormatter extends FormatterBase {

  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    foreach ($items as $delta => $item) {
      if ($file = $item->entity) {
        $url = file_create_url($file->getFileUri());
        $elements[$delta] = [
          '#type' => 'html_tag',
          '#tag' => 'audio',
          '#attributes' => [
            'src' => $url,
            'controls' => 'controls',
            'aria-label' => $this->t('Text-to-Speech audio player'),
            'tabindex' => '0',
          ],
        ];
      }
    }

    return $elements;
  }
}
