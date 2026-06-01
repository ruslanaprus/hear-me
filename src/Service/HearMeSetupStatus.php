<?php

namespace Drupal\hear_me\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\Entity\FieldConfig;

/**
 * Builds setup readiness checks for the HearMe settings form.
 */
class HearMeSetupStatus {

  use StringTranslationTrait;

  private const PROVIDER_TEST_STATE_KEY = 'hear_me.provider_connection_test';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileSystemInterface $fileSystem,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected QueueWorkerManagerInterface $queueWorkerManager,
    protected StateInterface $state,
    protected DateFormatterInterface $dateFormatter,
    protected TimeInterface $time,
    protected HearMeService $ttsService,
  ) {}

  /**
   * Returns setup status items for display on the settings form.
   */
  public function getStatusItems(): array {
    return [
      $this->getProviderConfiguredStatus(),
      $this->getProviderConnectionStatus(),
      $this->getQueueWorkerStatus(),
      $this->getMediaTypeStatus(),
      $this->getAudioFieldStatus(),
      $this->getPublicDirectoryStatus(),
      $this->getPrivateFilesStatus(),
      $this->getCronStatus(),
      $this->getAnonymousPermissionStatus(),
    ];
  }

  /**
   * Tests the saved active provider connection and stores the latest result.
   */
  public function testProviderConnection(): array {
    $providerKey = $this->getConfiguredProviderKey();
    $providers = $this->ttsService->getProviders();

    if ($providerKey === '' || !isset($providers[$providerKey])) {
      return $this->storeProviderConnectionResult($providerKey, 'error', (string) $this->t('The configured provider is missing or not registered.'));
    }

    $provider = $providers[$providerKey];
    $lang = (string) $this->configFactory->get('hear_me.provider.' . $providerKey)->get('default_lang');
    if ($lang === '') {
      $supported = $provider->getSupportedLanguages();
      $lang = (string) ($supported[0] ?? 'en');
    }

    $result = $provider->synthesize('HearMe setup test.', $lang);
    if ($result && $result->bytes !== '') {
      return $this->storeProviderConnectionResult($providerKey, 'ok', (string) $this->t('Provider returned @type audio for @lang.', [
        '@type' => $result->mimeType,
        '@lang' => $lang,
      ]));
    }

    return $this->storeProviderConnectionResult($providerKey, 'error', (string) $this->t('Provider did not return audio. Check the endpoint, language, and Drupal logs.'));
  }

  protected function getProviderConfiguredStatus(): array {
    $providerKey = $this->getConfiguredProviderKey();
    $providers = $this->ttsService->getProviders();

    if ($providerKey === '') {
      return $this->item('provider_configured', $this->t('Provider configured'), 'error', $this->t('Missing'), $this->t('No active provider is configured.'));
    }

    if (!isset($providers[$providerKey])) {
      return $this->item('provider_configured', $this->t('Provider configured'), 'error', $this->t('Failed'), $this->t('Provider @provider is configured but is not registered as a service.', ['@provider' => $providerKey]));
    }

    return $this->item('provider_configured', $this->t('Provider configured'), 'ok', $this->t('OK'), $this->t('@provider is active.', ['@provider' => $providers[$providerKey]->getLabel()]));
  }

  protected function getProviderConnectionStatus(): array {
    $providerKey = $this->getConfiguredProviderKey();
    $last = $this->state->get(self::PROVIDER_TEST_STATE_KEY, []);
    if (($last['provider'] ?? '') !== $providerKey || ($last['config_hash'] ?? '') !== $this->getProviderConfigHash($providerKey) || empty($last['checked'])) {
      return $this->item('provider_connection', $this->t('Provider connection'), 'warning', $this->t('Not tested'), $this->t('Use the Test provider connection button after saving provider settings.'));
    }

    $checked = $this->dateFormatter->format((int) $last['checked'], 'short');
    $message = $this->t('@message Last checked @time.', [
      '@message' => $last['message'] ?? '',
      '@time' => $checked,
    ]);

    return $this->item(
      'provider_connection',
      $this->t('Provider connection'),
      ($last['status'] ?? '') === 'ok' ? 'ok' : 'error',
      ($last['status'] ?? '') === 'ok' ? $this->t('OK') : $this->t('Failed'),
      $message,
    );
  }

  protected function getQueueWorkerStatus(): array {
    $definitions = $this->queueWorkerManager->getDefinitions();
    if (isset($definitions['hear_me_tts'])) {
      return $this->item('queue_worker', $this->t('Queue worker discovered'), 'ok', $this->t('OK'), $this->t('Queue worker hear_me_tts is registered.'));
    }

    return $this->item('queue_worker', $this->t('Queue worker discovered'), 'error', $this->t('Failed'), $this->t('Queue worker hear_me_tts is not discoverable. Clear caches and verify the plugin path.'));
  }

  protected function getMediaTypeStatus(): array {
    if ($this->entityTypeManager->getStorage('media_type')->load('hear_me_audio')) {
      return $this->item('media_type', $this->t('Media type installed'), 'ok', $this->t('OK'), $this->t('HearMe Audio media type exists.'));
    }

    return $this->item('media_type', $this->t('Media type installed'), 'error', $this->t('Missing'), $this->t('The hear_me_audio media type is missing. Reinstall the module or restore its config.'));
  }

  protected function getAudioFieldStatus(): array {
    $config = $this->configFactory->get('hear_me.settings');
    $fieldName = (string) ($config->get('tts_audio_field') ?? 'field_tts_audio');
    $queueBundles = array_values(array_filter($config->get('queue_bundles') ?? []));

    if (!$queueBundles) {
      return $this->item('audio_field', $this->t('Audio field configured'), 'info', $this->t('Not required'), $this->t('No content types are selected for queue pre-generation.'));
    }

    $missing = [];
    foreach ($queueBundles as $bundle) {
      if (!FieldConfig::loadByName('node', $bundle, $fieldName)) {
        $missing[] = $bundle;
      }
    }

    if (!$missing) {
      return $this->item('audio_field', $this->t('Audio field configured'), 'ok', $this->t('OK'), $this->t('@field exists on all queued content types.', ['@field' => $fieldName]));
    }

    return $this->item('audio_field', $this->t('Audio field configured'), 'error', $this->t('Missing'), $this->t('@field is missing on: @bundles.', [
      '@field' => $fieldName,
      '@bundles' => implode(', ', $missing),
    ]));
  }

  protected function getPublicDirectoryStatus(): array {
    if (!$this->streamWrapperManager->isValidScheme('public')) {
      return $this->item('public_tts', $this->t('public://tts writable'), 'error', $this->t('Failed'), $this->t('The public stream wrapper is not available.'));
    }

    $directory = TtsFileHelperInterface::TTS_URI_BASE;
    $prepared = $this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    if ($prepared) {
      return $this->item('public_tts', $this->t('public://tts writable'), 'ok', $this->t('OK'), $this->t('Generated entity audio can be written to public://tts.'));
    }

    return $this->item('public_tts', $this->t('public://tts writable'), 'error', $this->t('Failed'), $this->t('Drupal could not create or write to public://tts. Check file permissions.'));
  }

  protected function getPrivateFilesStatus(): array {
    if ($this->streamWrapperManager->isValidScheme('private')) {
      return $this->item('private_files', $this->t('Private files configured'), 'ok', $this->t('OK'), $this->t('Private runtime cache storage is available.'));
    }

    return $this->item('private_files', $this->t('Private files configured'), 'warning', $this->t('Not configured'), $this->t('Playback still works, but private runtime cache files cannot persist until file_private_path is configured or public runtime caching is selected.'));
  }

  protected function getCronStatus(): array {
    $lastRun = (int) $this->state->get('system.cron_last', 0);
    if ($lastRun <= 0) {
      return $this->item('cron', $this->t('Cron last run'), 'warning', $this->t('Never'), $this->t('Queue pre-generation requires cron.'));
    }

    $age = $this->time->getRequestTime() - $lastRun;
    $state = $age > 86400 ? 'warning' : 'ok';
    $status = $this->dateFormatter->format($lastRun, 'short');
    $message = $this->t('@age ago. Queue pre-generation depends on regular cron runs.', [
      '@age' => $this->dateFormatter->formatTimeDiffSince($lastRun),
    ]);

    return $this->item('cron', $this->t('Cron last run'), $state, $status, $message);
  }

  protected function getAnonymousPermissionStatus(): array {
    $anonymousRole = $this->entityTypeManager->getStorage('user_role')->load(AccountInterface::ANONYMOUS_ROLE);
    if ($anonymousRole && $anonymousRole->hasPermission('use tts playback')) {
      return $this->item('anonymous_permission', $this->t('Anonymous playback permission'), 'warning', $this->t('Enabled'), $this->t('Anonymous users can trigger synthesis. Keep strict IP limits and avoid public runtime caching unless audio is safe to expose.'));
    }

    return $this->item('anonymous_permission', $this->t('Anonymous playback permission'), 'ok', $this->t('Disabled'), $this->t('Anonymous users cannot trigger synthesis.'));
  }

  protected function getConfiguredProviderKey(): string {
    $providerKey = (string) $this->configFactory->get('hear_me.settings')->get('provider');
    if ($providerKey !== '') {
      return $providerKey;
    }

    $providers = $this->ttsService->getProviders();
    return (string) array_key_first($providers);
  }

  protected function storeProviderConnectionResult(string $providerKey, string $status, string $message): array {
    $result = [
      'provider' => $providerKey,
      'config_hash' => $this->getProviderConfigHash($providerKey),
      'status' => $status,
      'message' => $message,
      'checked' => $this->time->getRequestTime(),
    ];
    $this->state->set(self::PROVIDER_TEST_STATE_KEY, $result);

    return $result;
  }

  protected function getProviderConfigHash(string $providerKey): string {
    if ($providerKey === '') {
      return '';
    }

    return hash('sha256', serialize($this->configFactory->get('hear_me.provider.' . $providerKey)->getRawData()));
  }

  protected function item(string $id, $label, string $state, $status, $message): array {
    return [
      'id' => $id,
      'label' => $label,
      'state' => $state,
      'status' => $status,
      'message' => $message,
    ];
  }

}
