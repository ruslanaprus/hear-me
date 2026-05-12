<?php

namespace Drupal\hear_me\Plugin\TtsProvider;

use Drupal\Core\Form\FormStateInterface;

interface TtsProviderConfigurableInterface {

  /**
   * Builds the provider-specific configuration form elements.
   *
   * @param array $form
   *   The provider_settings container element.
   * @param array $config
   *   The current raw config values for this provider's config object.
   *
   * @return array
   *   The $form array with provider-specific form elements added.
   */
  public function buildConfigForm(array $form, array $config): array;

  /**
   * Saves provider-specific configuration from the submitted settings form.
   *
   * @param array $form
   *   The full form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state.
   */
  public function submitConfigForm(array &$form, FormStateInterface $form_state): void;

}
