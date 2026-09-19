<?php

namespace Drupal\hear_me\Service;

use Drupal\Component\Utility\Html;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreInterface;
use Drupal\Core\Lock\LockAcquiringException;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\State\StateInterface;
use Drupal\node\NodeInterface;

/**
 * Builds and validates queue items for node audio pre-generation.
 *
 * @internal
 */
class HearMeNodeAudioQueue {

  public const SUPPORTED_SOURCE_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
  ];

  protected const SOURCE_CONFIG_VERSION = 'v1';

  private const QUEUED_MARKER_TTL = 86400;

  private const SYNTHESIS_RESERVATION_TTL = 60;

  public const MAX_SYNTHESIS_ATTEMPTS = 3;

  protected \Psr\Log\LoggerInterface $logger;

  protected KeyValueStoreInterface $stateStore;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected TtsProviderResolver $providerResolver,
    protected HearMeInputValidator $inputValidator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected StateInterface $state,
    KeyValueFactoryInterface $keyValueFactory,
    protected LockBackendInterface $lock,
    protected TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('hear_me');
    $this->stateStore = $keyValueFactory->get('state');
  }

  /**
   * Queues audio generation for an enrolled node.
   */
  public function queueNode(EntityInterface $entity): bool {
    $queueItem = $this->buildQueueItem($entity);
    if ($queueItem === NULL) {
      return FALSE;
    }

    return $this->queueItem($queueItem);
  }

  /**
   * Adds a prebuilt node audio item to the queue.
   */
  public function queueItem(array $queueItem): bool {
    $stateKey = $this->getQueuedHashStateKey($queueItem);
    if ($stateKey === NULL) {
      return FALSE;
    }
    $storedItem = [
      'nid' => (int) ($queueItem['nid'] ?? 0),
      'content_hash' => (string) ($queueItem['content_hash'] ?? ''),
      'token' => bin2hex(random_bytes(16)),
    ];

    $lockName = 'hear_me.queue_hash.' . hash('sha256', $stateKey);
    if (!$this->lock->acquire($lockName, 30.0)) {
      return FALSE;
    }

    try {
      $marker = $this->stateStore->get($stateKey, FALSE);
      if (is_array($marker) && (int) ($marker['queued_at'] ?? 0) > $this->time->getRequestTime() - self::QUEUED_MARKER_TTL) {
        return FALSE;
      }

      $this->state->set($stateKey, [
        'attempt_started_at' => 0,
        'attempt_token' => '',
        'attempts' => 0,
        'queued_at' => $this->time->getRequestTime(),
        'token' => $storedItem['token'],
      ]);
      try {
        if ($this->queueFactory->get('hear_me_tts')->createItem($storedItem) === FALSE) {
          $this->clearReservationIfOwned($stateKey, $storedItem['token']);
          return FALSE;
        }
      }
      catch (\Throwable $e) {
        $this->clearReservationIfOwned($stateKey, $storedItem['token']);
        throw $e;
      }
    }
    finally {
      $this->lock->release($lockName);
    }

    return TRUE;
  }

  /**
   * Clears the pending marker for a processed or discarded queue item.
   */
  public function clearQueuedHash(int $nid, string $contentHash, string $token, ?string $attemptToken = NULL): void {
    $stateKey = $this->buildQueuedHashStateKey($nid, $contentHash);
    if ($stateKey === NULL || $token === '') {
      return;
    }

    $lockName = 'hear_me.queue_hash.' . hash('sha256', $stateKey);
    if (!$this->lock->acquire($lockName, 30.0)) {
      throw new LockAcquiringException('HearMe queue state is busy.');
    }

    try {
      $this->clearReservationIfOwned($stateKey, $token, $attemptToken);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Checks whether a token still owns the active marker for a queued hash.
   */
  public function isCurrentQueueItem(int $nid, string $contentHash, string $token): bool {
    $stateKey = $this->buildQueuedHashStateKey($nid, $contentHash);
    if ($stateKey === NULL || $token === '') {
      return FALSE;
    }

    $marker = $this->stateStore->get($stateKey, []);
    return is_array($marker) && hash_equals((string) ($marker['token'] ?? ''), $token);
  }

  /**
   * Reserves one synthesis attempt for the current queue item.
   *
   * @return string|null
   *   An attempt-specific token, an empty string for a stale or exhausted item,
   *   or NULL when lock contention requires a delayed retry.
   */
  public function reserveSynthesisAttempt(int $nid, string $contentHash, string $token): ?string {
    $stateKey = $this->buildQueuedHashStateKey($nid, $contentHash);
    if ($stateKey === NULL || $token === '') {
      return '';
    }

    $lockName = 'hear_me.queue_hash.' . hash('sha256', $stateKey);
    if (!$this->lock->acquire($lockName, 30.0)) {
      return NULL;
    }

    try {
      $value = $this->stateStore->get($stateKey, []);
      if (!is_array($value) || !hash_equals((string) ($value['token'] ?? ''), $token)) {
        return '';
      }
      $attempts = (int) ($value['attempts'] ?? 0);
      if ($attempts >= self::MAX_SYNTHESIS_ATTEMPTS) {
        $this->state->delete($stateKey);
        return '';
      }
      $now = $this->time->getCurrentTime();
      if ((int) ($value['attempt_started_at'] ?? 0) > $now - self::SYNTHESIS_RESERVATION_TTL) {
        return NULL;
      }
      $attemptToken = bin2hex(random_bytes(16));
      $this->state->set($stateKey, [
        'attempt_started_at' => $now,
        'attempt_token' => $attemptToken,
        'attempts' => $attempts,
        'queued_at' => (int) ($value['queued_at'] ?? $this->time->getRequestTime()),
        'token' => $token,
      ]);
      return $attemptToken;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Records one failed reserved synthesis attempt.
   *
   * @return int|null
   *   The failure count, zero for a stale item, or NULL on lock contention.
   */
  public function recordSynthesisFailure(int $nid, string $contentHash, string $token, string $attemptToken): ?int {
    $stateKey = $this->buildQueuedHashStateKey($nid, $contentHash);
    if ($stateKey === NULL || $token === '') {
      return 0;
    }

    $lockName = 'hear_me.queue_hash.' . hash('sha256', $stateKey);
    if (!$this->lock->acquire($lockName, 30.0)) {
      return NULL;
    }

    try {
      $value = $this->stateStore->get($stateKey, []);
      if (!is_array($value)
        || !hash_equals((string) ($value['token'] ?? ''), $token)
        || !hash_equals((string) ($value['attempt_token'] ?? ''), $attemptToken)) {
        return 0;
      }
      if ((int) ($value['attempt_started_at'] ?? 0) <= 0) {
        return 0;
      }
      $attempts = (int) ($value['attempts'] ?? 0) + 1;
      if ($attempts >= self::MAX_SYNTHESIS_ATTEMPTS) {
        $this->state->delete($stateKey);
        return $attempts;
      }
      $value['attempt_started_at'] = 0;
      $value['attempt_token'] = '';
      $value['attempts'] = $attempts;
      $this->state->set($stateKey, $value);
      return $attempts;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Completes a successful synthesis reservation for the current item.
   */
  public function completeSynthesisAttempt(int $nid, string $contentHash, string $token, string $attemptToken): bool {
    $stateKey = $this->buildQueuedHashStateKey($nid, $contentHash);
    if ($stateKey === NULL || $token === '') {
      return FALSE;
    }

    $lockName = 'hear_me.queue_hash.' . hash('sha256', $stateKey);
    if (!$this->lock->acquire($lockName, 30.0)) {
      throw new LockAcquiringException('HearMe synthesis attempt state is busy.');
    }

    try {
      $value = $this->stateStore->get($stateKey, []);
      if (!is_array($value)
        || !hash_equals((string) ($value['token'] ?? ''), $token)
        || !hash_equals((string) ($value['attempt_token'] ?? ''), $attemptToken)) {
        return FALSE;
      }
      $value['attempt_started_at'] = 0;
      $value['attempt_token'] = '';
      $this->state->set($stateKey, $value);
      return TRUE;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Refreshes a reservation immediately before its persistent side effect.
   *
   * @return bool|null
   *   TRUE when the attempt still owns the marker, FALSE when stale, or NULL
   *   when lock contention requires a delayed retry.
   */
  public function refreshSynthesisAttempt(int $nid, string $contentHash, string $token, string $attemptToken): ?bool {
    $stateKey = $this->buildQueuedHashStateKey($nid, $contentHash);
    if ($stateKey === NULL || $token === '' || $attemptToken === '') {
      return FALSE;
    }

    $lockName = 'hear_me.queue_hash.' . hash('sha256', $stateKey);
    if (!$this->lock->acquire($lockName, 30.0)) {
      return NULL;
    }

    try {
      $value = $this->stateStore->get($stateKey, []);
      if (!is_array($value)
        || !hash_equals((string) ($value['token'] ?? ''), $token)
        || !hash_equals((string) ($value['attempt_token'] ?? ''), $attemptToken)) {
        return FALSE;
      }
      $value['attempt_started_at'] = $this->time->getCurrentTime();
      $this->state->set($stateKey, $value);
      return TRUE;
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Removes a queue reservation only while its token still owns the marker.
   */
  private function clearReservationIfOwned(string $stateKey, string $token, ?string $attemptToken = NULL): void {
    $marker = $this->stateStore->get($stateKey, []);
    if (is_array($marker)
      && hash_equals((string) ($marker['token'] ?? ''), $token)
      && ($attemptToken === NULL || hash_equals((string) ($marker['attempt_token'] ?? ''), $attemptToken))) {
      $this->state->delete($stateKey);
    }
  }

  /**
   * Builds the state key for a queue item, if it supports de-duplication.
   */
  protected function getQueuedHashStateKey(array $queueItem): ?string {
    return $this->buildQueuedHashStateKey(
      (int) ($queueItem['nid'] ?? 0),
      (string) ($queueItem['content_hash'] ?? ''),
    );
  }

  /**
   * Builds the state key for a node/hash pending queue marker.
   */
  protected function buildQueuedHashStateKey(int $nid, string $contentHash): ?string {
    if ($nid <= 0 || $contentHash === '') {
      return NULL;
    }

    return 'hear_me.queued_hash.' . $nid . '.' . $contentHash;
  }

  /**
   * Builds a queue item for a node enrolled in TTS pre-generation.
   */
  public function buildQueueItem(EntityInterface $entity, ?string $providerId = NULL): ?array {
    if (!$this->isEligibleForPersistentAudio($entity)) {
      return NULL;
    }

    $source = $this->buildNodeAudioSource($entity, $providerId);
    if ($source === NULL) {
      return NULL;
    }

    return [
      'nid' => (int) $entity->id(),
    ] + $source;
  }

  /**
   * Builds a queue item from the current stored node state.
   */
  public function buildCurrentQueueItem(int $nid, ?string $providerId = NULL): ?array {
    try {
      $nodeStorage = $this->entityTypeManager->getStorage('node');
      $nodeStorage->resetCache([$nid]);
      $this->entityTypeManager->getAccessControlHandler('node')->resetCache();
      $node = $nodeStorage->load($nid);
    }
    catch (\Exception $e) {
      $this->logger->warning('HearMe: could not load node @nid for queued audio generation: @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }

    return $node instanceof EntityInterface ? $this->buildQueueItem($node, $providerId) : NULL;
  }

  /**
   * Resolves a node's synthesis language against one provider's capabilities.
   */
  public function resolveSupportedNodeLanguage(EntityInterface $entity, string $providerId): ?string {
    return $this->inputValidator->resolveSupportedLanguage(
      $this->resolveNodeLanguage($entity, $providerId),
      $providerId,
    );
  }

  /**
   * Checks whether the configured source text or language changed.
   */
  public function hasAudioSourceChanged(EntityInterface $entity): bool {
    if (!$entity instanceof NodeInterface || !$entity->id()) {
      return FALSE;
    }

    $original = $entity->getOriginal();
    $hasOriginal = $original instanceof EntityInterface && $original->getEntityTypeId() === 'node';
    $providerId = NULL;
    if ($this->usesProviderDefaultLanguage($entity) || ($hasOriginal && $this->usesProviderDefaultLanguage($original))) {
      $providerId = $this->providerResolver->getActiveProviderId();
    }

    $current = $this->buildNodeAudioSource($entity, $providerId);
    if ($current === NULL) {
      return FALSE;
    }

    if (!$hasOriginal) {
      return TRUE;
    }

    $previous = $this->buildNodeAudioSource($original, $providerId);
    if ($previous === NULL) {
      return TRUE;
    }

    return !hash_equals($previous['content_hash'], $current['content_hash']);
  }

  /**
   * Builds a stable hash for queued node audio source text and language.
   */
  public function buildContentHash(string $text, string $lang, string $sourceConfigHash = ''): string {
    return hash('sha256', implode("\0", [
      'v2',
      $this->normalizeText($text),
      strtolower($lang),
      $sourceConfigHash,
    ]));
  }

  /**
   * Checks whether this node bundle is enrolled in queue pre-generation.
   */
  public function isEligibleForPersistentAudio(EntityInterface $entity): bool {
    if (!$entity instanceof NodeInterface || !$entity->id() || !$entity->isPublished()) {
      return FALSE;
    }

    if (!$entity->access('view', new AnonymousUserSession())) {
      return FALSE;
    }

    $queueBundles = array_values(array_filter($this->configFactory->get('hear_me.settings')->get('queue_bundles') ?? []));
    return in_array($entity->bundle(), $queueBundles, TRUE);
  }

  /**
   * Builds normalized node audio source data independent of queue enrollment.
   */
  protected function buildNodeAudioSource(EntityInterface $entity, ?string $providerId = NULL): ?array {
    if ($entity->getEntityTypeId() !== 'node') {
      return NULL;
    }

    $sourceConfig = $this->getBundleSourceConfig($entity);
    $parts = [];
    $anonymousUser = new AnonymousUserSession();
    if ($sourceConfig['title'] && $entity->get('title')->access('view', $anonymousUser)) {
      $parts[] = $entity->label();
    }

    foreach ($sourceConfig['fields'] as $token) {
      $fieldSource = $this->getSourceFieldText($entity, $token, $anonymousUser);
      if ($fieldSource !== '') {
        $parts[] = $fieldSource;
      }
    }

    $text = $this->cleanSourceText(implode(' ', $parts));
    $text = $this->normalizeText($text);
    if ($text === '') {
      return NULL;
    }
    if (mb_strlen($text) > $this->inputValidator->getMaxTextLength()) {
      $this->logger->notice('HearMe: skipped queueing node @nid because its TTS source exceeds the configured text length limit.', [
        '@nid' => $entity->id(),
      ]);
      return NULL;
    }

    try {
      $providerId ??= $this->providerResolver->getActiveProviderId();
      $lang = $this->resolveSupportedNodeLanguage($entity, $providerId);
    }
    catch (\RuntimeException) {
      $this->logger->notice('HearMe: skipped queueing node @nid because no valid active provider is configured.', [
        '@nid' => $entity->id(),
      ]);
      return NULL;
    }
    if ($lang === NULL) {
      $this->logger->notice('HearMe: skipped queueing node @nid because its language is not supported by the active provider.', [
        '@nid' => $entity->id(),
      ]);
      return NULL;
    }
    $sourceConfigHash = $this->buildSourceConfigHash($entity->bundle(), $sourceConfig);

    return [
      'text' => $text,
      'lang' => $lang,
      'content_hash' => $this->buildContentHash($text, $lang, $sourceConfigHash),
      'source_config_hash' => $sourceConfigHash,
    ];
  }

  /**
   * Returns normalized source configuration for a node bundle.
   */
  protected function getBundleSourceConfig(EntityInterface $entity): array {
    $sourceFields = $this->configFactory->get('hear_me.settings')->get('queue_source_fields');
    $bundle = $entity->bundle();
    if (!is_array($sourceFields) || !array_key_exists($bundle, $sourceFields) || !is_array($sourceFields[$bundle])) {
      return [
        'title' => TRUE,
        'fields' => $entity->hasField('body') ? ['body:value'] : [],
      ];
    }

    return $this->normalizeSourceConfig($sourceFields[$bundle]);
  }

  /**
   * Normalizes stored source configuration to supported keys.
   */
  protected function normalizeSourceConfig(array $sourceConfig): array {
    $fields = [];
    foreach (($sourceConfig['fields'] ?? []) as $token) {
      $parsed = $this->parseSourceFieldToken((string) $token);
      if ($parsed !== NULL) {
        $fields[] = $parsed['field'] . ':' . $parsed['property'];
      }
    }

    return [
      'title' => !empty($sourceConfig['title']),
      'fields' => array_values(array_unique($fields)),
    ];
  }

  /**
   * Extracts text from a configured field token.
   */
  protected function getSourceFieldText(EntityInterface $entity, string $token, AnonymousUserSession $anonymousUser): string {
    $parsed = $this->parseSourceFieldToken($token);
    if ($parsed === NULL || !$this->isSupportedSourceField($entity, $parsed['field'], $parsed['property'])) {
      return '';
    }

    if (!$entity->get($parsed['field'])->access('view', $anonymousUser)) {
      return '';
    }

    $parts = [];
    foreach ($entity->get($parsed['field']) as $item) {
      $parts[] = (string) ($item->{$parsed['property']} ?? '');
    }

    return implode(' ', $parts);
  }

  /**
   * Checks whether a source field/property is currently supported.
   */
  protected function isSupportedSourceField(EntityInterface $entity, string $fieldName, string $property): bool {
    if (!$entity->hasField($fieldName)) {
      return FALSE;
    }

    $type = $entity->getFieldDefinition($fieldName)->getType();
    if (!in_array($type, self::SUPPORTED_SOURCE_FIELD_TYPES, TRUE)) {
      return FALSE;
    }

    return $property === 'value' || ($property === 'summary' && $type === 'text_with_summary');
  }

  /**
   * Parses a source field token such as body:value or body:summary.
   */
  protected function parseSourceFieldToken(string $token): ?array {
    $token = trim($token);
    if ($token === '') {
      return NULL;
    }

    [$fieldName, $property] = array_pad(explode(':', $token, 2), 2, 'value');
    $fieldName = trim($fieldName);
    $property = trim($property);
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $fieldName) || !in_array($property, ['value', 'summary'], TRUE)) {
      return NULL;
    }

    return [
      'field' => $fieldName,
      'property' => $property,
    ];
  }

  /**
   * Builds the source-configuration hash included in queue content hashes.
   */
  protected function buildSourceConfigHash(string $bundle, array $sourceConfig): string {
    return hash('sha256', serialize([
      'version' => self::SOURCE_CONFIG_VERSION,
      'bundle' => $bundle,
      'title' => (bool) $sourceConfig['title'],
      'fields' => array_values($sourceConfig['fields']),
    ]));
  }

  /**
   * Converts field HTML/formatted text to queue-safe plain text.
   */
  protected function cleanSourceText(string $text): string {
    return Html::decodeEntities(strip_tags(trim($text)));
  }

  /**
   * Resolves the node language used for queued audio generation.
   */
  protected function resolveNodeLanguage(EntityInterface $entity, ?string $providerId = NULL): string {
    $lang = $entity->language()->getId();
    if (!$this->usesProviderDefaultLanguage($entity)) {
      return $lang;
    }

    $providerId ??= $this->providerResolver->getActiveProviderId();
    return $this->providerResolver->getDefaultLanguage($providerId);
  }

  /**
   * Checks whether an entity needs the active provider's default language.
   */
  protected function usesProviderDefaultLanguage(EntityInterface $entity): bool {
    return in_array($entity->language()->getId(), [
      '',
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
      LanguageInterface::LANGCODE_NOT_APPLICABLE,
      LanguageInterface::LANGCODE_DEFAULT,
    ], TRUE);
  }

  /**
   * Normalizes source text for stable queue hashing and synthesis input.
   */
  protected function normalizeText(string $text): string {
    $text = str_replace("\xc2\xa0", ' ', $text);
    $text = preg_replace('/[ \t\r\n]+/u', ' ', $text) ?? $text;
    return trim($text);
  }

}
