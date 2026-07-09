<?php

namespace Drupal\hear_me\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Queues existing nodes for HearMe audio pre-generation.
 */
class HearMeExistingContentQueue {

  public const DEFAULT_BATCH_SIZE = 100;

  protected const STAT_KEYS = [
    'scanned',
    'queued',
    'skipped_existing_audio',
    'skipped_field_missing',
    'skipped_source_empty',
    'skipped_unsupported_language',
    'skipped_not_loaded',
  ];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HearMeNodeAudioQueue $nodeAudioQueue,
    protected HearMeService $ttsService,
    protected HearMeAudioFieldValidator $audioFieldValidator,
  ) {}

  /**
   * Returns queue-enabled node bundles from HearMe settings.
   *
   * @return string[]
   *   Bundle machine names.
   */
  public function getConfiguredBundles(): array {
    return array_values(array_filter(array_map(
      static fn($bundle) => trim((string) $bundle),
      $this->configFactory->get('hear_me.settings')->get('queue_bundles') ?? [],
    )));
  }

  /**
   * Returns the requested bundles that are enabled for HearMe queueing.
   *
   * An empty requested bundle list means all configured queue bundles.
   *
   * @param string[] $requestedBundles
   *   Optional bundle filter.
   *
   * @return string[]
   *   Configured bundle machine names.
   */
  public function filterConfiguredBundles(array $requestedBundles = []): array {
    $configured = $this->getConfiguredBundles();
    $requested = $this->normalizeBundles($requestedBundles);
    if (!$requested) {
      return $configured;
    }

    return array_values(array_intersect($configured, $requested));
  }

  /**
   * Returns requested bundles that are not currently queue-enabled.
   *
   * @param string[] $requestedBundles
   *   Bundle machine names.
   *
   * @return string[]
   *   Bundle machine names ignored by backfill.
   */
  public function getUnconfiguredBundles(array $requestedBundles): array {
    return array_values(array_diff(
      $this->normalizeBundles($requestedBundles),
      $this->getConfiguredBundles(),
    ));
  }

  /**
   * Checks whether a bundle has the configured node audio field.
   */
  public function hasCompatibleAudioField(string $bundle, ?string $fieldName = NULL): bool {
    $fieldName ??= $this->getAudioFieldName();
    return $this->audioFieldValidator->isBundleCompatible($bundle, $fieldName);
  }

  /**
   * Returns the subset of bundles with the configured node audio field.
   *
   * @param string[] $bundles
   *   Bundle machine names.
   * @param string|null $fieldName
   *   Optional field machine name.
   *
   * @return string[]
   *   Bundle machine names.
   */
  public function getBundlesWithAudioField(array $bundles, ?string $fieldName = NULL): array {
    return $this->audioFieldValidator->getCompatibleBundles($this->normalizeBundles($bundles), $fieldName ?? $this->getAudioFieldName());
  }

  /**
   * Counts candidate nodes before per-node skip checks are applied.
   *
   * @param string[] $bundles
   *   Optional configured bundle filter.
   */
  public function countCandidateNodes(array $bundles = [], bool $publishedOnly = TRUE): int {
    $bundles = $this->getBundlesWithAudioField($this->filterConfiguredBundles($bundles));
    if (!$bundles) {
      return 0;
    }

    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN')
      ->count();

    if ($publishedOnly) {
      $query->condition('status', 1);
    }

    return (int) $query->execute();
  }

  /**
   * Queues the next batch of existing nodes.
   *
   * @param string[] $bundles
   *   Optional configured bundle filter.
   *
   * @return array{
   *   stats: array<string, int>,
   *   last_nid: int,
   *   processed_nids: int[]
   * }
   *   Batch result.
   */
  public function queueNextBatch(
    array $bundles = [],
    bool $publishedOnly = TRUE,
    bool $missingOnly = TRUE,
    int $lastNid = 0,
    int $batchSize = self::DEFAULT_BATCH_SIZE,
  ): array {
    $stats = $this->emptyStats();
    $bundles = $this->getBundlesWithAudioField($this->filterConfiguredBundles($bundles));
    if (!$bundles) {
      return [
        'stats' => $stats,
        'last_nid' => $lastNid,
        'processed_nids' => [],
      ];
    }

    $batchSize = max(1, min(1000, $batchSize));
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN')
      ->sort('nid', 'ASC')
      ->range(0, $batchSize);

    if ($publishedOnly) {
      $query->condition('status', 1);
    }

    if ($lastNid > 0) {
      $query->condition('nid', $lastNid, '>');
    }

    $nids = array_values(array_map('intval', $query->execute()));
    $stats['scanned'] = count($nids);
    if (!$nids) {
      return [
        'stats' => $stats,
        'last_nid' => $lastNid,
        'processed_nids' => [],
      ];
    }

    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($nids);
    foreach ($nids as $nid) {
      $lastNid = max($lastNid, $nid);
      $node = $nodes[$nid] ?? NULL;
      if (!$node instanceof NodeInterface) {
        $stats['skipped_not_loaded']++;
        continue;
      }

      $stats = $this->mergeStats($stats, $this->queueNode($node, $missingOnly));
    }

    return [
      'stats' => $stats,
      'last_nid' => $lastNid,
      'processed_nids' => $nids,
    ];
  }

  /**
   * Queues matching existing nodes in one process, for Drush and scripts.
   *
   * @param string[] $bundles
   *   Optional configured bundle filter.
   * @param int $limit
   *   Maximum candidate nodes to scan. Zero means no limit.
   *
   * @return array<string, int>
   *   Backfill statistics.
   */
  public function queueAll(
    array $bundles = [],
    bool $publishedOnly = TRUE,
    bool $missingOnly = TRUE,
    int $limit = 0,
    int $batchSize = self::DEFAULT_BATCH_SIZE,
  ): array {
    $stats = $this->emptyStats();
    $lastNid = 0;

    do {
      $remaining = $limit > 0 ? $limit - $stats['scanned'] : $batchSize;
      if ($remaining <= 0) {
        break;
      }

      $result = $this->queueNextBatch(
        $bundles,
        $publishedOnly,
        $missingOnly,
        $lastNid,
        min($batchSize, $remaining),
      );
      $stats = $this->mergeStats($stats, $result['stats']);
      $lastNid = (int) $result['last_nid'];
    } while (!empty($result['processed_nids']));

    return $stats;
  }

  /**
   * Returns an empty statistics array.
   *
   * @return array<string, int>
   *   Statistics keyed by machine name.
   */
  public function emptyStats(): array {
    return array_fill_keys(static::STAT_KEYS, 0);
  }

  /**
   * Merges two statistics arrays.
   *
   * @param array<string, int> $base
   *   Base statistics.
   * @param array<string, int> $addition
   *   Additional statistics.
   *
   * @return array<string, int>
   *   Merged statistics.
   */
  public function mergeStats(array $base, array $addition): array {
    $stats = $this->emptyStats();
    foreach ($stats as $key => $value) {
      $stats[$key] = (int) ($base[$key] ?? 0) + (int) ($addition[$key] ?? 0);
    }

    return $stats;
  }

  /**
   * Queues one loaded node if it passes backfill checks.
   *
   * @return array<string, int>
   *   Statistics for this node.
   */
  protected function queueNode(NodeInterface $node, bool $missingOnly): array {
    $stats = $this->emptyStats();
    $fieldName = $this->getAudioFieldName();
    if (!$this->hasCompatibleAudioField($node->bundle(), $fieldName)) {
      $stats['skipped_field_missing']++;
      return $stats;
    }

    if ($missingOnly && !$node->get($fieldName)->isEmpty()) {
      $stats['skipped_existing_audio']++;
      return $stats;
    }

    $queueItem = $this->nodeAudioQueue->buildQueueItem($node);
    if ($queueItem === NULL) {
      $stats['skipped_source_empty']++;
      return $stats;
    }

    if (!$this->isSupportedLanguage((string) $queueItem['lang'])) {
      $stats['skipped_unsupported_language']++;
      return $stats;
    }

    $this->nodeAudioQueue->queueItem($queueItem);
    $stats['queued']++;
    return $stats;
  }

  /**
   * Checks provider language support.
   */
  protected function isSupportedLanguage(string $lang): bool {
    $supported = array_map('strtolower', $this->ttsService->getSupportedLanguages());
    return in_array(strtolower($lang), $supported, TRUE);
  }

  /**
   * Returns the configured node audio field machine name.
   */
  protected function getAudioFieldName(): string {
    return (string) ($this->configFactory->get('hear_me.settings')->get('tts_audio_field') ?? 'field_tts_audio');
  }

  /**
   * Normalizes bundle machine names.
   *
   * @param string[] $bundles
   *   Bundle machine names.
   *
   * @return string[]
   *   Normalized bundle machine names.
   */
  protected function normalizeBundles(array $bundles): array {
    return array_values(array_unique(array_filter(array_map(
      static fn($bundle) => trim((string) $bundle),
      $bundles,
    ))));
  }

}
