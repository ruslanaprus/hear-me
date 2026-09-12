<?php

namespace Drupal\hear_me\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;

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

  protected \Psr\Log\LoggerInterface $logger;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected TtsProviderResolver $providerResolver,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
    protected StateInterface $state,
    protected LockBackendInterface $lock,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('hear_me');
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
      return $this->queueFactory->get('hear_me_tts')->createItem($queueItem) !== FALSE;
    }

    $lockName = 'hear_me.queue_hash.' . hash('sha256', $stateKey);
    if (!$this->lock->acquire($lockName, 30.0)) {
      return FALSE;
    }

    try {
      if ($this->state->get($stateKey, FALSE)) {
        return FALSE;
      }

      if ($this->queueFactory->get('hear_me_tts')->createItem($queueItem) === FALSE) {
        return FALSE;
      }
      $this->state->set($stateKey, TRUE);
    }
    finally {
      $this->lock->release($lockName);
    }

    return TRUE;
  }

  /**
   * Clears the pending marker for a processed or discarded queue item.
   */
  public function clearQueuedHash(int $nid, string $contentHash): void {
    $stateKey = $this->buildQueuedHashStateKey($nid, $contentHash);
    if ($stateKey !== NULL) {
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
    if (!$this->isNodeQueuedForAudio($entity)) {
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
      $node = $this->entityTypeManager->getStorage('node')->load($nid);
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
   * Checks whether the configured source text or language changed.
   */
  public function hasAudioSourceChanged(EntityInterface $entity): bool {
    if (!$this->isNodeQueuedForAudio($entity)) {
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
  protected function isNodeQueuedForAudio(EntityInterface $entity): bool {
    if ($entity->getEntityTypeId() !== 'node' || !$entity->id()) {
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
    if ($sourceConfig['title']) {
      $parts[] = $entity->label();
    }

    foreach ($sourceConfig['fields'] as $token) {
      $fieldSource = $this->getSourceFieldText($entity, $token);
      if ($fieldSource !== '') {
        $parts[] = $fieldSource;
      }
    }

    $text = $this->cleanSourceText(implode(' ', $parts));
    $text = $this->normalizeText($text);
    if ($text === '') {
      return NULL;
    }

    $lang = $this->resolveNodeLanguage($entity, $providerId);
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
  protected function getSourceFieldText(EntityInterface $entity, string $token): string {
    $parsed = $this->parseSourceFieldToken($token);
    if ($parsed === NULL || !$this->isSupportedSourceField($entity, $parsed['field'], $parsed['property'])) {
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
