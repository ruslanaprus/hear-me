<?php

namespace Drupal\hear_me\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Builds and validates queue items for node audio pre-generation.
 */
class HearMeNodeAudioQueue {

  protected \Psr\Log\LoggerInterface $logger;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected QueueFactory $queueFactory,
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

    $this->queueItem($queueItem);
    return TRUE;
  }

  /**
   * Adds a prebuilt node audio item to the queue.
   */
  public function queueItem(array $queueItem): bool {
    $this->queueFactory->get('hear_me_tts')->createItem($queueItem);
    return TRUE;
  }

  /**
   * Builds a queue item for a node enrolled in TTS pre-generation.
   */
  public function buildQueueItem(EntityInterface $entity): ?array {
    if (!$this->isNodeQueuedForAudio($entity)) {
      return NULL;
    }

    $source = $this->buildNodeAudioSource($entity);
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
  public function buildCurrentQueueItem(int $nid): ?array {
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

    return $node instanceof EntityInterface ? $this->buildQueueItem($node) : NULL;
  }

  /**
   * Checks whether the node title, body, or language changed.
   */
  public function hasAudioSourceChanged(EntityInterface $entity): bool {
    if (!$this->isNodeQueuedForAudio($entity)) {
      return FALSE;
    }

    $current = $this->buildNodeAudioSource($entity);
    if ($current === NULL) {
      return FALSE;
    }

    $original = $entity->getOriginal();
    if (!$original instanceof EntityInterface || $original->getEntityTypeId() !== 'node') {
      return TRUE;
    }

    $previous = $this->buildNodeAudioSource($original);
    if ($previous === NULL) {
      return TRUE;
    }

    return !hash_equals($previous['content_hash'], $current['content_hash']);
  }

  /**
   * Builds a stable hash for queued node audio source text and language.
   */
  public function buildContentHash(string $text, string $lang): string {
    return hash('sha256', implode("\0", [
      'v1',
      $this->normalizeText($text),
      strtolower($lang),
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
  protected function buildNodeAudioSource(EntityInterface $entity): ?array {
    if ($entity->getEntityTypeId() !== 'node') {
      return NULL;
    }

    $bodyText = '';
    if ($entity->hasField('body') && !$entity->get('body')->isEmpty()) {
      $parts = [];
      foreach ($entity->get('body') as $item) {
        $parts[] = (string) ($item->value ?? '');
      }
      $bodyText = implode(' ', $parts);
    }

    $text = Html::decodeEntities(strip_tags(trim($entity->label() . ' ' . $bodyText)));
    $text = $this->normalizeText($text);
    if ($text === '') {
      return NULL;
    }

    $lang = $this->resolveNodeLanguage($entity);

    return [
      'text' => $text,
      'lang' => $lang,
      'content_hash' => $this->buildContentHash($text, $lang),
    ];
  }

  /**
   * Resolves the node language used for queued audio generation.
   */
  protected function resolveNodeLanguage(EntityInterface $entity): string {
    $lang = $entity->language()->getId();
    if (!in_array($lang, [
      '',
      LanguageInterface::LANGCODE_NOT_SPECIFIED,
      LanguageInterface::LANGCODE_NOT_APPLICABLE,
      LanguageInterface::LANGCODE_DEFAULT,
    ], TRUE)) {
      return $lang;
    }

    $providerKey = (string) $this->configFactory->get('hear_me.settings')->get('provider');
    $defaultLang = (string) $this->configFactory->get('hear_me.provider.' . $providerKey)->get('default_lang');
    if ($defaultLang === '') {
      throw new \RuntimeException(sprintf(
        'HearMe: the "default_lang" key is missing from hear_me.provider.%s configuration.',
        $providerKey,
      ));
    }

    return $defaultLang;
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
