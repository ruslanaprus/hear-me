<?php

namespace Drupal\hear_me\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class HearMeSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['hear_me.settings'];
  }

  public function getFormId() {
    return 'hear_me_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('hear_me.settings');

    $form['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Active TTS Provider'),
      '#options' => [
        'piper' => $this->t('Piper (self-hosted)'),
        'google' => $this->t('Google Cloud TTS'),
        'coqui' => $this->t('Coqui TTS'),
      ],
      '#default_value' => $config->get('provider') ?? 'piper',
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
      '#options' => $this->getProviderLanguages($form_state->getValue('provider')),
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
