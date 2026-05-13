<?php

namespace Drupal\hear_me\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderConfigurableInterface;
use Drupal\hear_me\Service\HearMeService;
use Drupal\hear_me\Service\HearMeInputValidator;
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

    $form['max_request_bytes'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum TTS request body size'),
      '#description' => $this->t('Maximum JSON request body size accepted by the TTS endpoint, in bytes.'),
      '#default_value' => $config->get('max_request_bytes') ?? HearMeInputValidator::DEFAULT_MAX_REQUEST_BYTES,
      '#min' => HearMeInputValidator::MIN_REQUEST_BYTES,
      '#max' => HearMeInputValidator::ABSOLUTE_MAX_REQUEST_BYTES,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['max_text_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum TTS text length'),
      '#description' => $this->t('Maximum normalized text length accepted by the TTS endpoint, in characters.'),
      '#default_value' => $config->get('max_text_length') ?? HearMeInputValidator::DEFAULT_MAX_TEXT_LENGTH,
      '#min' => HearMeInputValidator::MIN_TEXT_LENGTH,
      '#max' => HearMeInputValidator::ABSOLUTE_MAX_TEXT_LENGTH,
      '#step' => 1,
      '#required' => TRUE,
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

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $maxRequestBytes = (int) $form_state->getValue('max_request_bytes');
    if ($maxRequestBytes < HearMeInputValidator::MIN_REQUEST_BYTES || $maxRequestBytes > HearMeInputValidator::ABSOLUTE_MAX_REQUEST_BYTES) {
      $form_state->setErrorByName('max_request_bytes', $this->t('Maximum request body size must be between @min and @max bytes.', [
        '@min' => HearMeInputValidator::MIN_REQUEST_BYTES,
        '@max' => HearMeInputValidator::ABSOLUTE_MAX_REQUEST_BYTES,
      ]));
    }

    $maxTextLength = (int) $form_state->getValue('max_text_length');
    if ($maxTextLength < HearMeInputValidator::MIN_TEXT_LENGTH || $maxTextLength > HearMeInputValidator::ABSOLUTE_MAX_TEXT_LENGTH) {
      $form_state->setErrorByName('max_text_length', $this->t('Maximum text length must be between @min and @max characters.', [
        '@min' => HearMeInputValidator::MIN_TEXT_LENGTH,
        '@max' => HearMeInputValidator::ABSOLUTE_MAX_TEXT_LENGTH,
      ]));
    }
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
      ->set('max_request_bytes', (int) $form_state->getValue('max_request_bytes'))
      ->set('max_text_length', (int) $form_state->getValue('max_text_length'))
      ->set('tts_audio_field', $form_state->getValue('tts_audio_field'))
      ->set('queue_bundles',   $queueBundles)
      ->save();

    if (isset($providers[$providerKey]) && $providers[$providerKey] instanceof TtsProviderConfigurableInterface) {
      $providers[$providerKey]->submitConfigForm($form, $form_state);
    }

    parent::submitForm($form, $form_state);
  }

}
