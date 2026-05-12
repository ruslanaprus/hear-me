<?php

namespace Drupal\hear_me\Plugin\Filter;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\Attribute\Filter;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\filter\Plugin\FilterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Transforms <tts>…</tts> markup into an inline TTS playback button.
 *
 * This filter receives existing HTML content (from the CKEditor body field)
 * and irreversibly replaces every <tts> element with three sibling elements:
 *   - a <span> that preserves the original visible text,
 *   - a <button> that triggers TTS playback via hear_me.js,
 *   - a hidden <audio> element used by the JS to play back synthesised audio.
 */
#[Filter(
  id: 'filter_tts_playback',
  title: new TranslatableMarkup('TTS Playback Button'),
  description: new TranslatableMarkup('Transforms <tts>…</tts> marked text into an inline TTS playback button.'),
  type: FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
)]
class FilterTtsPlayback extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The HearMe TTS service, used to resolve the effective language.
   *
   * @var \Drupal\hear_me\Service\HearMeService
   */
  protected HearMeService $ttsService;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->ttsService = $container->get('hear_me.service');
    return $instance;
  }

  public function process($text, $langcode): FilterProcessResult {
    $effectiveLang = ($langcode && $langcode !== 'und')
      ? $langcode
      : $this->ttsService->getDefaultLang();

    $pattern = '/<tts>(.*?)<\/tts>/s';
    $hasTtsMarkup = FALSE;
    $newText = preg_replace_callback($pattern, function ($matches) use ($effectiveLang, &$hasTtsMarkup) {
      $hasTtsMarkup = TRUE;
      $raw  = $matches[1];
      $lang = htmlspecialchars($effectiveLang, ENT_QUOTES, 'UTF-8');
      $plainText = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
      $plainText = str_replace("\u{00A0}", ' ', $plainText);
      $escaped   = htmlspecialchars($plainText, ENT_QUOTES, 'UTF-8');

      return '<span class="tts-text">' . $raw . '</span>
              <button class="tts-play" data-text="' . $escaped . '" data-lang="' . $lang . '" aria-label="Play text-to-speech" tabindex="0">🔊</button>
              <audio class="tts-audio" controls hidden></audio>';
    }, $text);

    $result = new FilterProcessResult($newText);
    if ($hasTtsMarkup) {
      $result->setAttachments([
        'library' => ['hear_me/frontend'],
        'drupalSettings' => [
          'hear_me' => [
            'default_lang' => $effectiveLang,
            'tts_url' => Url::fromRoute('hear_me.tts')->toString(),
            'csrf_token_url' => Url::fromRoute('system.csrftoken')->toString(),
          ],
        ],
      ]);
    }

    return $result;
  }

  public function tips($long = FALSE): string {
    return $this->t('Wrap text in <tts>…</tts> to display the text with a speaker button for playback.');
  }

}
