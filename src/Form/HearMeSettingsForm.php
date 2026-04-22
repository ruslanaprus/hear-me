<?php

namespace Drupal\hear_me\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hear_me\Service\HearMeService;

class HearMeSettingsForm extends ConfigFormBase {

  protected HearMeService $ttsService;

  public function __construct(HearMeService $ttsService) {
    $this->ttsService = $ttsService;
  }

  public static function create(\Symfony\Component\DependencyInjection\ContainerInterface $container) {
    return new static(
      $container->get('hear_me.service')
    );
  }

  protected function getEditableConfigNames() {
    return ['hear_me.settings'];
  }

  public function getFormId() {
    return 'hear_me_settings_form';
  }

  protected function getProviderLanguages(string $providerKey): array {
    $providers = $this->ttsService->getProviders();

    if (isset($providers[$providerKey])) {
      $langs = $providers[$providerKey]->getSupportedLanguages();

      $labels = [
        'en' => $this->t('English'),
        'uk' => $this->t('Ukrainian'),
      ];

      $options = [];
      foreach ($langs as $code) {
        $options[$code] = $labels[$code] ?? strtoupper($code);
      }
      return $options;
    }

    return ['en' => $this->t('English')];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('hear_me.settings');
    $providerKey = $form_state->getValue('provider') ?? $config->get('provider') ?? 'piper';

    $form['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Active TTS Provider'),
      '#options' => [
        'piper' => $this->t('Piper (self-hosted)'),
        'google' => $this->t('Google Cloud TTS'),
        'coqui' => $this->t('Coqui TTS'),
      ],
      '#default_value' => $providerKey,
    ];

    $form['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Provider Endpoint URL'),
      '#default_value' => $config->get('endpoint') ?? '',
      '#description' => $this->t('Base URL of the selected TTS provider.'),
    ];

    $form['cache_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable file-based caching'),
      '#default_value' => $config->get('cache_enabled') ?? TRUE,
    ];

    $form['default_lang'] = [
      '#type' => 'select',
      '#title' => $this->t('Default Language'),
      '#options' => $this->getProviderLanguages($providerKey),
      '#default_value' => $config->get('default_lang') ?? 'en',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('hear_me.settings')
      ->set('provider', $form_state->getValue('provider'))
      ->set('endpoint', $form_state->getValue('endpoint'))
      ->set('cache_enabled', $form_state->getValue('cache_enabled'))
      ->set('default_lang', $form_state->getValue('default_lang'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
