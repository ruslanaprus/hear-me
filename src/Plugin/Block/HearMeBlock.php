<?php

namespace Drupal\hear_me\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
class HearMeBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected HearMeService $ttsService;

  protected LanguageManagerInterface $languageManager;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    HearMeService $ttsService,
    LanguageManagerInterface $languageManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->ttsService = $ttsService;
    $this->languageManager = $languageManager;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('hear_me.service'),
      $container->get('language_manager'),
    );
  }

  public function defaultConfiguration(): array {
    return [
      'label_display' => '0',
    ] + parent::defaultConfiguration();
  }

  public function build(): array {
    $currentLangcode = $this->languageManager->getCurrentLanguage()->getId();
    $supportedLangs = $this->ttsService->getSupportedLanguages();
    $shortCode = strtolower(substr($currentLangcode, 0, 2));
    $resolvedLang = in_array($shortCode, $supportedLangs, TRUE)
      ? $shortCode
      : $this->ttsService->getDefaultLang();

    return [
      'button' => [
        '#type'       => 'html_tag',
        '#tag'        => 'button',
        '#value'      => '🔊 ' . $this->t('Listen to this page'),
        '#attributes' => [
          'class'       => ['hear-me-block', 'hear-me-control-button'],
          'aria-label'  => $this->t('Play text-to-speech for this page'),
          'data-action' => 'tts-page',
        ],
      ],
      'status' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => '',
        '#attributes' => [
          'class' => ['hear-me-status'],
          'role' => 'status',
          'aria-live' => 'polite',
          'hidden' => 'hidden',
        ],
      ],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags'     => ['config:hear_me.settings'],
        'max-age'  => Cache::PERMANENT,
      ],
      '#attached' => [
        'library' => ['hear_me/frontend'],
        'drupalSettings' => [
          'hear_me' => [
            'default_lang' => $resolvedLang,
            'tts_url' => Url::fromRoute('hear_me.tts')->toString(),
            'csrf_token_url' => Url::fromRoute('system.csrftoken')->toString(),
          ],
        ],
      ],
    ];
  }

}
