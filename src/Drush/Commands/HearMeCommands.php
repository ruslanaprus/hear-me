<?php

namespace Drupal\hear_me\Drush\Commands;

use Drupal\hear_me\Service\HearMeExistingContentQueue;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for HearMe.
 */
class HearMeCommands extends DrushCommands {

  public function __construct(
    protected HearMeExistingContentQueue $existingContentQueue,
  ) {
    parent::__construct();
  }

  /**
   * Queues existing content for HearMe audio pre-generation.
   *
   * @command hear-me:queue-existing
   * @aliases hear-me-backfill
   *
   * @option bundles Comma-separated content type machine names. Defaults to configured queue bundles.
   * @option include-unpublished Include unpublished nodes. By default only published nodes are scanned.
   * @option requeue-existing Queue nodes even when the configured audio field already has media.
   * @option limit Maximum candidate nodes to scan. Zero means no limit.
   *
   * @usage drush hear-me:queue-existing
   *   Queue published existing content without attached HearMe audio.
   * @usage drush hear-me:queue-existing --bundles=article,page --limit=5000
   *   Queue up to 5000 existing article/page nodes.
   * @usage drush hear-me:queue-existing --include-unpublished --requeue-existing
   *   Include unpublished nodes and nodes that already have attached audio.
   */
  public function queueExisting(array $options = [
    'bundles' => '',
    'include-unpublished' => FALSE,
    'requeue-existing' => FALSE,
    'limit' => 0,
  ]): void {
    $requestedBundles = $this->parseBundles((string) ($options['bundles'] ?? ''));
    $configuredBundles = $this->existingContentQueue->getConfiguredBundles();
    if (!$configuredBundles) {
      throw new \InvalidArgumentException('No content types are configured for HearMe queue pre-generation. Configure queue bundles first.');
    }

    $unconfiguredBundles = $this->existingContentQueue->getUnconfiguredBundles($requestedBundles);
    if ($unconfiguredBundles) {
      $this->io()->warning(sprintf(
        'Ignoring bundle(s) that are not configured for HearMe queue pre-generation: %s',
        implode(', ', $unconfiguredBundles),
      ));
    }

    $bundles = $this->existingContentQueue->filterConfiguredBundles($requestedBundles);
    if (!$bundles) {
      throw new \InvalidArgumentException('No requested content types are configured for HearMe queue pre-generation.');
    }

    $stats = $this->existingContentQueue->queueAll(
      $bundles,
      empty($options['include-unpublished']),
      empty($options['requeue-existing']),
      max(0, (int) ($options['limit'] ?? 0)),
      HearMeExistingContentQueue::DEFAULT_BATCH_SIZE,
    );

    $this->io()->success(sprintf(
      'Queued %d HearMe audio job(s) after scanning %d node(s).',
      $stats['queued'],
      $stats['scanned'],
    ));

    $this->io()->table(['Metric', 'Count'], [
      ['Scanned', $stats['scanned']],
      ['Queued', $stats['queued']],
      ['Skipped: already queued', $stats['skipped_duplicate_queue']],
      ['Skipped: already had audio', $stats['skipped_existing_audio']],
      ['Skipped: missing audio field', $stats['skipped_field_missing']],
      ['Skipped: no source text', $stats['skipped_source_empty']],
      ['Skipped: unsupported language', $stats['skipped_unsupported_language']],
      ['Skipped: could not be loaded', $stats['skipped_not_loaded']],
    ]);
  }

  /**
   * Parses comma-separated bundle option values.
   *
   * @return string[]
   *   Bundle machine names.
   */
  protected function parseBundles(string $bundles): array {
    if ($bundles === '') {
      return [];
    }

    return array_values(array_unique(array_filter(array_map(
      static fn($bundle) => trim($bundle),
      explode(',', $bundles),
    ))));
  }

}
