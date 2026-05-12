<?php

namespace Drupal\hear_me\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderConfigurableInterface;
use Drupal\hear_me\Service\HearMeService;
use Symfony\Component\DependencyInjection\ContainerInterface;

class HearMeSettingsForm extends ConfigFormBase {

  protected HearMeService $ttsService;

  /**
   * The entity type manager, used to list available node bundles.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    HearMeService $ttsService,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
    $this->ttsService        = $ttsService;
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('hear_me.service'),
      $container->get('entity_type.manager'),
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
    $config    = $this->config('hear_me.settings');
    $providers = $this->ttsService->getProviders();

    $providerOptions = [];
    foreach ($providers as $key => $provider) {
      $providerOptions[$key] = $provider->getLabel();
    }

    $providerKey = $form_state->getValue('provider')
      ?? $config->get('provider')
      ?? array_key_first($providerOptions);

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

    $form['tts_audio_field'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('TTS Audio Field'),
      '#description'   => $this->t('Machine name of the node field used to attach generated TTS audio media (e.g. <code>field_tts_audio</code>).'),
      '#default_value' => $config->get('tts_audio_field') ?? 'field_tts_audio',
      '#required'      => TRUE,
    ];

    $bundleOptions = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      $bundleOptions[$type->id()] = $type->label();
    }

    $form['queue_bundles'] = [
      '#type'          => 'checkboxes',
      '#title'         => $this->t('Queue TTS pre-generation for content types'),
      '#description'   => $this->t(
        'When a new node of the selected type is created, its title and body '
        . 'are queued for background TTS synthesis. Leave all unchecked to '
        . 'disable automatic pre-generation entirely.'
      ),
      '#options'       => $bundleOptions,
      '#default_value' => $config->get('queue_bundles') ?? [],
    ];

    $form['provider_settings'] = [
      '#type'       => 'fieldset',
      '#title'      => $this->t('Provider Settings'),
      '#attributes' => ['id' => 'provider-settings-wrapper'],
    ];

    if (isset($providers[$providerKey]) && $providers[$providerKey] instanceof TtsProviderConfigurableInterface) {
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

    $queueBundles = array_values(
      array_filter($form_state->getValue('queue_bundles') ?? [])
    );

    $this->configFactory->getEditable('hear_me.settings')
      ->set('provider',        $providerKey)
      ->set('cache_enabled',   (bool) $form_state->getValue('cache_enabled'))
      ->set('tts_audio_field', $form_state->getValue('tts_audio_field'))
      ->set('queue_bundles',   $queueBundles)
      ->save();

    if (isset($providers[$providerKey]) && $providers[$providerKey] instanceof TtsProviderConfigurableInterface) {
      $providers[$providerKey]->submitConfigForm($form, $form_state);
    }

    parent::submitForm($form, $form_state);
  }

}
