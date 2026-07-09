<?php

namespace Drupal\hear_me\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\hear_me\Plugin\TtsProvider\TtsProviderConfigurableInterface;
use Drupal\hear_me\Service\HearMeAudioFieldValidator;
use Drupal\hear_me\Service\HearMeExistingContentQueue;
use Drupal\hear_me\Service\HearMeService;
use Drupal\hear_me\Service\HearMeInputValidator;
use Drupal\hear_me\Service\HearMeSetupStatus;
use Drupal\hear_me\Service\TtsCacheManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

class HearMeSettingsForm extends ConfigFormBase {

  protected HearMeService $ttsService;

  /**
   * The entity type manager, used to list available node bundles.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  protected TtsCacheManager $cacheManager;

  protected HearMeSetupStatus $setupStatus;

  protected HearMeExistingContentQueue $existingContentQueue;

  protected HearMeAudioFieldValidator $audioFieldValidator;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    HearMeService $ttsService,
    EntityTypeManagerInterface $entityTypeManager,
    TtsCacheManager $cacheManager,
    HearMeSetupStatus $setupStatus,
    HearMeExistingContentQueue $existingContentQueue,
    HearMeAudioFieldValidator $audioFieldValidator,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
    $this->ttsService        = $ttsService;
    $this->entityTypeManager = $entityTypeManager;
    $this->cacheManager      = $cacheManager;
    $this->setupStatus       = $setupStatus;
    $this->existingContentQueue = $existingContentQueue;
    $this->audioFieldValidator = $audioFieldValidator;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('hear_me.service'),
      $container->get('entity_type.manager'),
      $container->get('hear_me.cache_manager'),
      $container->get('hear_me.setup_status'),
      $container->get('hear_me.existing_content_queue'),
      $container->get('hear_me.audio_field_validator'),
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
    $runtimeCacheScheme = $config->get('runtime_cache_scheme') ?? 'private';
    if (!in_array($runtimeCacheScheme, ['private', 'public'], TRUE)) {
      $runtimeCacheScheme = 'private';
    }

    $form['setup_status'] = $this->buildSetupStatusPanel();

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

    if ($this->anonymousCanUseTts()) {
      $form['anonymous_permission_warning'] = $this->buildWarning(
        $this->t('The Anonymous role currently has the Use TTS playback permission. This exposes a public resource-consuming endpoint; keep strict IP limits enabled and avoid public runtime caching.')
      );
    }

    $form['cache_enabled'] = [
      '#type'          => 'checkbox',
      '#title'         => $this->t('Enable file-based caching'),
      '#description'   => $this->t('Cache synthesised runtime playback audio files to avoid re-generating identical requests. If disabled, click-triggered playback is returned directly and not persisted.'),
      '#default_value' => $config->get('cache_enabled') ?? TRUE,
    ];

    $stats = $this->cacheManager->getStats();
    $form['runtime_cache'] = [
      '#type' => 'details',
      '#title' => $this->t('Runtime cache retention'),
      '#open' => TRUE,
      '#description' => $this->t('Controls only runtime playback cache entries. Queue-generated media attached to content is not purged by these limits.'),
    ];

    $form['runtime_cache']['runtime_cache_scheme'] = [
      '#type' => 'radios',
      '#title' => $this->t('Runtime cache file storage'),
      '#description' => $this->t('Private storage is recommended because runtime playback can include selected text or authenticated page content. If private files are selected but not configured, runtime playback still works but generated audio is not persisted.'),
      '#options' => [
        'private' => $this->t('Private files (recommended)'),
        'public' => $this->t('Public files'),
      ],
      '#default_value' => $runtimeCacheScheme,
      '#required' => TRUE,
    ];

    if ($runtimeCacheScheme === 'private' && !$this->cacheManager->isRuntimeCacheStorageAvailable()) {
      $form['runtime_cache']['private_storage_warning'] = $this->buildWarning(
        $this->t('Private runtime cache storage is selected, but Drupal private files are not configured. Runtime audio will be generated on demand and returned to the browser, but it will not be persisted until private files are configured or Public files is selected.')
      );
    }
    elseif ($runtimeCacheScheme === 'public') {
      $form['runtime_cache']['public_storage_warning'] = $this->buildWarning(
        $this->t('Public runtime cache storage can expose generated speech files by URL. Use it only for sites where generated playback audio is safe to publish.')
      );
    }

    $form['runtime_cache']['cache_inline_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Inline cache TTL'),
      '#description' => $this->t('Seconds to keep audio generated from inline <tts> buttons. Set to 0 to disable persistent inline caching.'),
      '#default_value' => $config->get('cache_inline_ttl') ?? 2592000,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['runtime_cache']['cache_page_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Whole-page cache TTL'),
      '#description' => $this->t('Seconds to keep audio generated from the block whole-page action. Keep this short because the text is extracted from the rendered DOM.'),
      '#default_value' => $config->get('cache_page_ttl') ?? 86400,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['runtime_cache']['cache_selection_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Selected text cache TTL'),
      '#description' => $this->t('Seconds to keep audio generated from selected text or selected sections. Set to 0 to avoid caching ad-hoc user selections.'),
      '#default_value' => $config->get('cache_selection_ttl') ?? 3600,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['runtime_cache']['cache_ad_hoc_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Ad-hoc cache TTL'),
      '#description' => $this->t('Fallback TTL for API requests that do not declare a known source.'),
      '#default_value' => $config->get('cache_ad_hoc_ttl') ?? 0,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['runtime_cache']['cache_max_files'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum runtime cache files'),
      '#description' => $this->t('Oldest runtime cache entries are purged when this count is exceeded. Set to 0 for no file-count limit.'),
      '#default_value' => $config->get('cache_max_files') ?? 5000,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['runtime_cache']['cache_max_total_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum runtime cache size'),
      '#description' => $this->t('Maximum tracked runtime cache size in MB. Oldest entries are purged first. Set to 0 for no size limit.'),
      '#default_value' => $config->get('cache_max_total_mb') ?? 512,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['runtime_cache']['cache_stats'] = [
      '#type' => 'item',
      '#title' => $this->t('Current tracked runtime cache'),
      '#markup' => $this->t('@count file(s), @size MB.', [
        '@count' => $stats['count'],
        '@size' => number_format($stats['bytes'] / 1048576, 2),
      ]),
    ];

    $form['runtime_cache']['clear_runtime_cache'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear generated runtime audio cache'),
      '#submit' => ['::clearRuntimeCacheSubmit'],
      '#limit_validation_errors' => [],
    ];

    $form['rate_limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Rate limits and quotas'),
      '#open' => TRUE,
      '#description' => $this->t('Uses Drupal Flood API. Set any limit to 0 to disable that specific throttle.'),
    ];

    $form['rate_limits']['rate_limit_window_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Short rate-limit window'),
      '#description' => $this->t('Window length in seconds for user/IP/role throttles.'),
      '#default_value' => $config->get('rate_limit_window_seconds') ?? 60,
      '#min' => 1,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['rate_limits']['rate_limit_user_requests'] = [
      '#type' => 'number',
      '#title' => $this->t('Requests per user per window'),
      '#default_value' => $config->get('rate_limit_user_requests') ?? 20,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['rate_limits']['rate_limit_ip_requests'] = [
      '#type' => 'number',
      '#title' => $this->t('Requests per IP per window'),
      '#default_value' => $config->get('rate_limit_ip_requests') ?? 60,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['rate_limits']['rate_limit_role_requests'] = [
      '#type' => 'number',
      '#title' => $this->t('Requests per role set per window'),
      '#description' => $this->t('Optional aggregate throttle for all users sharing the same role set. Disabled by default.'),
      '#default_value' => $config->get('rate_limit_role_requests') ?? 0,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['rate_limits']['daily_user_quota'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily requests per user'),
      '#default_value' => $config->get('daily_user_quota') ?? 500,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
    ];

    $form['rate_limits']['monthly_user_quota'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly requests per user'),
      '#default_value' => $config->get('monthly_user_quota') ?? 5000,
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
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

    $audioFieldName = $config->get('tts_audio_field') ?? 'field_tts_audio';

    $form['tts_audio_field'] = [
      '#type'          => 'textfield',
      '#title'         => $this->t('TTS Audio Field'),
      '#description'   => $this->t('Machine name of the node field used to attach generated TTS audio media (e.g. <code>field_tts_audio</code>).'),
      '#default_value' => $audioFieldName,
      '#required'      => TRUE,
    ];

    $bundleOptions = $this->getNodeBundleOptions();

    $form['queue_bundles'] = [
      '#type'          => 'checkboxes',
      '#title'         => $this->t('Queue TTS pre-generation for content types'),
      '#description'   => $this->t(
        'When a new node of the selected type is created, its title and body '
        . 'are queued for background TTS synthesis. Leave all unchecked to '
        . 'disable automatic pre-generation entirely. Queue-generated media uses the installed HearMe Audio file field, which stores files publicly by default.'
      ),
      '#options'       => $bundleOptions,
      '#default_value' => $config->get('queue_bundles') ?? [],
    ];

    $form['queue_generated_audio_public_warning'] = $this->buildWarning(
      $this->t('Queue-generated audio attached to content is saved as Media/File entities under public://tts/. Those files can be publicly reachable by URL, including audio generated from unpublished or access-restricted content. Only enable queue pre-generation for content that is safe to expose as generated audio.')
    );

    $form['replace_existing_generated_audio'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Replace existing HearMe-generated audio when content changes'),
      '#description' => $this->t('When enabled, regenerated queue audio replaces media that appears to have been generated by HearMe. This keeps attached audio current after title/body/language changes.'),
      '#default_value' => $config->get('replace_existing_generated_audio') ?? TRUE,
    ];

    $form['overwrite_manual_audio'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Overwrite manually selected audio'),
      '#description' => $this->t('When disabled, HearMe will not replace an existing audio field value unless it appears to be HearMe-generated. Keep this disabled to preserve editorial overrides.'),
      '#default_value' => $config->get('overwrite_manual_audio') ?? FALSE,
    ];

    $form['audio_field_setup'] = [
      '#type' => 'details',
      '#title' => $this->t('Audio field setup'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#description' => $this->t('The queue worker can attach generated HearMe Audio media only when the configured TTS Audio Field exists on the target content type. Use this setup action to create that media reference field automatically.'),
    ];

    $form['audio_field_setup']['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Create the configured audio field on content types'),
      '#description' => $this->t('Existing fields are skipped. The field references only HearMe Audio media and is shown on the node display by default.'),
      '#options' => $this->getAudioFieldSetupOptions($audioFieldName, $bundleOptions),
    ];

    $form['audio_field_setup']['create_audio_field'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create HearMe audio field'),
      '#submit' => ['::createAudioFieldSubmit'],
      '#validate' => ['::validateAudioFieldSetup'],
      '#limit_validation_errors' => [
        ['tts_audio_field'],
        ['audio_field_setup', 'bundles'],
      ],
    ];

    $form['existing_content_queue'] = [
      '#type' => 'details',
      '#title' => $this->t('Existing content audio generation'),
      '#open' => FALSE,
      '#tree' => TRUE,
      '#description' => $this->t('Queue audio generation for existing nodes in the selected content types. This only adds queue jobs; cron or queue workers generate and attach audio later.'),
    ];

    $form['existing_content_queue']['public_audio_warning'] = $this->buildWarning(
      $this->t('Backfilled audio is saved under public://tts/ as generated Media/File entities. Do not include unpublished or access-restricted content unless the generated audio is safe to expose by public file URL.')
    );

    $form['existing_content_queue']['unpublished_audio_warning'] = [
      '#type' => 'item',
      '#title' => $this->t('Unpublished content warning'),
      '#markup' => $this->t('If you check Include unpublished content, HearMe may generate public audio files from draft or access-restricted text. You must confirm this separately before queueing.'),
    ];

    $form['existing_content_queue']['include_unpublished'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include unpublished content'),
      '#description' => $this->t('Leave unchecked to queue only published nodes. Check this only when generated audio for unpublished content is safe to expose as public media.'),
      '#default_value' => FALSE,
    ];

    $form['existing_content_queue']['confirm_unpublished_public_audio'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand unpublished content audio may be publicly accessible'),
      '#description' => $this->t('Required only when Include unpublished content is checked.'),
      '#default_value' => FALSE,
    ];

    $form['existing_content_queue']['requeue_existing'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Requeue content that already has audio'),
      '#description' => $this->t('Leave unchecked to queue only nodes whose configured TTS audio field is empty. Existing attached audio remains visible until a queued replacement succeeds.'),
      '#default_value' => FALSE,
    ];

    $form['existing_content_queue']['queue_existing_content'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue existing content'),
      '#submit' => ['::queueExistingContentSubmit'],
      '#validate' => ['::validateQueueExistingContent'],
      '#limit_validation_errors' => [
        ['tts_audio_field'],
        ['queue_bundles'],
        ['existing_content_queue'],
      ],
    ];

    $form['provider_settings'] = [
      '#type'       => 'fieldset',
      '#title'      => $this->t('Provider Settings'),
      '#tree'       => TRUE,
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

    $nonNegativeFields = [
      'cache_inline_ttl' => $this->t('Inline cache TTL'),
      'cache_page_ttl' => $this->t('Whole-page cache TTL'),
      'cache_selection_ttl' => $this->t('Selected text cache TTL'),
      'cache_ad_hoc_ttl' => $this->t('Ad-hoc cache TTL'),
      'cache_max_files' => $this->t('Maximum runtime cache files'),
      'cache_max_total_mb' => $this->t('Maximum runtime cache size'),
      'rate_limit_user_requests' => $this->t('Requests per user per window'),
      'rate_limit_ip_requests' => $this->t('Requests per IP per window'),
      'rate_limit_role_requests' => $this->t('Requests per role set per window'),
      'daily_user_quota' => $this->t('Daily requests per user'),
      'monthly_user_quota' => $this->t('Monthly requests per user'),
    ];

    foreach ($nonNegativeFields as $field => $label) {
      if ((int) $form_state->getValue($field) < 0) {
        $form_state->setErrorByName($field, $this->t('@label must be 0 or greater.', ['@label' => $label]));
      }
    }

    if ((int) $form_state->getValue('rate_limit_window_seconds') < 1) {
      $form_state->setErrorByName('rate_limit_window_seconds', $this->t('Short rate-limit window must be at least 1 second.'));
    }

    if (!in_array($form_state->getValue('runtime_cache_scheme'), ['private', 'public'], TRUE)) {
      $form_state->setErrorByName('runtime_cache_scheme', $this->t('Runtime cache file storage must be private or public.'));
    }

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

    $fieldName = (string) $form_state->getValue('tts_audio_field');
    $this->validateAudioFieldName($fieldName, 'tts_audio_field', $form_state);

    $queueBundles = array_values(array_filter($form_state->getValue('queue_bundles') ?? []));
    if ($queueBundles) {
      $summary = $this->audioFieldValidator->validateBundles(
        $queueBundles,
        $fieldName,
        !$form_state->getValue('overwrite_manual_audio'),
      );
      if ($summary['errors']) {
        $form_state->setErrorByName('queue_bundles', $this->t('The configured TTS audio field is not compatible with every selected content type: @messages', [
          '@messages' => $this->formatValidationMessages($summary['errors']),
        ]));
      }
    }
  }

  public function validateAudioFieldSetup(array &$form, FormStateInterface $form_state): void {
    $this->validateAudioFieldName((string) $form_state->getValue('tts_audio_field'), 'tts_audio_field', $form_state);

    $bundles = array_filter($form_state->getValue(['audio_field_setup', 'bundles']) ?? []);
    if (!$bundles) {
      $form_state->setErrorByName('audio_field_setup][bundles', $this->t('Select at least one content type.'));
    }

    if (!$this->entityTypeManager->getStorage('media_type')->load('hear_me_audio')) {
      $form_state->setErrorByName('audio_field_setup][bundles', $this->t('The HearMe Audio media type is missing. Reinstall the module or restore the media type before creating node audio fields.'));
    }

    $fieldName = (string) $form_state->getValue('tts_audio_field');
    $storageSummary = $this->audioFieldValidator->validateStorage($fieldName);
    if ($storageSummary['errors']) {
      $form_state->setErrorByName('tts_audio_field', $this->t('The existing field storage is not compatible with HearMe audio: @messages', [
        '@messages' => $this->formatValidationMessages($storageSummary['errors']),
      ]));
    }

    $existingBundles = array_values(array_filter($bundles, static fn($bundle) => FieldConfig::loadByName('node', $bundle, $fieldName)));
    if ($existingBundles) {
      $summary = $this->audioFieldValidator->validateBundles($existingBundles, $fieldName);
      if ($summary['errors']) {
        $form_state->setErrorByName('audio_field_setup][bundles', $this->t('One or more existing fields are not compatible with HearMe audio: @messages', [
          '@messages' => $this->formatValidationMessages($summary['errors']),
        ]));
      }
    }
  }

  public function validateQueueExistingContent(array &$form, FormStateInterface $form_state): void {
    $this->validateAudioFieldName((string) $form_state->getValue('tts_audio_field'), 'tts_audio_field', $form_state);

    $bundles = array_values(array_filter($form_state->getValue('queue_bundles') ?? []));
    if (!$bundles) {
      $form_state->setErrorByName('queue_bundles', $this->t('Select at least one content type for Queue TTS pre-generation before queueing existing content.'));
      return;
    }

    $fieldName = (string) $form_state->getValue('tts_audio_field');
    $summary = $this->audioFieldValidator->validateBundles(
      $bundles,
      $fieldName,
      !$this->config('hear_me.settings')->get('overwrite_manual_audio'),
    );
    if ($summary['errors']) {
      $form_state->setErrorByName('existing_content_queue', $this->t('The configured TTS audio field is not compatible with every selected content type: @messages', [
        '@messages' => $this->formatValidationMessages($summary['errors']),
      ]));
    }

    $options = $form_state->getValue('existing_content_queue') ?? [];
    if (!empty($options['include_unpublished']) && empty($options['confirm_unpublished_public_audio'])) {
      $form_state->setErrorByName('existing_content_queue][confirm_unpublished_public_audio', $this->t('Confirm that generated audio for unpublished content may be publicly accessible before queueing unpublished content.'));
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
      ->set('runtime_cache_scheme', $form_state->getValue('runtime_cache_scheme'))
      ->set('cache_inline_ttl', (int) $form_state->getValue('cache_inline_ttl'))
      ->set('cache_page_ttl', (int) $form_state->getValue('cache_page_ttl'))
      ->set('cache_selection_ttl', (int) $form_state->getValue('cache_selection_ttl'))
      ->set('cache_ad_hoc_ttl', (int) $form_state->getValue('cache_ad_hoc_ttl'))
      ->set('cache_max_files', (int) $form_state->getValue('cache_max_files'))
      ->set('cache_max_total_mb', (int) $form_state->getValue('cache_max_total_mb'))
      ->set('rate_limit_window_seconds', (int) $form_state->getValue('rate_limit_window_seconds'))
      ->set('rate_limit_user_requests', (int) $form_state->getValue('rate_limit_user_requests'))
      ->set('rate_limit_ip_requests', (int) $form_state->getValue('rate_limit_ip_requests'))
      ->set('rate_limit_role_requests', (int) $form_state->getValue('rate_limit_role_requests'))
      ->set('daily_user_quota', (int) $form_state->getValue('daily_user_quota'))
      ->set('monthly_user_quota', (int) $form_state->getValue('monthly_user_quota'))
      ->set('max_request_bytes', (int) $form_state->getValue('max_request_bytes'))
      ->set('max_text_length', (int) $form_state->getValue('max_text_length'))
      ->set('tts_audio_field', $form_state->getValue('tts_audio_field'))
      ->set('queue_bundles',   $queueBundles)
      ->set('replace_existing_generated_audio', (bool) $form_state->getValue('replace_existing_generated_audio'))
      ->set('overwrite_manual_audio', (bool) $form_state->getValue('overwrite_manual_audio'))
      ->save();

    if (isset($providers[$providerKey]) && $providers[$providerKey] instanceof TtsProviderConfigurableInterface) {
      $providers[$providerKey]->submitConfigForm($form, $form_state);
    }

    $summary = $this->audioFieldValidator->validateBundles(
      $queueBundles,
      (string) $form_state->getValue('tts_audio_field'),
      !$form_state->getValue('overwrite_manual_audio'),
    );
    foreach ($summary['warnings'] as $warning) {
      $this->messenger()->addWarning($warning);
    }

    parent::submitForm($form, $form_state);
  }

  public function clearRuntimeCacheSubmit(array &$form, FormStateInterface $form_state): void {
    $deleted = $this->cacheManager->clearRuntimeCache();
    $this->messenger()->addStatus($this->formatPlural(
      $deleted,
      'Deleted 1 generated runtime audio cache item.',
      'Deleted @count generated runtime audio cache items.',
    ));
    $form_state->setRebuild(TRUE);
  }

  public function testProviderConnectionSubmit(array &$form, FormStateInterface $form_state): void {
    $result = $this->setupStatus->testProviderConnection();
    if (($result['status'] ?? '') === 'ok') {
      $this->messenger()->addStatus($this->t('Provider connection test passed: @message', ['@message' => $result['message'] ?? '']));
    }
    else {
      $this->messenger()->addError($this->t('Provider connection test failed: @message', ['@message' => $result['message'] ?? '']));
    }

    $form_state->setRebuild(TRUE);
  }

  public function createAudioFieldSubmit(array &$form, FormStateInterface $form_state): void {
    $fieldName = (string) $form_state->getValue('tts_audio_field');
    $bundles = array_values(array_filter($form_state->getValue(['audio_field_setup', 'bundles']) ?? []));

    $this->configFactory->getEditable('hear_me.settings')
      ->set('tts_audio_field', $fieldName)
      ->save();

    $created = 0;
    $skipped = 0;
    $storage = FieldStorageConfig::loadByName('node', $fieldName);
    if (!$storage) {
      $storage = FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'type' => 'entity_reference',
        'settings' => [
          'target_type' => 'media',
        ],
        'cardinality' => 1,
        'translatable' => TRUE,
      ]);
      $storage->save();
    }

    foreach ($bundles as $bundle) {
      if (!$this->entityTypeManager->getStorage('node_type')->load($bundle)) {
        $skipped++;
        continue;
      }

      if (FieldConfig::loadByName('node', $bundle, $fieldName)) {
        $skipped++;
        continue;
      }

      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'node',
        'bundle' => $bundle,
        'label' => 'HearMe audio',
        'description' => 'Generated text-to-speech audio media attached by HearMe.',
        'required' => FALSE,
        'translatable' => TRUE,
        'settings' => [
          'handler' => 'default:media',
          'handler_settings' => [
            'target_bundles' => [
              'hear_me_audio' => 'hear_me_audio',
            ],
            'auto_create' => FALSE,
          ],
        ],
      ])->save();

      $this->configureAudioFieldDisplays($bundle, $fieldName);
      $created++;
    }

    if ($created > 0) {
      $this->messenger()->addStatus($this->formatPlural(
        $created,
        'Created the HearMe audio field on 1 content type.',
        'Created the HearMe audio field on @count content types.',
      ));
    }

    if ($skipped > 0) {
      $this->messenger()->addWarning($this->formatPlural(
        $skipped,
        'Skipped 1 content type because the field already exists or the content type no longer exists.',
        'Skipped @count content types because the field already exists or the content type no longer exists.',
      ));
    }

    $form_state->setRebuild(TRUE);
  }

  public function queueExistingContentSubmit(array &$form, FormStateInterface $form_state): void {
    $fieldName = (string) $form_state->getValue('tts_audio_field');
    $queueBundles = array_values(array_filter($form_state->getValue('queue_bundles') ?? []));
    $options = $form_state->getValue('existing_content_queue') ?? [];

    $this->configFactory->getEditable('hear_me.settings')
      ->set('tts_audio_field', $fieldName)
      ->set('queue_bundles', $queueBundles)
      ->save();

    batch_set([
      'title' => $this->t('Queueing existing content for HearMe audio'),
      'operations' => [
        [
          [static::class, 'queueExistingContentBatchOperation'],
          [[
            'bundles' => $queueBundles,
            'published_only' => empty($options['include_unpublished']),
            'missing_only' => empty($options['requeue_existing']),
            'batch_size' => HearMeExistingContentQueue::DEFAULT_BATCH_SIZE,
          ]],
        ],
      ],
      'finished' => [static::class, 'queueExistingContentBatchFinished'],
      'progress_message' => $this->t('Queueing existing content audio jobs.'),
    ]);
  }

  public static function queueExistingContentBatchOperation(array $options, array &$context): void {
    /** @var \Drupal\hear_me\Service\HearMeExistingContentQueue $queueService */
    $queueService = \Drupal::service('hear_me.existing_content_queue');
    $bundles = $options['bundles'] ?? [];
    $publishedOnly = (bool) ($options['published_only'] ?? TRUE);
    $missingOnly = (bool) ($options['missing_only'] ?? TRUE);
    $batchSize = (int) ($options['batch_size'] ?? HearMeExistingContentQueue::DEFAULT_BATCH_SIZE);

    if (empty($context['sandbox']['initialized'])) {
      $context['sandbox']['initialized'] = TRUE;
      $context['sandbox']['last_nid'] = 0;
      $context['sandbox']['scanned'] = 0;
      $context['sandbox']['total'] = $queueService->countCandidateNodes($bundles, $publishedOnly);
      $context['results'] = $queueService->emptyStats();
    }

    $result = $queueService->queueNextBatch(
      $bundles,
      $publishedOnly,
      $missingOnly,
      (int) $context['sandbox']['last_nid'],
      $batchSize,
    );

    $context['sandbox']['last_nid'] = (int) $result['last_nid'];
    $context['sandbox']['scanned'] += (int) ($result['stats']['scanned'] ?? 0);
    $context['results'] = $queueService->mergeStats($context['results'], $result['stats']);

    $context['message'] = \Drupal::translation()->formatPlural(
      (int) ($context['results']['queued'] ?? 0),
      'Queued 1 existing content audio job.',
      'Queued @count existing content audio jobs.',
    );

    $total = (int) $context['sandbox']['total'];
    if (!$result['processed_nids'] || $total === 0 || $context['sandbox']['scanned'] >= $total) {
      $context['finished'] = 1;
      return;
    }

    $context['finished'] = min(0.99, $context['sandbox']['scanned'] / $total);
  }

  public static function queueExistingContentBatchFinished(bool $success, array $results, array $operations): void {
    if (!$success) {
      \Drupal::messenger()->addError(\Drupal::translation()->translate('Queueing existing content did not complete. Check recent log messages for details.'));
      return;
    }

    \Drupal::messenger()->addStatus(\Drupal::translation()->translate('Existing content queueing finished. Scanned @scanned node(s), queued @queued audio job(s).', [
      '@scanned' => (int) ($results['scanned'] ?? 0),
      '@queued' => (int) ($results['queued'] ?? 0),
    ]));

    $skipped = (int) ($results['skipped_existing_audio'] ?? 0)
      + (int) ($results['skipped_field_missing'] ?? 0)
      + (int) ($results['skipped_source_empty'] ?? 0)
      + (int) ($results['skipped_unsupported_language'] ?? 0)
      + (int) ($results['skipped_not_loaded'] ?? 0);
    if ($skipped > 0) {
      \Drupal::messenger()->addWarning(\Drupal::translation()->translate('Skipped @skipped node(s): @existing already had audio, @field missing or incompatible audio field, @empty had no source text, @unsupported used an unsupported language, @missing could not be loaded.', [
        '@skipped' => $skipped,
        '@existing' => (int) ($results['skipped_existing_audio'] ?? 0),
        '@field' => (int) ($results['skipped_field_missing'] ?? 0),
        '@empty' => (int) ($results['skipped_source_empty'] ?? 0),
        '@unsupported' => (int) ($results['skipped_unsupported_language'] ?? 0),
        '@missing' => (int) ($results['skipped_not_loaded'] ?? 0),
      ]));
    }
  }

  protected function anonymousCanUseTts(): bool {
    $anonymousRole = $this->entityTypeManager
      ->getStorage('user_role')
      ->load(AccountInterface::ANONYMOUS_ROLE);

    return $anonymousRole && $anonymousRole->hasPermission('use tts playback');
  }

  protected function getNodeBundleOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('node_type')->loadMultiple() as $type) {
      $options[$type->id()] = $type->label();
    }

    return $options;
  }

  protected function buildSetupStatusPanel(): array {
    $rows = [];
    foreach ($this->setupStatus->getStatusItems() as $item) {
      $rows[] = [
        'data' => [
          $item['label'],
          [
            'data' => $item['status'],
            'class' => ['hear-me-setup-status__status', 'hear-me-setup-status__status--' . $item['state']],
          ],
          $item['message'],
        ],
        'class' => ['hear-me-setup-status__row', 'hear-me-setup-status__row--' . $item['state']],
      ];
    }

    return [
      '#type' => 'details',
      '#title' => $this->t('Setup status'),
      '#open' => TRUE,
      '#weight' => -100,
      '#description' => $this->t('Checks the pieces HearMe needs for runtime playback and queue-based audio generation. Provider connection is tested only when you click the button below.'),
      'items' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Check'),
          $this->t('Status'),
          $this->t('Details'),
        ],
        '#rows' => $rows,
        '#attributes' => ['class' => ['hear-me-setup-status']],
      ],
      'test_provider_connection' => [
        '#type' => 'submit',
        '#value' => $this->t('Test provider connection'),
        '#submit' => ['::testProviderConnectionSubmit'],
        '#limit_validation_errors' => [],
      ],
    ];
  }

  protected function getAudioFieldSetupOptions(string $fieldName, array $bundleOptions): array {
    $options = [];
    foreach ($bundleOptions as $bundle => $label) {
      $options[$bundle] = FieldConfig::loadByName('node', $bundle, $fieldName)
        ? $this->t('@label (field already exists)', ['@label' => $label])
        : $label;
    }

    return $options;
  }

  protected function validateAudioFieldName(string $fieldName, string $formElementName, FormStateInterface $form_state): void {
    if (!preg_match('/^field_[a-z0-9_]+$/', $fieldName)) {
      $form_state->setErrorByName($formElementName, $this->t('The TTS Audio Field must start with "field_" and contain only lowercase letters, numbers, and underscores.'));
      return;
    }

    if (strlen($fieldName) > FieldStorageConfig::NAME_MAX_LENGTH) {
      $form_state->setErrorByName($formElementName, $this->t('The TTS Audio Field machine name must be @max characters or fewer.', ['@max' => FieldStorageConfig::NAME_MAX_LENGTH]));
    }
  }

  protected function configureAudioFieldDisplays(string $bundle, string $fieldName): void {
    $formDisplay = EntityFormDisplay::load('node.' . $bundle . '.default')
      ?: EntityFormDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $formDisplay->removeComponent($fieldName)->save();

    $viewDisplay = EntityViewDisplay::load('node.' . $bundle . '.default')
      ?: EntityViewDisplay::create([
        'targetEntityType' => 'node',
        'bundle' => $bundle,
        'mode' => 'default',
        'status' => TRUE,
      ]);
    $viewDisplay->setComponent($fieldName, [
      'type' => 'entity_reference_entity_view',
      'label' => 'above',
      'settings' => [
        'view_mode' => 'default',
        'link' => FALSE,
      ],
      'weight' => 90,
    ])->save();
  }

  protected function buildWarning($message): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning']],
      'message' => [
        '#markup' => $message,
      ],
    ];
  }

  protected function formatValidationMessages(array $messages): string {
    return implode(' ', array_map(static fn($message) => (string) $message, $messages));
  }

}
