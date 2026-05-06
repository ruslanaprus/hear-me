<?php

namespace Drupal\hear_me\Plugin\Filter;

use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\FilterProcessResult;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

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

  protected HearMeService $ttsService;

  /**
   * {@inheritdoc}
   *
   * Overrides the parent constructor to inject HearMeService.
   * FilterBase expects ($configuration, $plugin_id, $plugin_definition),
   * so those are passed through to the parent unchanged.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    HearMeService $ttsService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->ttsService = $ttsService;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('hear_me.service'),
    );
  }

  public function process($text, $langcode) {
    $effectiveLang = ($langcode && $langcode !== 'und')
      ? $langcode
      : $this->ttsService->getDefaultLang();

    $pattern = '/<tts>(.*?)<\/tts>/s';
    $newText = preg_replace_callback($pattern, function ($matches) use ($effectiveLang) {
      $raw  = $matches[1];
      $lang = htmlspecialchars($effectiveLang, ENT_QUOTES, 'UTF-8');

      return '<span class="tts-text">' . $raw . '</span>
              <button class="tts-play" data-text="' . $raw . '" data-lang="' . $lang . '" aria-label="Play text-to-speech" tabindex="0">🔊</button>
              <audio class="tts-audio" controls hidden></audio>';
    }, $text);

    return new FilterProcessResult($newText);
  }

  public function tips($long = FALSE) {
    return $this->t('Wrap text in <tts>…</tts> to display the text with a speaker button for playback.');
  }

}
