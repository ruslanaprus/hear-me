<?php

namespace Drupal\hear_me\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class HearMeSettingsForm extends ConfigFormBase {

  protected HearMeService $ttsService;

  public function __construct(HearMeService $ttsService) {
    $this->ttsService = $ttsService;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hear_me.service')
    );
  }

  protected function getEditableConfigNames(): array {
    $names = ['hear_me.settings'];
    foreach ($this->ttsService->getProviders() as $key => $provider) {
      $names[] = 'hear_me.provider.' . $key;
    }
    return $names;
  }

  public function getFormId(): string {
    return 'hear_me_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config      = $this->config('hear_me.settings');
    $providerKey = $form_state->getValue('provider') ?? $config->get('provider') ?? 'piper';
    $providers   = $this->ttsService->getProviders();

    $providerOptions = [];
    foreach ($providers as $key => $provider) {
      $providerOptions[$key] = $provider->getLabel();
    }

    $form['provider'] = [
      '#type'          => 'select',
      '#title'         => $this->t('Active TTS Provider'),
      '#options'       => $providerOptions,
      '#default_value' => $providerKey,
      '#ajax'          => [
        'callback' => '::ajaxProviderSettings',
        'wrapper'  => 'provider-settings-wrapper',
        'effect'   => 'fade',
      ],
    ];

    $form['cache_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable file-based caching'),
      '#description'   => $this->t('Cache synthesised audio files to avoid re-generating identical requests.'),
      '#default_value' => $config->get('cache_enabled') ?? TRUE,
    ];

    $form['provider_settings'] = [
      '#type'       => 'fieldset',
      '#title'      => $this->t('Provider Settings'),
      '#attributes' => ['id' => 'provider-settings-wrapper'],
    ];

    if (isset($providers[$providerKey])) {
      $providerConfig = $this->config('hear_me.provider.' . $providerKey)->getRawData();
      $form['provider_settings'] = $providers[$providerKey]
        ->buildConfigForm($form['provider_settings'], $providerConfig);
    }

    return parent::buildForm($form, $form_state);
  }

  public function ajaxProviderSettings(array &$form, FormStateInterface $form_state): array {
    return $form['provider_settings'];
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $providerKey = $form_state->getValue('provider');
    $providers   = $this->ttsService->getProviders();

    $this->configFactory->getEditable('hear_me.settings')
      ->set('provider',      $providerKey)
      ->set('cache_enabled', (bool) $form_state->getValue('cache_enabled'))
      ->save();

    if (isset($providers[$providerKey])) {
      $providers[$providerKey]->submitConfigForm($form, $form_state);
    }

    parent::submitForm($form, $form_state);
  }

}
