<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Core\Form\FormStateInterface;
use Drupal\hear_me\TtsSynthesisResult;

interface TtsProviderInterface {

  /**
   * Synthesise text into speech and persist the audio file to disk.
   *
   * @param string $text
   *   The text to synthesise.
   * @param string $lang
   *   Language code (e.g. 'en', 'uk').
   *
   * @return \Drupal\hear_me\TtsSynthesisResult|null
   *   A DTO carrying the saved file URI and raw bytes, or NULL on failure.
   */
  public function synthesize(string $text, string $lang): ?TtsSynthesisResult;

  /**
   * Get supported languages for this provider.
   *
   * @return array
   *   Array of language codes supported, e.g. ['en', 'uk'].
   */
  public function getSupportedLanguages(): array;

  /**
   * Returns the unique machine name for this provider.
   *
   * Must match the provider_key tag used in hear_me.services.yml.
   *
   * @return string
   *   Provider key, e.g. 'piper'.
   */
  public function getProviderKey(): string;

  /**
   * Returns a human-readable label for this provider.
   *
   * Used to populate the provider selector in the settings form.
   *
   * @return string
   *   Provider label, e.g. 'Piper (self-hosted)'.
   */
  public function getLabel(): string;

  /**
   * Builds the provider-specific configuration form elements.
   *
   * Called by HearMeSettingsForm to render provider settings inside the
   * 'provider_settings' fieldset. The returned elements are merged into the
   * fieldset container.
   *
   * @param array $form
   *   The provider_settings container element (may already have attributes set).
   * @param array $config
   *   The current raw config values for this provider's config object
   *   (e.g. the contents of hear_me.provider.piper).
   *
   * @return array
   *   The $form array with provider-specific form elements added.
   */
  public function buildConfigForm(array $form, array $config): array;

  /**
   * Saves provider-specific configuration from the submitted settings form.
   *
   * Called by HearMeSettingsForm::submitForm after the global settings have
   * been saved. Responsible for writing values from $form_state into this
   * provider's own config object (e.g. hear_me.provider.piper).
   *
   * @param array $form
   *   The full form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state. Provider values are expected under the
   *   'provider_settings' parent key.
   */
  public function submitConfigForm(array &$form, FormStateInterface $form_state): void;

}
